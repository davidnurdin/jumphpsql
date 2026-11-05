<?php

namespace App\Db;

/** Quoting and splitting SQL. */
final class Sql
{
    /** `name` (a database, table or column). */
    public static function ident(string $name): string
    {
        return '`' . str_replace('`', '``', $name) . '`';
    }

    /** `db`.`table` */
    public static function table(string $database, string $table): string
    {
        return self::ident($database) . '.' . self::ident($table);
    }

    /** A value as SQL: NULL, a quoted string, or 0x... for binary data. */
    public static function value(?string $value, bool $binary = false): string
    {
        if ($value === null) {
            return 'NULL';
        }
        if ($binary || !mb_check_encoding($value, 'UTF-8')) {
            return $value === '' ? "''" : '0x' . bin2hex($value);
        }
        return "'" . strtr($value, ["\\" => "\\\\", "\0" => "\\0", "\n" => "\\n", "\r" => "\\r", "'" => "\\'", "\x1a" => "\\Z"]) . "'";
    }

    /** A number stays bare in INSERTs of a dump (smaller), when it is one. */
    public static function literal(?string $value, bool $numeric, bool $binary = false): string
    {
        if ($value !== null && $numeric && preg_match('/^-?\d+(\.\d+)?(e[+-]?\d+)?$/i', $value)) {
            return $value;
        }
        return self::value($value, $binary);
    }

    /**
     * The statements of a script: split on ';' outside quotes and comments; `DELIMITER $$` lines change the separator
     * (procedures, triggers). Empty statements are dropped.
     *
     * @return list<string>
     */
    public static function split(string $script): array
    {
        $out = [];
        $delimiter = ';';
        $current = '';
        $len = strlen($script);
        $i = 0;
        $lineStart = true;
        while ($i < $len) {
            if ($lineStart && preg_match('/\G[ \t]*DELIMITER[ \t]+(\S+)[ \t]*(\r?\n|$)/Ai', $script, $m, 0, $i)) {
                if (trim($current) !== '') {
                    $out[] = trim($current);
                }
                $current = '';
                $delimiter = $m[1];
                $i += strlen($m[0]);
                continue;
            }
            $c = $script[$i];
            $lineStart = false;
            if ($c === "'" || $c === '"' || $c === '`') {
                $j = $i + 1;
                while ($j < $len) {
                    if ($script[$j] === '\\' && $c !== '`') {
                        $j += 2;
                        continue;
                    }
                    if ($script[$j] === $c) {
                        if (($script[$j + 1] ?? '') === $c) {
                            $j += 2;
                            continue;
                        }
                        break;
                    }
                    $j++;
                }
                $current .= substr($script, $i, $j - $i + 1);
                $i = $j + 1;
                continue;
            }
            if ($c === '#' || ($c === '-' && substr($script, $i, 2) === '--' && in_array($script[$i + 2] ?? ' ', [' ', "\t", "\n", "\r"], true))) {
                $end = strpos($script, "\n", $i);
                $end = $end === false ? $len : $end;
                $current .= substr($script, $i, $end - $i);
                $i = $end;
                continue;
            }
            if ($c === '/' && ($script[$i + 1] ?? '') === '*') {
                $end = strpos($script, '*/', $i + 2);
                $end = $end === false ? $len : $end + 2;
                $current .= substr($script, $i, $end - $i);
                $i = $end;
                continue;
            }
            if (substr_compare($script, $delimiter, $i, strlen($delimiter)) === 0) {
                if (trim($current) !== '' && !self::onlyComments($current)) {
                    $out[] = trim($current);
                }
                $current = '';
                $i += strlen($delimiter);
                continue;
            }
            if ($c === "\n") {
                $lineStart = true;
            }
            $current .= $c;
            $i++;
        }
        if (trim($current) !== '' && !self::onlyComments($current)) {
            $out[] = trim($current);
        }
        return $out;
    }

    private static function onlyComments(string $sql): bool
    {
        $stripped = preg_replace(['~/\*(?!!).*?\*/~s', '/^\s*(#|--\s).*$/m'], '', $sql);
        return trim((string)$stripped) === '';
    }

    /** The statement under a cursor (offset in characters) of a script: what Ctrl+Enter runs. */
    public static function statementAt(string $script, int $cursor): string
    {
        $statements = [];
        $offset = 0;
        // positions: split keeps no offsets, so find each statement again in order
        foreach (self::split($script) as $statement) {
            $at = mb_strpos($script, $statement, $offset);
            if ($at === false) {
                continue;
            }
            $statements[] = [$at, $at + mb_strlen($statement), $statement];
            $offset = $at + mb_strlen($statement);
        }
        $best = '';
        foreach ($statements as [$from, $to, $statement]) {
            if ($cursor >= $from) {
                $best = $statement;
            }
            if ($cursor <= $to) {
                break;
            }
        }
        return $best !== '' ? $best : ($statements[0][2] ?? '');
    }

    /** Does a statement give rows (a SELECT...) rather than change things? */
    public static function isRead(string $sql): bool
    {
        $sql = ltrim((string)preg_replace(['~^\s*/\*.*?\*/~s', '/^\s*(#|--\s)[^\n]*\n/'], '', $sql));
        return (bool)preg_match('/^\(?\s*(SELECT|SHOW|DESCRIBE|DESC|EXPLAIN|WITH|VALUES|TABLE|HELP|CALL)\b/i', $sql);
    }

    /** A size in bytes for people: 1.2 MB. */
    public static function bytes(int|float|string|null $bytes): string
    {
        if ($bytes === null || $bytes === '') {
            return '';
        }
        $bytes = (float)$bytes;
        foreach (['B', 'KB', 'MB', 'GB', 'TB'] as $unit) {
            if ($bytes < 1024 || $unit === 'TB') {
                return ($unit === 'B' ? (string)(int)$bytes : number_format($bytes, $bytes < 10 ? 1 : 0)) . ' ' . $unit;
            }
            $bytes /= 1024;
        }
        return '';
    }
}
