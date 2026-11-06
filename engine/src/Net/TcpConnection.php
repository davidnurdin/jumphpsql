<?php

namespace JumPHP\Net;

use Evenement\EventEmitter;
use JumPHP\Http\Http;
use JumPHP\Http\HttpResponse;
use JumPHP\Kernel\GameConfig;
use PhpWasmNetwork\Connection;
use PhpWasmNetwork\Event;
use PhpWasmNetwork\Network;

/**
 * One TCP connection (see Tcp). Events: open, data (string $bytes), close (?string $reason: null = closed normally),
 * error (string $message, before 'close' when a connection could not be made).
 */
final class TcpConnection extends EventEmitter
{
    private const CHUNK = 60000;

    public const IDLE = 'idle';
    public const CONNECTING = 'connecting';
    public const OPEN = 'open';
    public const CLOSED = 'closed';

    private string $state = self::IDLE;
    /** @var resource|null direct socket (desktop) */
    private $socket = null;
    private ?Connection $bridge = null;
    private string $pending = '';
    private float $deadline = 0.0;

    public function __construct(private GameConfig $config, private Http $http, private string $host, private int $port,
        private float $timeout, private bool $viaBridge)
    {
    }

    public function state(): string { return $this->state; }
    public function isOpen(): bool { return $this->state === self::OPEN; }
    public function isClosed(): bool { return $this->state === self::CLOSED; }

    public function start(): void
    {
        $this->state = self::CONNECTING;
        $this->deadline = microtime(true) + $this->timeout;
        if ($this->viaBridge) {
            $this->askTicket();
            return;
        }
        $socket = @stream_socket_client("tcp://{$this->host}:{$this->port}", $errno, $error, 0, STREAM_CLIENT_CONNECT | STREAM_CLIENT_ASYNC_CONNECT);
        if (!$socket) {
            $this->fail($error !== '' ? $error : "cannot connect ($errno)");
            return;
        }
        stream_set_blocking($socket, false);
        $this->socket = $socket;
    }

    /** @return bool false when it cannot be sent (not open) */
    public function write(string $bytes): bool
    {
        if ($this->state === self::CLOSED || $bytes === '') {
            return $this->state !== self::CLOSED;
        }
        if ($this->state === self::CONNECTING) {
            $this->pending .= $bytes;      // sent as soon as it opens
            return true;
        }
        $this->send($bytes);
        return true;
    }

    public function close(): void
    {
        if ($this->state === self::CLOSED) {
            return;
        }
        $this->finish(null);
    }

    public function poll(): void
    {
        if ($this->state === self::CLOSED) {
            return;
        }
        if ($this->state === self::CONNECTING && microtime(true) > $this->deadline) {
            $this->fail('connection timed out');
            return;
        }
        if ($this->socket !== null) {
            $this->pollSocket();
        } elseif ($this->bridge !== null) {
            $this->pollBridge();
        }
    }

    private function pollSocket(): void
    {
        if ($this->state === self::CONNECTING) {
            if (@stream_socket_get_name($this->socket, true) === false) {
                return;       // not connected yet (or refused: the deadline ends it)
            }
            $this->opened();
        }
        $data = @fread($this->socket, 65536);
        if ($data !== '' && $data !== false) {
            $this->emit('data', [$data]);
        } elseif ($data === false || feof($this->socket)) {
            $this->finish('connection closed');
        }
    }

    private function pollBridge(): void
    {
        foreach ($this->bridge->poll() as $event) {
            switch ($event->type) {
                case Event::ROOM:
                    $this->opened();
                    break;
                case Event::MESSAGE:
                    $this->emit('data', [$event->data]);
                    break;
                case Event::PEER_LEAVE:
                    $this->finish('connection closed');
                    return;
                case Event::ERROR:
                    $info = $event->info();
                    $this->fail((string)($info['message'] ?? $event->data));
                    return;
                case Event::CLOSE:
                    $this->finish($this->state === self::OPEN ? 'connection closed' : 'bridge closed');
                    return;
            }
        }
    }

    private function askTicket(): void
    {
        try {
            $this->http->post($this->config->tcpTicketUrl(), ['slug' => $this->config->id(), 'host' => $this->host, 'port' => $this->port])
                ->then(function (HttpResponse $r): void {
                    $data = $r->json();
                    if ($this->state !== self::CONNECTING) {
                        return;
                    }
                    if (!$r->ok() || !is_array($data) || !isset($data['ticket'], $data['bridge'])) {
                        $this->fail(is_array($data) && isset($data['error']) ? (string)$data['error'] : 'the TCP bridge refused (HTTP ' . $r->status . ')');
                        return;
                    }
                    $this->bridge = Network::connect((string)$data['bridge'], ['transport' => 'ws']);
                    $this->bridge->createRoom(2, ['tcp_ticket' => (string)$data['ticket']]);
                }, fn (\Throwable $e) => $this->fail('cannot reach the playground: ' . $e->getMessage()));
        } catch (\Throwable $e) {
            $this->fail($e->getMessage());
        }
    }

    private function opened(): void
    {
        if ($this->state !== self::CONNECTING) {
            return;
        }
        $this->state = self::OPEN;
        $this->emit('open');
        if ($this->pending !== '') {
            $this->send($this->pending);
            $this->pending = '';
        }
    }

    private function send(string $bytes): void
    {
        if ($this->socket !== null) {
            while ($bytes !== '') {
                $n = @fwrite($this->socket, $bytes);
                if ($n === false) {
                    $this->finish('write failed');
                    return;
                }
                if ($n === 0) {                // buffer full: a short wait, the frame stays light for ordinary queries
                    usleep(1000);
                }
                $bytes = (string)substr($bytes, $n);
            }
        } elseif ($this->bridge !== null) {
            foreach (str_split($bytes, self::CHUNK) as $part) {
                $this->bridge->send($part, 0);
            }
        }
    }

    private function fail(string $message): void
    {
        $this->emit('error', [$message]);
        $this->finish($message);
    }

    private function finish(?string $reason): void
    {
        if ($this->state === self::CLOSED) {
            return;
        }
        $this->state = self::CLOSED;
        if ($this->socket !== null) {
            @fclose($this->socket);
            $this->socket = null;
        }
        $this->bridge?->close();
        $this->bridge = null;
        $this->emit('close', [$reason]);
    }
}
