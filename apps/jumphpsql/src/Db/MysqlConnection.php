<?php

namespace App\Db;

/**
 * A connection to a MySQL or MariaDB server, in pure PHP (the client/server protocol over a TCP socket): no mysqli nor
 * PDO needed, and it never blocks the window. Commands are queued and answered by promises; poll() (called every frame)
 * moves the bytes. The protocol itself is written straight, in a Fiber that waits for the bytes it needs.
 *
 *   $db = new MysqlConnection(['host' => '127.0.0.1', 'port' => 3306, 'user' => 'root', 'password' => '']);
 *   $db->connect()->then(fn () => ..., fn (\Throwable $e) => ...);
 *   $db->query('SELECT * FROM shop.products')->then(fn (array $results) => $results[0]->rows);
 *
 * Authentication: mysql_native_password, caching_sha2_password (MySQL 8: full authentication with the server's RSA key),
 * mysql_clear_password and client_ed25519 (MariaDB) when sodium is there.
 */
final class MysqlConnection
{
    private const LONG_PASSWORD = 1, FOUND_ROWS = 2, LONG_FLAG = 4, CONNECT_WITH_DB = 8, PROTOCOL_41 = 0x200,
        TRANSACTIONS = 0x2000, SECURE_CONNECTION = 0x8000, MULTI_STATEMENTS = 0x10000, MULTI_RESULTS = 0x20000,
        PLUGIN_AUTH = 0x80000, PLUGIN_AUTH_LENENC = 0x200000;
    private const MORE_RESULTS = 0x08;
    private const UTF8MB4 = 45;

    /** new, connecting, ready, closed */
    public string $state = 'new';
    public string $serverVersion = '';
    public int $threadId = 0;
    public ?string $database = null;
    /** the last error of the connection itself (lost, refused...) */
    public ?string $error = null;
    /** called when the connection drops: fn (string $why) */
    public ?\Closure $onClose = null;

    /** @var resource|null */
    private $stream = null;
    /** the connection through the engine (JumPHP\Net\Tcp: a socket on desktop, the playground's TCP bridge in the browser) */
    /** @var \JumPHP\Net\TcpConnection|\JumPHP\Net\SshConnection|null */
    private ?object $conn = null;
    private string $in = '';
    private string $out = '';
    private int $seq = 0;
    private ?\Fiber $fiber = null;
    private float $deadline = 0;
    /** @var list<array{0: string, 1: mixed, 2: Promise}> */
    private array $queue = [];
    /** @var list<array{0: Promise, 1: mixed, 2: ?\Throwable}> */
    private array $settled = [];
    private ?Promise $connecting = null;
    private bool $working = false;

    /** @param array{host?: string, port?: int|string, user?: string, password?: string, database?: string, timeout?: float, tcp?: ?object} $config an object with connect($host, $port, $timeout): JumPHP\Net\Tcp, or Ssh::via() */
    public function __construct(public readonly array $config)
    {
    }

    public function isReady(): bool
    {
        return $this->state === 'ready';
    }

    /** Commands not answered yet (the one running included). */
    public function pending(): int
    {
        return count($this->queue) + ($this->working ? 1 : 0);
    }

    public function connect(): Promise
    {
        if ($this->connecting !== null) {
            return $this->connecting;
        }
        $this->connecting = new Promise();
        $host = (string)($this->config['host'] ?? '127.0.0.1');
        $host = $host === 'localhost' ? '127.0.0.1' : $host;
        $port = (int)($this->config['port'] ?? 3306);
        $timeout = (float)($this->config['timeout'] ?? 10);
        if (is_object($tcp = $this->config['tcp'] ?? null) && method_exists($tcp, 'connect')) {
            $this->state = 'connecting';
            $this->deadline = microtime(true) + $timeout;
            $conn = $this->conn = $tcp->connect($host, $port, $timeout);
            $conn->on('data', function (string $data): void {
                $this->in .= $data;
            });
            $conn->on('open', function (): void {
                if ($this->state === 'connecting' && $this->fiber === null) {
                    $this->fiber = new \Fiber(fn () => $this->work());
                    $this->resume();
                    $this->flush();
                }
            });
            $conn->on('close', function (?string $why) use ($host, $port): void {
                if ($this->state === 'closed') {
                    return;
                }
                $this->fail($this->state === 'connecting' ? "Can't connect to $host:$port: " . ($why ?? 'connection closed') : 'Lost connection to the server');
            });
            return $this->connecting;
        }
        $errno = 0;
        $error = '';
        $target = str_contains($host, ':') && !str_starts_with($host, '[') ? "[$host]" : $host;
        $stream = @stream_socket_client("tcp://$target:$port", $errno, $error, $timeout, STREAM_CLIENT_CONNECT | STREAM_CLIENT_ASYNC_CONNECT);
        if ($stream === false) {
            $this->state = 'closed';
            $this->error = "Can't connect to $host:$port: " . ($error !== '' ? $error : "error $errno");
            $this->connecting->reject(new MysqlError($this->error));
            return $this->connecting;
        }
        stream_set_blocking($stream, false);
        $this->stream = $stream;
        $this->state = 'connecting';
        $this->deadline = microtime(true) + $timeout;
        return $this->connecting;
    }

