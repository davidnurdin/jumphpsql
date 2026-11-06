<?php

namespace JumPHP\Net;

use JumPHP\Http\Http;
use JumPHP\Kernel\GameConfig;
use JumPHP\Kernel\Platform;

/**
 * TCP connections for applications (a SQL client, a Redis tool...), never blocking the frame.
 *
 *   $c = $engine->tcp()->connect('db.example.com', 3306);
 *   $c->on('open', fn () => ...);  $c->on('data', fn (string $bytes) => ...);  $c->on('close', fn (?string $why) => ...);
 *   $c->write($bytes);  $c->close();
 *
 * Desktop: a plain non-blocking socket. Browser: no sockets exist, so the connection goes through the playground's TCP
 * bridge (WebSocket -> TCP), and only for an application the administrator approved: the page asks jumphp.net for a
 * short-lived ticket for this application, host and port (project.yaml `tcp_ticket_url`), and a modified application,
 * another port than a database's, a private address: refused ('error' event with the reason).
 * The engine polls the connections each frame.
 */
final class Tcp
{
    /** @var list<TcpConnection> */
    private array $connections = [];

    public function __construct(private GameConfig $config, private Http $http)
    {
    }

    /** Whether the browser's bridge is needed (no direct socket). */
    public static function viaBridge(): bool
    {
        return Platform::isWasm();
    }

    public function connect(string $host, int $port, float $timeout = 10.0): TcpConnection
    {
        $connection = new TcpConnection($this->config, $this->http, $host, $port, $timeout, self::viaBridge());
        $this->connections[] = $connection;
        $connection->start();
        return $connection;
    }

    public function poll(): void
    {
        foreach ($this->connections as $i => $connection) {
            $connection->poll();
            if ($connection->isClosed()) {
                unset($this->connections[$i]);
            }
        }
        $this->connections = array_values($this->connections);
    }

    public function closeAll(): void
    {
        foreach ($this->connections as $connection) {
            $connection->close();
        }
        $this->connections = [];
    }
}
