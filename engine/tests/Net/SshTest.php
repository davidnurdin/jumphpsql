<?php

namespace JumPHP\Tests\Net;

use JumPHP\Bridge\JsBridge;
use JumPHP\Http\Http;
use JumPHP\Kernel\GameConfig;
use JumPHP\Net\Ssh;
use JumPHP\Net\SshConnection;
use PHPUnit\Framework\TestCase;

final class SshTest extends TestCase
{
    private function connection(array $options, bool $browser): SshConnection
    {
        return new SshConnection(new GameConfig(['id' => 'sql']), $this->createStub(Http::class), $this->createStub(JsBridge::class), 'db.internal', 3306, $options, 20.0, $browser);
    }

    public function testTheArgumentsPipeTheStreamThroughTheSshServer(): void
    {
        $args = $this->connection(['sshHost' => 'bastion.example.com', 'sshUser' => 'me', 'sshPort' => 2222], true)
            ->arguments('bastion.example.com', '/home/user/.ssh/id', false);
        self::assertSame(['-W', 'db.internal:3306', '-p', '2222', '-l', 'me'], array_slice($args, 0, 6));
        self::assertContains('BatchMode=yes', $args);
        self::assertContains('UserKnownHostsFile=/home/user/.ssh/known_hosts', $args);
        self::assertSame(['-i', '/home/user/.ssh/id'], array_slice($args, array_search('-i', $args), 2));
        self::assertSame(['--', 'bastion.example.com'], array_slice($args, -2));
    }

    public function testASecretTurnsBatchModeOffAndAnIpv6TargetIsBracketed(): void
    {
        $connection = new SshConnection(new GameConfig(['id' => 'sql']), $this->createStub(Http::class), $this->createStub(JsBridge::class), '2001:db8::5', 3306, [], 20.0, false);
        $args = $connection->arguments('bastion', '', true);
        self::assertSame('[2001:db8::5]:3306', $args[1]);
        self::assertContains('BatchMode=no', $args);
        self::assertNotContains('-i', $args);
    }

    public function testWithoutAnSshHostItFailsAtOnce(): void
    {
        $ssh = new Ssh(new GameConfig(['id' => 'sql']), $this->createStub(Http::class), $this->createStub(JsBridge::class));
        $connection = $ssh->connect('db.internal', 3306, []);
        $closed = null;
        $connection->on('close', function (?string $why) use (&$closed): void { $closed = $why; });
        self::assertTrue($connection->isClosed());
        $ssh->poll();
    }
}
