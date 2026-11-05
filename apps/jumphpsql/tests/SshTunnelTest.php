<?php

namespace Tests;

use App\Db\Endpoint;
use App\Db\MysqlConnection;
use App\Db\SshTunnel;
use PHPUnit\Framework\TestCase;

final class SshTunnelTest extends TestCase
{
    private ?Endpoint $endpoint = null;
    private string $knownHosts = '';

    protected function setUp(): void
    {
        $this->knownHosts = sys_get_temp_dir() . '/jumphpsql-known-hosts-' . getmypid() . '-' . mt_rand();
    }

    protected function tearDown(): void
    {
        $this->endpoint?->close();
        @unlink($this->knownHosts);
    }

    /** @return array<string, mixed> */
    private function ssh(array $more = []): array
    {
        $config = TestServer::sshConfig();
        if ($config === null) {
            self::markTestSkipped('No SSH test server on 2299 or no ssh client (see tests/TestServer.php)');
        }
        return $more + $config;
    }

    /** Opens the endpoint, frame after frame, as the window does: the connection, or the error. */
    private function open(array $config): MysqlConnection|\Throwable
    {
        $this->endpoint = new Endpoint($config, $this->knownHosts);
        $outcome = null;
        $this->endpoint->open()->then(function (MysqlConnection $db) use (&$outcome): void {
            $outcome = $db;
        }, function (\Throwable $e) use (&$outcome): void {
            $outcome = $e;
        });
        $end = microtime(true) + 25;
        while ($outcome === null && microtime(true) < $end) {
            $this->endpoint->poll();
            usleep(5000);
        }
        self::assertNotNull($outcome, 'the tunnel answered in time');
        return $outcome;
    }

    private function query(MysqlConnection $db, string $sql): string
    {
        return (string)$db->wait($db->first($sql))->rows[0][0];
    }

    public function testTheCommandHasNoSecretAndFitsTheAuthentication(): void
    {
        $base = ['sshHost' => 'bastion', 'sshUser' => 'me', 'host' => 'db.internal', 'port' => 3307, 'sshPassword' => 'pw', 'sshPassphrase' => 'pp'];
        $agent = (new SshTunnel($base + ['sshAuth' => 'agent']))->command(40000);
        self::assertSame(['ssh', '-N', '-T', '-L', '127.0.0.1:40000:db.internal:3307', '-p', '22'], array_slice($agent, 0, 7));
        self::assertContains('BatchMode=yes', $agent);
        self::assertSame('me@bastion', end($agent));
        $key = (new SshTunnel($base + ['sshAuth' => 'key', 'sshKey' => '/k']))->command(1);
        self::assertContains('/k', $key);
        self::assertContains('IdentitiesOnly=yes', $key);
        self::assertNotContains('BatchMode=yes', $key, 'a passphrase may be asked');
        $password = (new SshTunnel($base + ['sshAuth' => 'password']))->command(1);
        self::assertContains('PubkeyAuthentication=no', $password);
        foreach ([$agent, $key, $password] as $command) {
            self::assertNotContains('pw', $command);
            self::assertNotContains('pp', $command);
        }
    }

    public function testAPasswordOpensTheTunnelToTheDatabase(): void
    {
        $db = $this->open($this->ssh());
        self::assertInstanceOf(MysqlConnection::class, $db, $db instanceof \Throwable ? $db->getMessage() : '');
        self::assertSame('1', $this->query($db, 'SELECT 1'));
        self::assertSame('127.0.0.1', $this->endpoint->mysqlConfig()['host'], 'another connection goes through the tunnel');
        self::assertFileExists($this->knownHosts, 'the host key is remembered');
    }

    public function testAWrongPasswordIsRefused(): void
    {
        $error = $this->open($this->ssh(['sshPassword' => 'wrong']));
        self::assertInstanceOf(\Throwable::class, $error);
        self::assertStringContainsString('Permission denied', $error->getMessage());
    }

    public function testAKeyFileOpensTheTunnel(): void
    {
        $dir = TestServer::sshKeys();
        $db = $this->open($this->ssh(['sshAuth' => 'key', 'sshKey' => "$dir/key"]));
        self::assertInstanceOf(MysqlConnection::class, $db, $db instanceof \Throwable ? $db->getMessage() : '');
        self::assertSame('2', $this->query($db, 'SELECT 1 + 1'));
    }

    public function testAKeyWithAPassphraseOpensTheTunnel(): void
    {
        $dir = TestServer::sshKeys();
        $db = $this->open($this->ssh(['sshAuth' => 'key', 'sshKey' => "$dir/keyp", 'sshPassphrase' => 'secret phrase']));
        self::assertInstanceOf(MysqlConnection::class, $db, $db instanceof \Throwable ? $db->getMessage() : '');
        $this->endpoint->close();
        $error = $this->open($this->ssh(['sshAuth' => 'key', 'sshKey' => "$dir/keyp", 'sshPassphrase' => '']));
        self::assertInstanceOf(\Throwable::class, $error, 'without its passphrase the key is refused');
    }

    public function testTheSessionKeyOfTheAgentOpensTheTunnel(): void
    {
        $config = $this->ssh(['sshAuth' => 'agent']);
        $dir = TestServer::sshKeys();
        $socket = sys_get_temp_dir() . '/jumphpsql-agent-' . getmypid() . '.sock';
        @unlink($socket);
        $env = '';
        foreach (SshTunnel::passwdWrapper() as $name => $value) {
            $env .= $name . '=' . escapeshellarg($value) . ' ';
        }
        $out = (string)shell_exec($env . 'ssh-agent -a ' . escapeshellarg($socket) . ' 2>&1');
        self::assertMatchesRegularExpression('/SSH_AGENT_PID=(\d+)/', $out);
        preg_match('/SSH_AGENT_PID=(\d+)/', $out, $m);
        $before = getenv('SSH_AUTH_SOCK');
        putenv("SSH_AUTH_SOCK=$socket");
        try {
            shell_exec($env . 'ssh-add ' . escapeshellarg("$dir/key") . ' 2>&1');
            $db = $this->open($config);
            self::assertInstanceOf(MysqlConnection::class, $db, $db instanceof \Throwable ? $db->getMessage() : '');
        } finally {
            putenv($before === false ? 'SSH_AUTH_SOCK' : "SSH_AUTH_SOCK=$before");
            posix_kill((int)$m[1], SIGTERM);
            @unlink($socket);
        }
    }

    public function testAnUnknownSshHostFailsWithItsReason(): void
    {
        $error = $this->open($this->ssh(['sshHost' => 'no-such-host.invalid']));
        self::assertInstanceOf(\Throwable::class, $error);
        self::assertStringStartsWith('SSH: ', $error->getMessage());
    }

    public function testTheWindowGoesThroughTheTunnelWhenItDrops(): void
    {
        $db = $this->open($this->ssh());
        self::assertInstanceOf(MysqlConnection::class, $db, $db instanceof \Throwable ? $db->getMessage() : '');
        $why = null;
        $this->endpoint->onClose = function (string $reason) use (&$why): void {
            $why = $reason;
        };
        // the ssh process killed: the endpoint says so
        $tunnel = $this->endpoint->tunnel;
        $process = (new \ReflectionProperty($tunnel, 'process'))->getValue($tunnel);
        proc_terminate($process, SIGKILL);
        $end = microtime(true) + 5;
        while ($why === null && microtime(true) < $end) {
            $this->endpoint->poll();
            usleep(5000);
        }
        self::assertNotNull($why);
    }
}
