<?php

namespace App\Db;

/**
 * What one statement gave: rows (a SELECT, SHOW...) or a count of changed rows (INSERT, UPDATE...). Values are the text
 * the server sent (null = NULL); binary columns keep their raw bytes.
 */
final class ResultSet
{
    /** MySQL column types */
    public const NUMERIC = [0, 1, 2, 3, 4, 5, 8, 9, 13, 16, 246];
    public const BLOBS = [249, 250, 251, 252, 253, 254];

    /** @var list<array{name: string, table: string, orgTable: string, db: string, orgName: string, type: int, flags: int, charset: int, length: int}> */
    public array $columns = [];
    /** @var list<list<?string>> */
    public array $rows = [];
    public int $affectedRows = 0;
    public int|float $insertId = 0;
    public int $warnings = 0;
    public string $info = '';
    /** rows the server sent beyond the limit asked (not kept) */
    public bool $truncated = false;
    public int $totalRows = 0;
    /** seconds the statement took */
    public float $time = 0;
    public string $sql = '';

    public function hasRows(): bool
    {
        return $this->columns !== [];
    }

    /** @return list<string> */
    public function names(): array
    {
        return array_map(fn (array $c) => $c['name'], $this->columns);
    }

    public function isNumeric(int $column): bool
    {
        return in_array($this->columns[$column]['type'] ?? -1, self::NUMERIC, true);
    }

    public function isBinary(int $column): bool
    {
        $c = $this->columns[$column] ?? null;
        return $c !== null && $c['charset'] === 63 && in_array($c['type'], self::BLOBS, true);
    }

    /** @return list<array<string, ?string>> the rows keyed by column name */
    public function assoc(): array
    {
        $names = $this->names();
        return array_map(fn (array $row) => array_combine($names, $row), $this->rows);
    }

    /** The first column of each row. @return list<?string> */
    public function column(int $index = 0): array
    {
        return array_map(fn (array $row) => $row[$index] ?? null, $this->rows);
    }

    public function summary(): string
    {
        $time = sprintf('%.3f s', $this->time);
        if ($this->hasRows()) {
            return $this->totalRows . ' row' . ($this->totalRows === 1 ? '' : 's') . ($this->truncated ? ' (' . count($this->rows) . ' shown)' : '') . ' in ' . $time;
        }
        return $this->affectedRows . ' row' . ($this->affectedRows === 1 ? '' : 's') . ' affected in ' . $time
            . ($this->insertId ? ', insert id ' . $this->insertId : '') . ($this->warnings ? ', ' . $this->warnings . ' warning(s)' : '');
    }
}
