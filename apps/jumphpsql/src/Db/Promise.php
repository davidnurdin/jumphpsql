<?php

namespace App\Db;

/**
 * The answer of the server to come: then(fn ($value) => ..., fn (\Throwable $e) => ...). Settled by the connection between
 * two frames (MysqlConnection::poll()), never while the window waits.
 *
 * @template T
 */
final class Promise
{
    private bool $done = false;
    private mixed $value = null;
    private ?\Throwable $error = null;
    /** @var list<array{0: ?callable, 1: ?callable}> */
    private array $callbacks = [];

    public static function resolved(mixed $value): self
    {
        $p = new self();
        $p->resolve($value);
        return $p;
    }

    public static function rejected(\Throwable $error): self
    {
        $p = new self();
        $p->reject($error);
        return $p;
    }

    public function then(?callable $onDone, ?callable $onError = null): self
    {
        if ($this->done) {
            $this->call($onDone, $onError);
        } else {
            $this->callbacks[] = [$onDone, $onError];
        }
        return $this;
    }

    public function isDone(): bool
    {
        return $this->done;
    }

    public function value(): mixed
    {
        if ($this->error !== null) {
            throw $this->error;
        }
        return $this->value;
    }

    public function error(): ?\Throwable
    {
        return $this->error;
    }

    public function resolve(mixed $value): void
    {
        if ($this->done) {
            return;
        }
        $this->done = true;
        $this->value = $value;
        $this->flush();
    }

    public function reject(\Throwable $error): void
    {
        if ($this->done) {
            return;
        }
        $this->done = true;
        $this->error = $error;
        $this->flush();
    }

    private function flush(): void
    {
        $callbacks = $this->callbacks;
        $this->callbacks = [];
        foreach ($callbacks as [$onDone, $onError]) {
            $this->call($onDone, $onError);
        }
    }

    private function call(?callable $onDone, ?callable $onError): void
    {
        if ($this->error === null) {
            $onDone !== null && $onDone($this->value);
        } elseif ($onError !== null) {
            $onError($this->error);
        }
    }
}
