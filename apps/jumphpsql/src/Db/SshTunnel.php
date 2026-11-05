<?php

namespace App\Db;

/**
 * An SSH tunnel to the database server, made by the system's ssh client in the background (`ssh -N -L`), never waited
 * for: start() returns a Promise of the local port (127.0.0.1:<port> then reaches the database through the SSH server),
 * poll() (every frame) watches the process. The ssh process forwards the bytes by itself, polled or not.
 *
 *   $tunnel = new SshTunnel(['sshHost' => 'bastion.example', 'sshUser' => 'me', 'sshAuth' => 'agent', 'host' => '10.0.0.5', 'port' => 3306]);
 *   $tunnel->start()->then(fn (int $port) => new MysqlConnection(['host' => '127.0.0.1', 'port' => $port] + ...));
 *
 * sshAuth: 'agent' (the session's key, ssh-agent: SSH_AUTH_SOCK), 'key' (sshKey: a private key file, sshPassphrase if it
 * has one), 'password' (sshPassword, given to ssh through SSH_ASKPASS), 'auto' (what ssh does by itself: ~/.ssh/config,
 * the default keys, the agent). Host keys: accepted the first time and remembered (known_hosts of the app, then the
 * user's ~/.ssh/known_hosts); a changed one is refused.
 */
final class SshTunnel
{
    public const AUTHS = ['agent' => 'Session key (ssh-agent)', 'key' => 'Key file', 'password' => 'Password', 'auto' => 'SSH config / default keys'];

    /** new, connecting, ready, closed */
    public string $state = 'new';
    public int $localPort = 0;
    public ?string $error = null;
    /** called when the tunnel drops after it was up: fn (string $why) */
    public ?\Closure $onClose = null;

    /** @var resource|null */
    private $process = null;
    /** @var resource|null */
    private $stderr = null;
    private string $log = '';
    private float $deadline = 0;
    private float $nextProbe = 0;
    private ?Promise $starting = null;
    private ?string $askpass = null;

    /**
     * @param array<string, mixed> $config sshHost, sshPort, sshUser, sshAuth, sshKey, sshPassphrase, sshPassword; host and
     *                                     port: the database server as the SSH server sees it
     * @param string $knownHosts the app's known_hosts file
     */
    public function __construct(public readonly array $config, private string $knownHosts = '', private string $ssh = 'ssh')
    {
    }

    /** The ssh command line (without the secrets: they go through the environment). @return list<string> */
    public function command(int $localPort): array
    {
        $c = $this->config;
        $auth = (string)($c['sshAuth'] ?? 'agent');
        $target = (string)($c['host'] ?? '127.0.0.1');
        $target = $target === 'localhost' ? '127.0.0.1' : $target;
        $target = str_contains($target, ':') ? "[$target]" : $target;
        $args = [$this->ssh, '-N', '-T', '-L', "127.0.0.1:$localPort:$target:" . (int)($c['port'] ?? 3306),
            '-p', (string)((int)($c['sshPort'] ?? 22) ?: 22),
            '-o', 'ExitOnForwardFailure=yes', '-o', 'ServerAliveInterval=30', '-o', 'ServerAliveCountMax=3',
            '-o', 'ConnectTimeout=' . (int)($c['sshTimeout'] ?? 15), '-o', 'StrictHostKeyChecking=accept-new'];
        $home = getenv('HOME') ?: '';
        if ($this->knownHosts !== '') {
            $files = [$this->knownHosts];
            if ($home !== '' && is_file("$home/.ssh/known_hosts")) {
                $files[] = "$home/.ssh/known_hosts";
            }
            $args = [...$args, '-o', 'UserKnownHostsFile=' . implode(' ', array_map(fn (string $f) => str_replace(' ', '\ ', $f), $files))];
        }
        switch ($auth) {
            case 'agent':
                $args = [...$args, '-o', 'PreferredAuthentications=publickey', '-o', 'PasswordAuthentication=no', '-o', 'BatchMode=yes'];
                break;
            case 'key':
                $args = [...$args, '-i', (string)($c['sshKey'] ?? ''), '-o', 'IdentitiesOnly=yes', '-o', 'IdentityAgent=none',
                    '-o', 'PreferredAuthentications=publickey', '-o', 'PasswordAuthentication=no', '-o', 'NumberOfPasswordPrompts=1'];
                if ((string)($c['sshPassphrase'] ?? '') === '') {
                    $args = [...$args, '-o', 'BatchMode=yes'];
                }
                break;
            case 'password':
                $args = [...$args, '-o', 'PreferredAuthentications=password,keyboard-interactive', '-o', 'PubkeyAuthentication=no',
                    '-o', 'IdentityAgent=none', '-o', 'NumberOfPasswordPrompts=1'];
                break;
            default:
                $args = [...$args, '-o', 'BatchMode=yes'];
        }
        $user = trim((string)($c['sshUser'] ?? ''));
        $args[] = ($user !== '' ? $user . '@' : '') . trim((string)($c['sshHost'] ?? ''));
        return $args;
    }