    /**
     * Runs SQL (several statements separated by ';' too): a list of ResultSet, one per statement.
     * $maxRows: rows kept per result (0: all), the others are counted.
     */
    public function query(string $sql, int $maxRows = 0): Promise
    {
        return $this->enqueue('query', [$sql, $maxRows]);
    }

    /** The first result of a query. */
    public function first(string $sql, int $maxRows = 0): Promise
    {
        $p = new Promise();
        $this->query($sql, $maxRows)->then(fn (array $results) => $p->resolve($results[0] ?? new ResultSet()), fn (\Throwable $e) => $p->reject($e));
        return $p;
    }

    public function useDatabase(string $database): Promise
    {
        return $this->enqueue('initdb', $database);
    }

    public function ping(): Promise
    {
        return $this->enqueue('ping', null);
    }

    public function close(): void
    {
        if ($this->stream !== null && $this->state === 'ready') {
            @fwrite($this->stream, pack('V', 1) . "\x01");      // COM_QUIT
        }
        if ($this->conn !== null && $this->state === 'ready') {
            $this->conn->write(pack('V', 1) . "\x01");
        }
        $this->drop('Connection closed');
    }

    private function enqueue(string $kind, mixed $arg): Promise
    {
        $p = new Promise();
        if ($this->state === 'closed') {
            $p->reject(new MysqlError($this->error ?? 'Not connected'));
            return $p;
        }
        $this->queue[] = [$kind, $arg, $p];
        return $p;
    }

    // the frame: bytes in and out, the protocol goes on -------------------------------------------------------------

    public function poll(): void
    {
        if ($this->conn !== null) {
            for ($turn = 0; $turn < 64 && $this->conn !== null && $this->fiber !== null; $turn++) {
                $before = strlen($this->in) . '|' . count($this->queue) . '|' . strlen($this->out);
                $this->resume();
                $this->flush();
                if ($before === strlen($this->in) . '|' . count($this->queue) . '|' . strlen($this->out)) {
                    break;
                }
            }
            if ($this->state === 'connecting' && microtime(true) > $this->deadline) {
                $this->fail('Connection timed out');
            }
            $this->dispatch();
            return;
        }
        if ($this->stream === null) {
            $this->dispatch();
            return;
        }
        if ($this->state === 'connecting' && $this->fiber === null) {
            $read = null;
            $write = [$this->stream];
            $except = null;
            if (@stream_select($read, $write, $except, 0) !== 1) {
                if (microtime(true) > $this->deadline) {
                    $this->fail('Connection timed out');
                }
                $this->dispatch();
                return;
            }
            if (stream_socket_get_name($this->stream, true) === false) {
                $this->fail("Can't connect to " . ($this->config['host'] ?? '') . ':' . ($this->config['port'] ?? 3306) . ': connection refused');
                $this->dispatch();
                return;
            }
            $this->fiber = new \Fiber(fn () => $this->work());
            $this->resume();
        }
        for ($turn = 0; $turn < 64 && $this->stream !== null; $turn++) {
            $this->flush();
            $got = $this->receive();
            if ($this->stream === null) {
                break;
            }
            $before = strlen($this->in) . '|' . count($this->queue);
            $this->resume();
            $this->flush();
            if (!$got && $before === strlen($this->in) . '|' . count($this->queue)) {
                break;
            }
        }
        if ($this->state === 'connecting' && $this->stream !== null && microtime(true) > $this->deadline) {
            $this->fail('Connection timed out');
        }
        $this->dispatch();
    }

