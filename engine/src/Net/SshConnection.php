<?php

namespace JumPHP\Net;

use Evenement\EventEmitter;
use JumPHP\Bridge\JsBridge;
use JumPHP\Http\Http;
use JumPHP\Http\HttpResponse;
use JumPHP\Kernel\GameConfig;

/**
 * One connection through an SSH server (`ssh -W host:port`, see Ssh). The events of TcpConnection: open, data (string), close
 * (?string $reason), error (string, before 'close'); plus knownHosts (string: the known_hosts text after the session, browser only).
 */
final class SshConnection extends EventEmitter
{
    private const HOME = '/home/user/.ssh';
    private static int $next = 1;

    private string $state = TcpConnection::IDLE;
    private int $id;
    private string $stderr = '';
    private string $pending = '';
    private float $deadline = 0.0;
    /** @var resource|null */
    private $process = null;
    /** @var array<int, resource> */
    private array $pipes = [];
    private bool $opened = false;
    private ?\Closure $listener = null;

    /** @param array<string, mixed> $options */
    public function __construct(private GameConfig $config, private Http $http, private JsBridge $bridge, private string $host,
        private int $port, private array $options, private float $timeout, private bool $browser)
    {
        $this->id = self::$next++;
    }

    public function state(): string { return $this->state; }
    public function isOpen(): bool { return $this->state === TcpConnection::OPEN; }
    public function isClosed(): bool { return $this->state === TcpConnection::CLOSED; }

    public function start(): void
    {
        $this->state = TcpConnection::CONNECTING;
        $this->deadline = microtime(true) + $this->timeout + 5;
        $sshHost = trim((string)($this->options['sshHost'] ?? ''));
        if ($sshHost === '') {
            $this->fail('No SSH host.');
            return;
        }
        $this->browser ? $this->askTicket($sshHost) : $this->startProcess($sshHost);
    }

    public function write(string $bytes): bool
    {
        if ($this->state === TcpConnection::CLOSED) {
            return false;
        }
        if ($bytes === '') {
            return true;
        }
        if ($this->state === TcpConnection::CONNECTING && !$this->opened) {
            $this->pending .= $bytes;
            return true;
        }
        $this->send($bytes);
        return true;
    }

    public function close(): void
    {
        if ($this->state === TcpConnection::CLOSED) {
            return;
        }
        if ($this->browser) {
            $this->bridge->send('ssh', ['op' => 'kill', 'id' => $this->id]);
        }
        $this->finish(null);
    }

    public function poll(): void
    {
        if ($this->state === TcpConnection::CLOSED) {
            return;
        }
        if ($this->process !== null) {
            $this->pollProcess();
        }
        if ($this->state === TcpConnection::CONNECTING && !$this->opened && microtime(true) > $this->deadline) {
            $this->fail('SSH connection timed out');
        }
    }

    /** The arguments of `ssh -W` (the same for the program and for ssh.wasm). @return list<string> */
    public function arguments(string $sshHost, string $identityPath, bool $hasSecret): array
    {
        $args = ['-W', self::bracket($this->host) . ':' . $this->port, '-p', (string)(int)($this->options['sshPort'] ?? 22)];
        if (($user = trim((string)($this->options['sshUser'] ?? ''))) !== '') {
            array_push($args, '-l', $user);
        }
        $extra = array_map('strval', (array)($this->options['sshOptions'] ?? []));
        $set = ['ConnectTimeout=' . (int)$this->timeout, 'ServerAliveInterval=30', 'LogLevel=ERROR',
            'BatchMode=' . ($hasSecret ? 'no' : 'yes'), 'StrictHostKeyChecking=accept-new', 'NumberOfPasswordPrompts=1'];
        foreach (array_merge($set, $extra) as $o) {
            array_push($args, '-o', $o);
        }
        if ($identityPath !== '') {
            array_push($args, '-i', $identityPath, '-o', 'IdentitiesOnly=yes');
        }
        if ($this->browser) {
            array_push($args, '-o', 'UserKnownHostsFile=' . self::HOME . '/known_hosts');
        }
        $args[] = '--';
        $args[] = $sshHost;
        return $args;
    }

    private static function bracket(string $host): string
    {
        return str_contains($host, ':') && !str_starts_with($host, '[') ? "[$host]" : $host;
    }

    // browser: ssh.wasm in a worker, its network the TCP bridge --------------------------------------------------------------

    private function askTicket(string $sshHost): void
    {
        $port = (int)($this->options['sshPort'] ?? 22);
        try {
            $this->http->post($this->config->tcpTicketUrl(), ['slug' => $this->config->id(), 'host' => $sshHost, 'port' => $port, 'scope' => 'ssh'])
                ->then(function (HttpResponse $r) use ($sshHost): void {
                    $data = $r->json();
                    if ($this->state !== TcpConnection::CONNECTING) {
                        return;
                    }
                    if (!$r->ok() || !is_array($data) || !isset($data['ticket'], $data['bridge'])) {
                        $this->fail(is_array($data) && isset($data['error']) ? (string)$data['error'] : 'the TCP bridge refused SSH (HTTP ' . $r->status . ')');
                        return;
                    }
                    $this->openWorker($sshHost, (string)$data['bridge'] . '?raw=1&ticket=' . rawurlencode((string)$data['ticket']));
                }, fn (\Throwable $e) => $this->fail('cannot reach the playground: ' . $e->getMessage()));
        } catch (\Throwable $e) {
            $this->fail($e->getMessage());
        }
    }

