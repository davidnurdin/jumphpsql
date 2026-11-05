<?php

namespace App\Db;

/** An error of the server (ERR packet: code, SQL state) or of the connection (code 0). */
final class MysqlError extends \RuntimeException
{
    public function __construct(string $message, int $code = 0, public readonly string $sqlState = '')
    {
        parent::__construct($message, $code);
    }

    public function describe(): string
    {
        return $this->getCode() > 0 ? sprintf('%d (%s): %s', $this->getCode(), $this->sqlState, $this->getMessage()) : $this->getMessage();
    }
}
