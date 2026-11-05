<?php

namespace App\Db;

/**
 * A SQL dump of a database (tables, views, their rows) into a .sql file, done between the frames on a connection of its
 * own (the window's connection stays free) in one consistent snapshot. Rows are read by pages and written as they come.
 *
 *   $dump = new Dumper($config, 'shop', '/path/shop.sql', ['tables' => ['customers'], 'data' => true]);
 *   $dump->start(); ... each frame: $dump->poll(); $dump->done, $dump->progress, $dump->message, $dump->error
 */
final class Dumper
{
    public const DEFAULTS = ['tables' => null, 'structure' => true, 'data' => true, 'drop' => true, 'createDatabase' => false,
        'rowsPerInsert' => 100, 'pageSize' => 2000];

    public bool $done = false;
    public ?string $error = null;
    /** 0..1 */
    public float $progress = 0;
    public string $message = '';
    public int $rowsWritten = 0;
    public int $tablesWritten = 0;

    private MysqlConnection $db;
    /** @var resource|null */
    private $file = null;
    /** @var array<string, mixed> */
    private array $options;
    /** @var list<array{0: string, 1: string}> name, type (BASE TABLE / VIEW) */
    private array $objects = [];
    private int $at = 0;

    /**
     * @param array<string, mixed> $config the connection (MysqlConnection's)
     * @param array<string, mixed> $options DEFAULTS
     */
    public function __construct(array $config, public readonly string $database, public readonly string $path, array $options = [])
    {
        $this->options = $options + self::DEFAULTS;
        // the dump's database is the current one: SHOW CREATE VIEW then names its tables without it (portable)
        $this->db = new MysqlConnection(['database' => $database] + $config);
    }

    public function start(): void
    {
        $dir = dirname($this->path);
        if (!is_dir($dir) && !@mkdir($dir, 0777, true)) {
            $this->fail("Can't create the folder $dir");
            return;
        }
        $file = @fopen($this->path, 'wb');
        if ($file === false) {
            $this->fail("Can't write $this->path");
            return;
        }
        $this->file = $file;
        $this->message = 'Connecting…';
        $this->db->connect()->then(fn () => $this->list(), fn (\Throwable $e) => $this->fail($e->getMessage()));
    }

    private function list(): void
    {
        $this->db->query('SET SESSION TRANSACTION ISOLATION LEVEL REPEATABLE READ; START TRANSACTION /*!40100 WITH CONSISTENT SNAPSHOT */')
            ->then(null, fn (\Throwable $e) => $this->fail($e->getMessage()));
        $this->db->first('SHOW FULL TABLES FROM ' . Sql::ident($this->database))->then(function (ResultSet $r): void {
            $wanted = $this->options['tables'];
            foreach ($r->rows as $row) {
                if ($wanted === null || in_array($row[0], (array)$wanted, true)) {
                    $this->objects[] = [(string)$row[0], (string)$row[1]];
                }
            }
            // tables first, views after (a view may read any table)
            usort($this->objects, fn (array $a, array $b) => [$a[1] === 'VIEW', $a[0]] <=> [$b[1] === 'VIEW', $b[0]]);
            $this->header();
        }, fn (\Throwable $e) => $this->fail($e->getMessage()));
    }

    public function poll(): void
    {
        $this->db->poll();
    }

    public function cancel(): void
    {
        if (!$this->done) {
            $this->fail('Cancelled');
        }
    }

    private function header(): void
    {
        $this->write("-- jumphpsql SQL dump\n-- Server: " . $this->db->serverVersion . "\n-- Database: " . $this->database
            . "\n-- Date: " . date('Y-m-d H:i:s') . "\n\n"
            . "/*!40101 SET NAMES utf8mb4 */;\nSET FOREIGN_KEY_CHECKS = 0;\nSET SQL_MODE = 'NO_AUTO_VALUE_ON_ZERO';\nSET UNIQUE_CHECKS = 0;\n\n");
        if ($this->options['createDatabase']) {
            $this->db->first('SHOW CREATE DATABASE ' . Sql::ident($this->database))->then(function (ResultSet $r): void {
                $create = (string)($r->rows[0][1] ?? 'CREATE DATABASE ' . Sql::ident($this->database));
                $this->write(preg_replace('/^CREATE DATABASE /i', 'CREATE DATABASE /*!32312 IF NOT EXISTS*/ ', $create) . ";\nUSE "
                    . Sql::ident($this->database) . ";\n\n");
                $this->next();
            }, fn (\Throwable $e) => $this->fail($e->getMessage()));
            return;
        }
        $this->next();
    }