    /** Blocks until a promise is settled (scripts and tests only: the window never calls it). */
    public function wait(Promise $promise, float $timeout = 30): mixed
    {
        $end = microtime(true) + $timeout;
        while (!$promise->isDone()) {
            if (microtime(true) > $end) {
                throw new MysqlError('Timed out waiting for the server');
            }
            if ($this->stream !== null) {
                $read = [$this->stream];
                $write = $this->out !== '' || $this->state === 'connecting' ? [$this->stream] : null;
                $except = null;
                @stream_select($read, $write, $except, 0, 20000);
            }
            if ($this->conn !== null) {
                if (method_exists($this->config['tcp'], 'poll')) {
                    $this->config['tcp']->poll();
                }
                usleep(2000);
            }
            $this->poll();
            if ($this->stream === null && $this->conn === null && !$promise->isDone()) {
                $this->poll();
                if (!$promise->isDone()) {
                    throw new MysqlError($this->error ?? 'Connection closed');
                }
            }
        }
        return $promise->value();
    }

    private function receive(): bool
    {
        $got = false;
        while (($chunk = @fread($this->stream, 262144)) !== false && $chunk !== '') {
            $this->in .= $chunk;
            $got = true;
        }
        if (!$got && feof($this->stream)) {
            $this->fail('Lost connection to the server');
        }
        return $got;
    }

    private function flush(): void
    {
        if ($this->out !== '' && $this->conn !== null) {
            $this->conn->write($this->out);
            $this->out = '';
            return;
        }
        if ($this->out === '' || $this->stream === null) {
            return;
        }
        $written = @fwrite($this->stream, $this->out);
        if ($written === false) {
            $this->fail('Lost connection to the server (write)');
            return;
        }
        $this->out = (string)substr($this->out, $written);
    }

    private function resume(): void
    {
        $fiber = $this->fiber;
        if ($fiber === null || $fiber->isTerminated()) {
            return;
        }
        try {
            $fiber->isStarted() ? ($fiber->isSuspended() ? $fiber->resume() : null) : $fiber->start();
        } catch (MysqlError $e) {
            $this->fail($e->getMessage());
        } catch (\Throwable $e) {
            $this->fail('Protocol error: ' . $e->getMessage());
        }
    }

    /** Callbacks run outside the fiber, once the frame's bytes are read. */
    private function dispatch(): void
    {
        while ($this->settled !== []) {
            [$promise, $value, $error] = array_shift($this->settled);
            $error !== null ? $promise->reject($error) : $promise->resolve($value);
        }
    }

    private function fail(string $why): void
    {
        $this->error = $why;
        if ($this->connecting !== null && !$this->connecting->isDone() && $this->state !== 'ready') {
            $this->settled[] = [$this->connecting, null, new MysqlError($why)];
        }
        $this->drop($why);
    }

    private function drop(string $why): void
    {
        $wasOpen = $this->state === 'ready';
        if ($this->stream !== null) {
            @fclose($this->stream);
        }
        $this->stream = null;
        $conn = $this->conn;
        $this->conn = null;
        $this->fiber = null;
        $this->state = 'closed';
        $conn?->close();
        $this->working = false;
        foreach ($this->queue as [, , $promise]) {
            $this->settled[] = [$promise, null, new MysqlError($why)];
        }
        $this->queue = [];
        if ($this->current !== null) {
            $this->settled[] = [$this->current, null, new MysqlError($why)];
            $this->current = null;
        }
        if ($wasOpen && $this->onClose !== null) {
            ($this->onClose)($why);
        }
    }

    private ?Promise $current = null;

    // the protocol (inside the fiber) ------------------------------------------------------------------------------

    private function work(): void
    {
        $this->handshake();
        $this->state = 'ready';
        $this->settled[] = [$this->connecting, $this, null];
        while (true) {
            if ($this->queue === []) {
                \Fiber::suspend();
                continue;
            }
            [$kind, $arg, $promise] = array_shift($this->queue);
            $this->working = true;
            $this->current = $promise;
            try {
                $value = match ($kind) {
                    'query' => $this->runQuery((string)$arg[0], (int)$arg[1]),
                    'initdb' => $this->simple("\x02" . $arg),
                    'ping' => $this->simple("\x0e"),
                    default => null,
                };
                if ($kind === 'initdb') {
                    $this->database = (string)$arg;
                }
                $this->settled[] = [$promise, $value, null];
            } catch (MysqlError $e) {
                if ($e->getCode() === 0) {
                    throw $e;          // the connection itself
                }
                $this->settled[] = [$promise, null, $e];
            }
            $this->current = null;
            $this->working = false;
        }
    }