    private function openWorker(string $sshHost, string $websocket): void
    {
        $identity = (string)($this->options['sshKey'] ?? '');
        $secret = (string)($this->options['sshPassphrase'] ?? $this->options['sshPassword'] ?? '');
        $this->bridge->on('ssh', $this->listener = function (array $e): void {
            if ((int)($e['id'] ?? 0) === $this->id) {
                $this->onPage($e);
            }
        });
        $this->bridge->send('ssh', ['op' => 'open', 'id' => $this->id, 'options' => [
            'args' => $this->arguments($sshHost, $identity !== '' ? self::HOME . '/id' : '', $secret !== ''),
            'websocket' => $websocket, 'identity' => $identity, 'secret' => $secret, 'knownHosts' => (string)($this->options['knownHosts'] ?? ''),
        ]]);
        $this->opened();
    }

    /** @param array<string, mixed> $e */
    private function onPage(array $e): void
    {
        switch ($e['ev'] ?? '') {
            case 'stdout':
                $this->emit('data', [(string)base64_decode((string)($e['data'] ?? ''))]);
                break;
            case 'stderr':
                $this->stderr = mb_substr($this->stderr . (string)($e['text'] ?? ''), -2000);
                break;
            case 'knownHosts':
                $this->emit('knownHosts', [(string)($e['text'] ?? '')]);
                break;
            case 'exit':
                $code = (int)($e['code'] ?? 0);
                $this->finish($code === 0 ? null : $this->reason($code), $code !== 0);
                break;
            case 'error':
                $this->fail((string)($e['text'] ?? 'SSH error'));
                break;
        }
    }

    // desktop: the system's ssh --------------------------------------------------------------------------------------------

    private function startProcess(string $sshHost): void
    {
        $secret = (string)($this->options['sshPassphrase'] ?? $this->options['sshPassword'] ?? '');
        $key = (string)($this->options['sshKey'] ?? '');
        $keyFile = $key !== '' && is_file($key) ? $key : '';
        $command = ['ssh', ...$this->arguments($sshHost, $keyFile, false)];
        if ($secret !== '') {
            $this->fail('A password or passphrase needs the desktop tunnel (SshTunnel) or an ssh-agent: this pipe is not interactive.');
            return;
        }
        $process = @proc_open($command, [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
        if (!is_resource($process)) {
            $this->fail('cannot start ssh (is the ssh program installed?)');
            return;
        }
        foreach ($pipes as $pipe) {
            stream_set_blocking($pipe, false);
        }
        $this->process = $process;
        $this->pipes = $pipes;
        $this->opened();
    }

    private function pollProcess(): void
    {
        $data = @fread($this->pipes[1], 65536);
        if ($data !== '' && $data !== false) {
            $this->emit('data', [$data]);
        }
        $err = @fread($this->pipes[2], 8192);
        if ($err !== '' && $err !== false) {
            $this->stderr = mb_substr($this->stderr . $err, -2000);
        }
        $status = proc_get_status($this->process);
        if (!$status['running'] && ($data === '' || $data === false)) {
            $code = (int)$status['exitcode'];
            $this->finish($code === 0 || $code === -1 ? null : $this->reason($code), $code > 0);
        }
    }

    // both ----------------------------------------------------------------------------------------------------------------

    private function reason(int $code): string
    {
        $text = trim(preg_replace('/\s+/', ' ', $this->stderr) ?? '');
        return $text !== '' ? $text : "ssh ended with code $code";
    }

    private function opened(): void
    {
        $this->opened = true;
        $this->state = TcpConnection::OPEN;
        $this->emit('open');
        if ($this->pending !== '') {
            $this->send($this->pending);
            $this->pending = '';
        }
    }

    private function send(string $bytes): void
    {
        if ($this->browser) {
            foreach (str_split($bytes, 49152) as $part) {
                $this->bridge->send('ssh', ['op' => 'data', 'id' => $this->id, 'data' => base64_encode($part)]);
            }
        } elseif ($this->process !== null) {
            @fwrite($this->pipes[0], $bytes);
        }
    }

    private function fail(string $message): void
    {
        $this->emit('error', [$message]);
        $this->finish($message);
    }

    private function finish(?string $reason, bool $asError = false): void
    {
        if ($this->state === TcpConnection::CLOSED) {
            return;
        }
        if ($asError && $reason !== null) {
            $this->emit('error', [$reason]);
        }
        $this->state = TcpConnection::CLOSED;
        if ($this->process !== null) {
            foreach ($this->pipes as $pipe) {
                @fclose($pipe);
            }
            @proc_terminate($this->process);
            @proc_close($this->process);
            $this->process = null;
            $this->pipes = [];
        }
        if ($this->listener !== null) {
            $this->bridge->removeListener('ssh', $this->listener);
            $this->listener = null;
        }
        $this->emit('close', [$reason]);
    }
}
