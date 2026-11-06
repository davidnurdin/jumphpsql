<?php

namespace App\Db;

/**
 * A saved connection opened: its SSH tunnel first when it has one (`ssh` ticked), then the MySQL connection, through the
 * tunnel's local port. poll() every frame; mysqlConfig() is what another connection to the same server uses (a dump, an
 * import: they go through the same tunnel while it is up).
 *
 *   $endpoint = new Endpoint($config, $storage->path('known_hosts'));
 *   $endpoint->open()->then(fn (MysqlConnection $db) => ..., fn (\Throwable $e) => ...);
 */
final class Endpoint
{
    public ?SshTunnel $tunnel = null;
    public ?MysqlConnection $db = null;
    /** called when the tunnel or the connection drops after they were up: fn (string $why) */
    public ?\Closure $onClose = null;
    private ?Promise $opening = null;

    /** @param array<string, mixed> $config a connection (ConnectionStore's) */
    public function __construct(public readonly array $config, private string $knownHosts = '', private string $ssh = 'ssh')
    {
    }

    public function usesSsh(): bool
    {
        return !empty($this->config['ssh']);
    }

    public function open(): Promise
    {
        if ($this->opening !== null) {
            return $this->opening;
        }
        $this->opening = new Promise();
        if ($this->usesSsh() && \JumPHP\Kernel\Platform::isWasm()) {
            $this->opening->reject(new MysqlError('SSH tunnels need the desktop app: a browser cannot run ssh. Edit the connection to connect directly.'));
            return $this->opening;
        }
        if (!$this->usesSsh()) {
            $this->connect($this->mysqlConfig());
            return $this->opening;
        }
        $ssh = $this->config;
        $ssh['sshKey'] = self::expandHome((string)($ssh['sshKey'] ?? ''));
        $this->tunnel = new SshTunnel($ssh, $this->knownHosts, $this->ssh);
        $this->tunnel->onClose = fn (string $why) => $this->dropped($why);
        $this->tunnel->start()->then(fn () => $this->connect($this->mysqlConfig()), fn (\Throwable $e) => $this->opening->reject($e));
        return $this->opening;
    }

    /** @param array<string, mixed> $config */
    private function connect(array $config): void
    {
        $db = $this->db = new MysqlConnection(['database' => ''] + $config);
        $db->onClose = fn (string $why) => $this->dropped($why);
        $db->connect()->then(fn () => $this->opening?->resolve($db), function (\Throwable $e): void {
            // through a tunnel, ssh knows better why the database did not answer
            $why = $this->tunnel?->forwardError() ?? '';
            $this->tunnel?->close();
            $this->opening?->reject($why !== '' ? new MysqlError($why . ' (' . $e->getMessage() . ')') : $e);
        });
    }

    /** @return array<string, mixed> the connection as MySQL sees it: through the tunnel's port when there is one */
    public function mysqlConfig(): array
    {
        if ($this->tunnel === null) {
            return $this->config;
        }
        return ['host' => '127.0.0.1', 'port' => $this->tunnel->localPort, 'ssh' => false] + $this->config;
    }

    public function poll(): void
    {
        $this->tunnel?->poll();
        $this->db?->poll();
    }

    public function isReady(): bool
    {
        return $this->db !== null && $this->db->isReady();
    }

    /** Closes the tunnel and the connection (onClose is not called: it is for drops). */
    public function close(): void
    {
        $this->onClose = null;
        $this->db?->close();
        $this->tunnel?->close();
    }

    private function dropped(string $why): void
    {
        $this->db?->close();
        $this->tunnel?->close();
        if ($this->onClose !== null) {
            $onClose = $this->onClose;
            $this->onClose = null;      // once
            $onClose($why);
        }
    }

    public static function expandHome(string $path): string
    {
        $home = getenv('HOME') ?: (getenv('USERPROFILE') ?: '');
        return $home !== '' && ($path === '~' || str_starts_with($path, '~/')) ? $home . substr($path, 1) : $path;
    }
}
