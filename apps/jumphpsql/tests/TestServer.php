<?php

namespace Tests;

use App\Db\MysqlConnection;

/**
 * The server the tests use: a throwaway MariaDB / MySQL, by default on port 3399, root / jumphp, of this computer
 * (127.0.0.1) or of its Docker gateway (172.17.0.1: what the test container sees):
 *
 *   docker run -d --name jumphpsql-mariadb -e MARIADB_ROOT_PASSWORD=jumphp -p 127.0.0.1:3399:3306 -p 172.17.0.1:3399:3306 mariadb:11
 *
 * (JUMPHPSQL_TEST_PORT / JUMPHPSQL_TEST_PASSWORD change it.) Without it, the tests that need it are skipped.
 * seed() makes the database `jumphpsql_test` again: customers (primary key), notes (none), a view.
 */
final class TestServer
{
    public const DATABASE = 'jumphpsql_test';

    private static ?string $host = null;

    /** @return array{host: string, port: int, user: string, password: string, name: string} */
    public static function config(): array
    {
        return ['host' => self::$host ?? (getenv('JUMPHPSQL_TEST_HOST') ?: '127.0.0.1'), 'port' => (int)(getenv('JUMPHPSQL_TEST_PORT') ?: 3399),
            'user' => getenv('JUMPHPSQL_TEST_USER') ?: 'root', 'password' => getenv('JUMPHPSQL_TEST_PASSWORD') ?: 'jumphp', 'name' => 'test server'];
    }

    /** A connection, connected, or null when no server answers. */
    public static function connect(): ?MysqlConnection
    {
        // bin/jumphp test runs in a container of its own network: the server is on the host's Docker gateway there
        $hosts = getenv('JUMPHPSQL_TEST_HOST') ? [getenv('JUMPHPSQL_TEST_HOST')] : ['127.0.0.1', '172.17.0.1', 'host.docker.internal'];
        foreach ($hosts as $host) {
            self::$host = $host;
            $db = new MysqlConnection(self::config() + ['timeout' => 2]);
            try {
                $db->wait($db->connect(), 3);
                return $db;
            } catch (\Throwable) {
            }
        }
        self::$host = null;
        return null;
    }

    /**
     * The test SSH server (a throwaway sshd: user tunnel / tunnelpw, keys from var/tmp/ssh/authorized_keys, forwarding
     * allowed), on port 2299 of the same address as the database; the database as it sees it: the Docker gateway.
     *
     *   docker run -d --name jumphpsql-sshd -p 127.0.0.1:2299:22 -p 172.17.0.1:2299:22 -v $PWD/var/tmp/ssh:/keys:ro alpine sh -c \
     *     'apk add --no-cache openssh && ssh-keygen -A && adduser -D tunnel && echo tunnel:tunnelpw | chpasswd \
     *      && exec /usr/sbin/sshd -D -e -o AllowTcpForwarding=yes -o PasswordAuthentication=yes -o KbdInteractiveAuthentication=yes \
     *         -o AuthorizedKeysFile=/keys/authorized_keys -o StrictModes=no -o PerSourcePenalties=no'
     *
     * @return array<string, mixed>|null null: no SSH server (or no ssh client) here
     */
    public static function sshConfig(): ?array
    {
        static $checked = null;          // probed once: sshd penalises connections that do not log in
        if ($checked !== null) {
            return $checked ?: null;
        }
        $checked = [];
        $db = self::connect();
        if ($db === null || trim((string)shell_exec('command -v ssh')) === '') {
            return null;
        }
        $db->close();
        $host = self::config()['host'];
        $probe = @stream_socket_client("tcp://$host:" . (getenv('JUMPHPSQL_TEST_SSH_PORT') ?: 2299), $errno, $error, 2);
        if ($probe === false) {
            return null;
        }
        fclose($probe);
        return $checked = ['ssh' => true, 'sshHost' => $host, 'sshPort' => (int)(getenv('JUMPHPSQL_TEST_SSH_PORT') ?: 2299), 'sshUser' => 'tunnel',
            'sshAuth' => 'password', 'sshPassword' => 'tunnelpw',
            'host' => getenv('JUMPHPSQL_TEST_DB_FROM_SSH') ?: '172.17.0.1', 'port' => self::config()['port']] + self::config();
    }

    /** var/tmp/ssh: a key without passphrase (key), one with 'secret phrase' (keyp), both in authorized_keys. */
    public static function sshKeys(): string
    {
        $dir = dirname(__DIR__) . '/var/tmp/ssh';
        if (!is_file("$dir/keyp")) {
            @mkdir($dir, 0700, true);
            @unlink("$dir/key");
            $env = '';
            foreach (\App\Db\SshTunnel::passwdWrapper() as $name => $value) {
                $env .= $name . '=' . escapeshellarg($value) . ' ';      // ssh-keygen wants a passwd entry too
            }
            shell_exec($env . 'ssh-keygen -q -t ed25519 -N "" -C jumphpsql-test -f ' . escapeshellarg("$dir/key"));
            shell_exec($env . 'ssh-keygen -q -t ed25519 -N "secret phrase" -C jumphpsql-test-p -f ' . escapeshellarg("$dir/keyp"));
            file_put_contents("$dir/authorized_keys", file_get_contents("$dir/key.pub") . file_get_contents("$dir/keyp.pub"));
        }
        return $dir;
    }

    public static function seed(MysqlConnection $db): void
    {
        $d = self::DATABASE;
        $db->wait($db->query(<<<SQL
            DROP DATABASE IF EXISTS `$d`;
            CREATE DATABASE `$d` CHARACTER SET utf8mb4;
            CREATE TABLE `$d`.customers (id INT AUTO_INCREMENT PRIMARY KEY, name VARCHAR(80) NOT NULL, city VARCHAR(80) NULL, photo BLOB NULL);
            INSERT INTO `$d`.customers (name, city, photo) VALUES ('Ada', 'London', 0x89504E47), ('Grace', 'New York', NULL), ('Linus', NULL, NULL);
            CREATE TABLE `$d`.notes (body TEXT, stars INT);
            INSERT INTO `$d`.notes VALUES ('l''été', 3), ('same', 1), ('same', 1);
            CREATE VIEW `$d`.londoners AS SELECT id, name FROM `$d`.customers WHERE city = 'London';
            SQL));
    }

    /** @return list<list<?string>> */
    public static function rows(MysqlConnection $db, string $sql): array
    {
        return $db->wait($db->first($sql))->rows;
    }
}