    private function readPacket(): string
    {
        $payload = '';
        do {
            while (strlen($this->in) < 4 || strlen($this->in) < 4 + (unpack('V', substr($this->in, 0, 3) . "\0")[1])) {
                \Fiber::suspend();
                if ($this->stream === null && $this->conn === null) {
                    throw new MysqlError($this->error ?? 'Connection closed');
                }
            }
            $len = unpack('V', substr($this->in, 0, 3) . "\0")[1];
            $this->seq = (ord($this->in[3]) + 1) & 0xff;
            $payload .= substr($this->in, 4, $len);
            $this->in = (string)substr($this->in, 4 + $len);
        } while ($len === 0xffffff);
        return $payload;
    }

    private function writePacket(string $payload): void
    {
        do {
            $part = substr($payload, 0, 0xffffff);
            $payload = (string)substr($payload, 0xffffff);
            $this->out .= substr(pack('V', strlen($part)), 0, 3) . chr($this->seq) . $part;
            $this->seq = ($this->seq + 1) & 0xff;
        } while (strlen($part) === 0xffffff);
        $this->flush();
    }

    private function command(string $payload): void
    {
        $this->seq = 0;
        $this->writePacket($payload);
    }

    /** A command answered by OK or ERR. */
    private function simple(string $payload): ResultSet
    {
        $this->command($payload);
        $packet = $this->readPacket();
        $this->checkError($packet);
        $result = new ResultSet();
        $this->parseOk($packet, $result);
        return $result;
    }

    private function checkError(string $packet): void
    {
        if ($packet !== '' && $packet[0] === "\xff") {
            $code = unpack('v', substr($packet, 1, 2))[1];
            $state = ($packet[3] ?? '') === '#' ? substr($packet, 4, 5) : '';
            $message = ($packet[3] ?? '') === '#' ? substr($packet, 9) : substr($packet, 3);
            throw new MysqlError($message, $code ?: 1, $state);
        }
    }

    private function handshake(): void
    {
        $packet = $this->readPacket();
        $this->checkError($packet);
        $pos = 1;
        $this->serverVersion = $this->zstring($packet, $pos);
        $this->threadId = unpack('V', substr($packet, $pos, 4))[1];
        $pos += 4;
        $scramble = substr($packet, $pos, 8);
        $pos += 9;
        $caps = unpack('v', substr($packet, $pos, 2))[1];
        $pos += 2 + 1 + 2;          // charset, status
        $caps |= unpack('v', substr($packet, $pos, 2))[1] << 16;
        $pos += 2;
        $authLen = ord($packet[$pos]);
        $pos += 11;
        if ($caps & self::SECURE_CONNECTION) {
            $part = substr($packet, $pos, max(13, $authLen - 8));
            $pos += strlen($part);
            $scramble .= rtrim($part, "\0");
        }
        $plugin = $caps & self::PLUGIN_AUTH ? $this->zstring($packet, $pos) : 'mysql_native_password';
        $scramble = substr($scramble, 0, 20);

        $database = (string)($this->config['database'] ?? '');
        $flags = self::LONG_PASSWORD | self::FOUND_ROWS | self::LONG_FLAG | self::PROTOCOL_41 | self::TRANSACTIONS | self::SECURE_CONNECTION
            | self::MULTI_STATEMENTS | self::MULTI_RESULTS | self::PLUGIN_AUTH | self::PLUGIN_AUTH_LENENC
            | ($database !== '' ? self::CONNECT_WITH_DB : 0);
        $flags &= $caps | self::CONNECT_WITH_DB;
        $auth = $this->authData($plugin, $scramble);
        $this->writePacket(pack('VVC', $flags, 0x1000000, self::UTF8MB4) . str_repeat("\0", 23)
            . (string)($this->config['user'] ?? 'root') . "\0" . $this->lenencInt(strlen($auth)) . $auth
            . ($database !== '' ? $database . "\0" : '') . $plugin . "\0");
        $this->authLoop($plugin, $scramble);
        $this->database = $database !== '' ? $database : null;
    }