    /** A free port of this computer (asked to the system). */
    public static function freePort(): int
    {
        $server = @stream_socket_server('tcp://127.0.0.1:0', $errno, $error);
        if ($server === false) {
            throw new MysqlError("No free local port for the SSH tunnel: $error");
        }
        $name = (string)stream_socket_get_name($server, false);
        fclose($server);
        return (int)substr($name, strrpos($name, ':') + 1);
    }

    public function start(): Promise
    {
        if ($this->starting !== null) {
            return $this->starting;
        }
        $this->starting = new Promise();
        if (trim((string)($this->config['sshHost'] ?? '')) === '') {
            $this->fail('SSH: a host, please');
            return $this->starting;
        }
        if (($this->config['sshAuth'] ?? '') === 'key' && !is_file((string)($this->config['sshKey'] ?? ''))) {
            $this->fail("SSH: no key file at '" . ($this->config['sshKey'] ?? '') . "'");
            return $this->starting;
        }
        if (!function_exists('proc_open')) {
            $this->fail('SSH: proc_open is not available');
            return $this->starting;
        }
        try {
            $this->localPort = self::freePort();
        } catch (MysqlError $e) {
            $this->fail($e->getMessage());
            return $this->starting;
        }
        if ($this->knownHosts !== '' && !is_dir(dirname($this->knownHosts))) {
            @mkdir(dirname($this->knownHosts), 0700, true);
        }
        $env = $this->environment();
        $null = PHP_OS_FAMILY === 'Windows' ? 'NUL' : '/dev/null';
        $pipes = [];
        $process = @proc_open($this->command($this->localPort), [0 => ['file', $null, 'r'], 1 => ['file', $null, 'w'], 2 => ['pipe', 'w']], $pipes, null, $env);
        if (!is_resource($process)) {
            $this->fail("SSH: can't start '$this->ssh' (is the ssh client installed?)");
            return $this->starting;
        }
        $this->process = $process;
        $this->stderr = $pipes[2];
        stream_set_blocking($this->stderr, false);
        $this->state = 'connecting';
        $this->deadline = microtime(true) + (int)($this->config['sshTimeout'] ?? 15) + 5;
        $this->nextProbe = microtime(true) + 0.15;
        return $this->starting;
    }

    /** @return array<string, string> the process's environment: ours, plus the secret and SSH_ASKPASS when one is needed */
    private function environment(): array
    {
        $env = array_filter(getenv(), 'is_string');
        $secret = match ($this->config['sshAuth'] ?? '') {
            'password' => (string)($this->config['sshPassword'] ?? ''),
            'key' => (string)($this->config['sshPassphrase'] ?? ''),
            default => '',
        };
        $env += self::passwdWrapper();
        if ($secret !== '') {
            $this->askpass = $this->askpassScript();
            $env['JUMPHPSQL_SSH_SECRET'] = $secret;
            $env['SSH_ASKPASS'] = $this->askpass;
            $env['SSH_ASKPASS_REQUIRE'] = 'force';
            $env['DISPLAY'] = $env['DISPLAY'] ?? ':0';
        }
        return $env;
    }

    /**
     * ssh finds the user's home (~/.ssh) and name in the passwd database: in a container run with another uid (bin/jumphp)
     * there is none and ssh stops ("No user exists for uid"). nss_wrapper then gives it one, home = $HOME.
     *
     * @return array<string, string>
     */
    public static function passwdWrapper(): array
    {
        if (!function_exists('posix_getpwuid') || posix_getpwuid(posix_geteuid()) !== false) {
            return [];
        }
        $library = '';
        foreach (glob('/usr/lib/*/libnss_wrapper.so') ?: [] as $file) {
            $library = $file;
        }
        if ($library === '') {
            return [];
        }
        $dir = rtrim(sys_get_temp_dir(), '/') . '/jumphpsql-nss-' . posix_geteuid();
        @mkdir($dir, 0700, true);
        $uid = posix_geteuid();
        $gid = posix_getegid();
        $home = getenv('HOME') ?: sys_get_temp_dir();
        file_put_contents("$dir/passwd", "root:x:0:0:root:/root:/bin/sh\nuser:x:$uid:$gid:user:$home:/bin/sh\n");
        file_put_contents("$dir/group", "root:x:0:\nuser:x:$gid:\n");
        return ['LD_PRELOAD' => $library, 'NSS_WRAPPER_PASSWD' => "$dir/passwd", 'NSS_WRAPPER_GROUP' => "$dir/group"];
    }

