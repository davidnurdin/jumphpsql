<?php

namespace App\Db;

/**
 * Runs a .sql file (a dump, a script) into a database, statement by statement, between the frames, on a connection of its
 * own. `continueOnError`: an error is counted and the next statement runs.
 */
final class Importer
{
    public bool $done = false;
    public ?string $error = null;
    public float $progress = 0;
    public string $message = '';
    public int $executed = 0;
    /** @var list<string> */
    public array $errors = [];

    private MysqlConnection $db;
    /** @var list<string> */
    private array $statements = [];
    private int $at = 0;

    /** @param array<string, mixed> $config the connection (MysqlConnection's) */
    public function __construct(array $config, public readonly string $database, public readonly string $path, private bool $continueOnError = false)
    {
        $this->db = new MysqlConnection(['database' => $database] + $config);
    }

    public function start(): void
    {
        $sql = is_file($this->path) ? @file_get_contents($this->path) : false;
        if ($sql === false) {
            $this->fail("Can't read $this->path");
            return;
        }
        $this->statements = Sql::split($sql);
        $this->message = 'Connecting…';
        $this->db->connect()->then(fn () => $this->next(), fn (\Throwable $e) => $this->fail($e->getMessage()));
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

    private function next(): void
    {
        if ($this->done) {
            return;
        }
        if ($this->at >= count($this->statements)) {
            $this->done = true;
            $this->progress = 1;
            $this->message = sprintf('%d statement(s) run%s', $this->executed, $this->errors !== [] ? ', ' . count($this->errors) . ' error(s)' : '');
            $this->db->close();
            return;
        }
        $statement = $this->statements[$this->at++];
        $this->progress = $this->at / max(1, count($this->statements));
        $this->message = sprintf('%d / %d', $this->at, count($this->statements));
        $this->db->query($statement, 1)->then(function (): void {
            $this->executed++;
            $this->next();
        }, function (\Throwable $e) use ($statement): void {
            $why = ($e instanceof MysqlError ? $e->describe() : $e->getMessage()) . ' — ' . mb_strimwidth($statement, 0, 120, '…');
            $this->errors[] = $why;
            if ($this->continueOnError && $this->db->isReady()) {
                $this->next();
                return;
            }
            $this->fail($why);
        });
    }

    private function fail(string $why): void
    {
        if ($this->done) {
            return;
        }
        $this->error = $why;
        $this->message = $why;
        $this->done = true;
        $this->db->close();
    }
}