    private function authLoop(string $plugin, string $scramble): void
    {
        $password = (string)($this->config['password'] ?? '');
        while (true) {
            $packet = $this->readPacket();
            $this->checkError($packet);
            $head = $packet[0] ?? '';
            if ($head === "\x00") {
                return;
            }
            if ($head === "\xfe") {           // the server wants another method
                $pos = 1;
                $plugin = $this->zstring($packet, $pos);
                $scramble = substr(rtrim(substr($packet, $pos), "\0"), 0, 20);
                $this->writePacket($this->authData($plugin, $scramble));
                continue;
            }
            if ($head === "\x01") {           // more data (caching_sha2_password)
                $data = substr($packet, 1);
                if ($data === "\x03") {
                    continue;                  // fast authentication done: OK follows
                }
                if ($data === "\x04") {
                    if ($password === '') {
                        $this->writePacket("\0");
                    } else {
                        $this->writePacket("\x02");      // the server's public key, please
                    }
                    continue;
                }
                if (str_starts_with($data, '-----BEGIN')) {
                    $this->writePacket($this->rsaPassword($password, $scramble, $data));
                    continue;
                }
            }
            throw new MysqlError('Unexpected answer during authentication');
        }
    }

    private function authData(string $plugin, string $scramble): string
    {
        $password = (string)($this->config['password'] ?? '');
        if ($password === '' && $plugin !== 'mysql_clear_password') {
            return '';
        }
        switch ($plugin) {
            case 'mysql_native_password':
                $a = sha1($password, true);
                return $a ^ sha1($scramble . sha1($a, true), true);
            case 'caching_sha2_password':
                $a = hash('sha256', $password, true);
                return $a ^ hash('sha256', hash('sha256', $a, true) . $scramble, true);
            case 'mysql_clear_password':
                return $password . "\0";
            case 'client_ed25519':
                return $this->ed25519($password, $scramble);
        }
        throw new MysqlError("Authentication method '$plugin' is not supported");
    }

    private function rsaPassword(string $password, string $scramble, string $pem): string
    {
        if (!function_exists('openssl_public_encrypt')) {
            throw new MysqlError('caching_sha2_password needs the openssl extension (or a cached login)');
        }
        $plain = $password . "\0";
        $xored = '';
        for ($i = 0; $i < strlen($plain); $i++) {
            $xored .= $plain[$i] ^ $scramble[$i % strlen($scramble)];
        }
        $out = '';
        if (!openssl_public_encrypt($xored, $out, $pem, OPENSSL_PKCS1_OAEP_PADDING)) {
            throw new MysqlError('RSA encryption of the password failed');
        }
        return $out;
    }

    /** MariaDB's client_ed25519: an Ed25519 signature of the scramble with a key made from SHA-512 of the password. */
    private function ed25519(string $password, string $scramble): string
    {
        if (!function_exists('sodium_crypto_sign_detached')) {
            throw new MysqlError('client_ed25519 needs the sodium extension');
        }
        $seed = substr(hash('sha512', $password, true), 0, 32);
        $keys = sodium_crypto_sign_seed_keypair($seed);
        return sodium_crypto_sign_detached($scramble, sodium_crypto_sign_secretkey($keys));
    }