    /** A tiny program that prints the secret ssh asks for (it reads it from its environment: never written to a file). */
    private function askpassScript(): string
    {
        $windows = PHP_OS_FAMILY === 'Windows';
        $file = rtrim(sys_get_temp_dir(), '/\\') . DIRECTORY_SEPARATOR . 'jumphpsql-askpass-' . getmypid() . ($windows ? '.cmd' : '.sh');
        file_put_contents($file, $windows ? "@echo off\r\necho %JUMPHPSQL_SSH_SECRET%\r\n" : "#!/bin/sh\nprintf '%s\\n' \"\$JUMPHPSQL_SSH_SECRET\"\n");
        @chmod($file, 0700);
        return $file;
    }

    public function poll(): void
    {
        if ($this->process === null) {
            return;
        }
        $this->readLog();
        $status = proc_get_status($this->process);
        if (!$status['running']) {
            $this->readLog();
            $this->fail($this->reason($status['exitcode']));
            return;
        }
        if ($this->state === 'connecting') {
            if (microtime(true) > $this->deadline) {
                $this->fail('SSH: timed out' . ($this->log !== '' ? ' — ' . $this->lastLine() : ''));
                return;
            }
            if (microtime(true) >= $this->nextProbe) {
                $this->nextProbe = microtime(true) + 0.15;
                // the forwarded port listens once ssh is logged in (and ExitOnForwardFailure kept it alive)
                $probe = @stream_socket_client("tcp://127.0.0.1:$this->localPort", $errno, $error, 0.05);
                if ($probe !== false) {
                    fclose($probe);
                    $this->state = 'ready';
                    $this->forgetAskpass();
                    $this->starting?->resolve($this->localPort);
                }
            }
        }
    }

    private function readLog(): void
    {
        while ($this->stderr !== null && ($chunk = @fread($this->stderr, 8192)) !== false && $chunk !== '') {
            $this->log = substr($this->log . $chunk, -4000);
        }
    }

    private function lastLine(): string
    {
        $lines = array_values(array_filter(array_map('trim', explode("\n", $this->log)), fn (string $l) => $l !== '' && !str_starts_with($l, 'Warning: Permanently added')));
        return (string)end($lines);
    }

    private function reason(int $code): string
    {
        $line = $this->lastLine();
        if ($code === 127 || str_contains($line, 'not found')) {
            return "SSH: the ssh client was not found ('$this->ssh')";
        }
        return 'SSH: ' . ($line !== '' ? $line : "ssh stopped (exit code $code)");
    }

    /** Why ssh could not forward a connection (the server refused it, the database is unreachable from it), or ''. */
    public function forwardError(): string
    {
        $this->readLog();
        return preg_match('/channel \d+: open failed: (.+)/', $this->log, $m) ? 'SSH: forwarding refused or failed: ' . trim($m[1]) : '';
    }

    /** What ssh said (its errors and warnings). */
    public function log(): string
    {
        return $this->log;
    }

    public function isReady(): bool
    {
        return $this->state === 'ready';
    }

    public function close(): void
    {
        $this->stop('SSH tunnel closed', false);
    }

    private function fail(string $why): void
    {
        $this->error = $why;
        $this->stop($why, true);
    }

    private function stop(string $why, bool $failed): void
    {
        $wasReady = $this->state === 'ready';
        if ($this->process !== null) {
            $status = proc_get_status($this->process);
            if ($status['running']) {
                proc_terminate($this->process);
            }
            if ($this->stderr !== null) {
                fclose($this->stderr);
            }
            proc_close($this->process);
        }
        $this->process = $this->stderr = null;
        $this->state = 'closed';
        $this->forgetAskpass();
        if ($this->starting !== null && !$this->starting->isDone()) {
            $this->starting->reject(new MysqlError($why));
        }
        if ($wasReady && $failed && $this->onClose !== null) {
            ($this->onClose)($why);
        }
    }

    private function forgetAskpass(): void
    {
        if ($this->askpass !== null) {
            @unlink($this->askpass);
            $this->askpass = null;
        }
    }

    public function __destruct()
    {
        $this->close();
    }
}