    /** The next table or view. */
    private function next(): void
    {
        if ($this->done) {
            return;
        }
        if ($this->at >= count($this->objects)) {
            $this->finish();
            return;
        }
        [$name, $type] = $this->objects[$this->at];
        $this->progress = $this->at / max(1, count($this->objects));
        $this->message = $name;
        $qualified = Sql::table($this->database, $name);
        if ($type === 'VIEW') {
            if (!$this->options['structure']) {
                $this->advance();
                return;
            }
            $this->db->first('SHOW CREATE VIEW ' . $qualified)->then(function (ResultSet $r) use ($name): void {
                $this->write("--\n-- View " . Sql::ident($name) . "\n--\n\n" . ($this->options['drop'] ? 'DROP VIEW IF EXISTS ' . Sql::ident($name) . ";\n" : '')
                    . preg_replace('/ DEFINER=`(?:[^`]|``)*`@`(?:[^`]|``)*`/', '', (string)$r->rows[0][1]) . ";\n\n");
                $this->advance();
            }, fn (\Throwable $e) => $this->fail($e->getMessage()));
            return;
        }
        if ($this->options['structure']) {
            $this->db->first('SHOW CREATE TABLE ' . $qualified)->then(function (ResultSet $r) use ($name): void {
                $this->write("--\n-- Table " . Sql::ident($name) . "\n--\n\n" . ($this->options['drop'] ? 'DROP TABLE IF EXISTS ' . Sql::ident($name) . ";\n" : '')
                    . $r->rows[0][1] . ";\n\n");
                $this->options['data'] ? $this->rows($name, 0) : $this->advance();
            }, fn (\Throwable $e) => $this->fail($e->getMessage()));
        } else {
            $this->rows($name, 0);
        }
    }

    private function rows(string $name, int $offset): void
    {
        $page = (int)$this->options['pageSize'];
        $this->db->first('SELECT * FROM ' . Sql::table($this->database, $name) . " LIMIT $page OFFSET $offset")->then(function (ResultSet $r) use ($name, $offset, $page): void {
            if ($this->done) {
                return;
            }
            if ($r->rows !== []) {
                if ($offset === 0) {
                    $this->write("--\n-- Rows of " . Sql::ident($name) . "\n--\n\nLOCK TABLES " . Sql::ident($name) . " WRITE;\n");
                }
                $columns = '(' . implode(', ', array_map(fn (string $c) => Sql::ident($c), $r->names())) . ')';
                $numeric = [];
                $binary = [];
                foreach ($r->columns as $i => $c) {
                    $numeric[$i] = $r->isNumeric($i);
                    $binary[$i] = $r->isBinary($i);
                }
                foreach (array_chunk($r->rows, max(1, (int)$this->options['rowsPerInsert'])) as $chunk) {
                    $values = [];
                    foreach ($chunk as $row) {
                        $cells = [];
                        foreach ($row as $i => $value) {
                            $cells[] = Sql::literal($value, $numeric[$i], $binary[$i]);
                        }
                        $values[] = '(' . implode(',', $cells) . ')';
                    }
                    $this->write('INSERT INTO ' . Sql::ident($name) . " $columns VALUES\n" . implode(",\n", $values) . ";\n");
                }
                $this->rowsWritten += count($r->rows);
                $this->message = $name . ' (' . number_format($this->rowsWritten) . ' rows)';
            }
            if (count($r->rows) === $page) {
                $this->rows($name, $offset + $page);
                return;
            }
            if ($offset > 0 || $r->rows !== []) {
                $this->write("UNLOCK TABLES;\n\n");
            }
            $this->advance();
        }, fn (\Throwable $e) => $this->fail($e->getMessage()));
    }

    private function advance(): void
    {
        $this->at++;
        $this->tablesWritten++;
        $this->next();
    }

    private function finish(): void
    {
        $this->write("SET FOREIGN_KEY_CHECKS = 1;\nSET UNIQUE_CHECKS = 1;\n\n-- Dump completed " . date('Y-m-d H:i:s') . "\n");
        if ($this->file !== null) {
            fclose($this->file);
            $this->file = null;
        }
        $this->db->query('COMMIT');
        $this->db->close();
        $this->progress = 1;
        $this->done = true;
        $this->message = sprintf('%d object(s), %s rows: %s (%s)', $this->tablesWritten, number_format($this->rowsWritten), $this->path,
            Sql::bytes((int)@filesize($this->path)));
    }

    private function write(string $text): void
    {
        if ($this->file !== null && @fwrite($this->file, $text) === false) {
            $this->fail("Can't write $this->path (disk full?)");
        }
    }

    private function fail(string $why): void
    {
        if ($this->done) {
            return;
        }
        $this->error = $why;
        $this->message = $why;
        $this->done = true;
        if ($this->file !== null) {
            fclose($this->file);
            $this->file = null;
        }
        $this->db->close();
    }
}