    /** @return list<ResultSet> */
    private function runQuery(string $sql, int $maxRows): array
    {
        $started = microtime(true);
        $this->command("\x03" . $sql);
        $results = [];
        do {
            $result = new ResultSet();
            $result->sql = $sql;
            $packet = $this->readPacket();
            $this->checkError($packet);
            $more = false;
            if ($packet[0] === "\x00") {
                $more = ($this->parseOk($packet, $result) & self::MORE_RESULTS) !== 0;
            } elseif ($packet[0] === "\xfb") {
                $this->writePacket('');      // LOAD DATA LOCAL INFILE: refused (no file sent)
                $packet = $this->readPacket();
                $this->checkError($packet);
                $more = ($this->parseOk($packet, $result) & self::MORE_RESULTS) !== 0;
            } else {
                $pos = 0;
                $count = (int)$this->lenencIntAt($packet, $pos);
                for ($i = 0; $i < $count; $i++) {
                    $result->columns[] = $this->parseColumn($this->readPacket());
                }
                $this->readPacket();          // EOF
                while (true) {
                    $packet = $this->readPacket();
                    if ($packet !== '' && $packet[0] === "\xfe" && strlen($packet) < 9) {
                        $status = unpack('v', substr($packet, 3, 2))[1];
                        $result->warnings = unpack('v', substr($packet, 1, 2))[1];
                        $more = ($status & self::MORE_RESULTS) !== 0;
                        break;
                    }
                    $this->checkError($packet);
                    $result->totalRows++;
                    if ($maxRows > 0 && count($result->rows) >= $maxRows) {
                        $result->truncated = true;
                        continue;
                    }
                    $row = [];
                    $pos = 0;
                    $len = strlen($packet);
                    for ($i = 0; $i < $count && $pos < $len; $i++) {
                        if ($packet[$pos] === "\xfb") {
                            $row[] = null;
                            $pos++;
                        } else {
                            $n = (int)$this->lenencIntAt($packet, $pos);
                            $row[] = substr($packet, $pos, $n);
                            $pos += $n;
                        }
                    }
                    $result->rows[] = $row;
                }
            }
            $now = microtime(true);
            $result->time = $now - $started;
            $started = $now;
            $results[] = $result;
        } while ($more);
        return $results;
    }

    private function parseOk(string $packet, ResultSet $result): int
    {
        $pos = 1;
        $result->affectedRows = (int)$this->lenencIntAt($packet, $pos);
        $result->insertId = $this->lenencIntAt($packet, $pos);
        $status = unpack('v', substr($packet, $pos, 2))[1] ?? 0;
        $result->warnings = unpack('v', substr($packet, $pos + 2, 2))[1] ?? 0;
        $info = (string)substr($packet, $pos + 4);
        $result->info = $info !== '' && ord($info[0]) < 0xfb && ord($info[0]) === strlen($info) - 1 ? substr($info, 1) : $info;
        return (int)$status;
    }

    /** @return array{name: string, table: string, orgTable: string, db: string, orgName: string, type: int, flags: int, charset: int, length: int} */
    private function parseColumn(string $packet): array
    {
        $pos = 0;
        $this->lenencString($packet, $pos);   // catalog
        $db = $this->lenencString($packet, $pos);
        $table = $this->lenencString($packet, $pos);
        $orgTable = $this->lenencString($packet, $pos);
        $name = $this->lenencString($packet, $pos);
        $orgName = $this->lenencString($packet, $pos);
        $this->lenencIntAt($packet, $pos);
        $fixed = unpack('vcharset/Vlength/Ctype/vflags', substr($packet, $pos, 9));
        return ['name' => $name, 'table' => $table, 'orgTable' => $orgTable, 'db' => $db, 'orgName' => $orgName,
            'type' => (int)$fixed['type'], 'flags' => (int)$fixed['flags'], 'charset' => (int)$fixed['charset'], 'length' => (int)$fixed['length']];
    }

    private function zstring(string $s, int &$pos): string
    {
        $end = strpos($s, "\0", $pos);
        $end = $end === false ? strlen($s) : $end;
        $out = substr($s, $pos, $end - $pos);
        $pos = $end + 1;
        return $out;
    }

    private function lenencIntAt(string $s, int &$pos): int|float
    {
        $first = ord($s[$pos] ?? "\0");
        $pos++;
        if ($first < 0xfb) {
            return $first;
        }
        if ($first === 0xfc) {
            $pos += 2;
            return unpack('v', substr($s, $pos - 2, 2))[1];
        }
        if ($first === 0xfd) {
            $pos += 3;
            return unpack('V', substr($s, $pos - 3, 3) . "\0")[1];
        }
        if ($first === 0xfe) {
            $pos += 8;
            $v = unpack('V2', substr($s, $pos - 8, 8));
            return $v[1] + $v[2] * 4294967296;
        }
        return 0;
    }

    private function lenencString(string $s, int &$pos): string
    {
        $n = (int)$this->lenencIntAt($s, $pos);
        $out = substr($s, $pos, $n);
        $pos += $n;
        return $out;
    }

    private function lenencInt(int $n): string
    {
        return match (true) {
            $n < 0xfb => chr($n),
            $n < 0x10000 => "\xfc" . pack('v', $n),
            $n < 0x1000000 => "\xfd" . substr(pack('V', $n), 0, 3),
            default => "\xfe" . pack('VV', $n & 0xffffffff, 0),
        };
    }
}
