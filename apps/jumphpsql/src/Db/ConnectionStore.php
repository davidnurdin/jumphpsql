<?php

namespace App\Db;

use JumPHP\Save\Storage;

/**
 * The saved connections (var/connections.json): name, server kind, host, port, user, password (when "save password" is
 * ticked), default database, colour, and an optional SSH tunnel (ssh: bool; sshHost, sshPort, sshUser, sshAuth: agent | key |
 * password | auto, sshKey: a key file; its passphrase and the SSH password are saved like the password).
 */
final class ConnectionStore
{
    public const FILE = 'connections.json';
    public const BLANK = ['id' => '', 'name' => '', 'kind' => 'mysql', 'host' => '127.0.0.1', 'port' => 3306, 'user' => 'root',
        'password' => '', 'savePassword' => true, 'database' => '', 'color' => '#3b6ef5',
        'ssh' => false, 'sshHost' => '', 'sshPort' => 22, 'sshUser' => '', 'sshAuth' => 'agent', 'sshKey' => '', 'sshPassphrase' => '',
        'sshPassword' => ''];
    /** the secrets: not saved when "save password" is not ticked */
    public const SECRETS = ['password', 'sshPassword', 'sshPassphrase'];

    public function __construct(private Storage $storage)
    {
    }

    /** @return list<array<string, mixed>> */
    public function all(): array
    {
        $list = $this->storage->readJson(self::FILE, []);
        return array_values(array_map(fn (array $c) => $c + self::BLANK, is_array($list) ? array_filter($list, 'is_array') : []));
    }

    /** @return array<string, mixed>|null */
    public function get(string $id): ?array
    {
        foreach ($this->all() as $c) {
            if ($c['id'] === $id) {
                return $c;
            }
        }
        return null;
    }

    /** Adds or replaces a connection (by id); returns it with its id. @param array<string, mixed> $connection @return array<string, mixed> */
    public function save(array $connection): array
    {
        $connection += self::BLANK;
        if ($connection['id'] === '') {
            $connection['id'] = 'c' . substr(md5(uniqid('', true)), 0, 10);
        }
        $stored = $connection;
        if (!$stored['savePassword']) {
            foreach (self::SECRETS as $secret) {
                $stored[$secret] = '';
            }
        }
        $list = $this->all();
        $found = false;
        foreach ($list as $i => $c) {
            if ($c['id'] === $connection['id']) {
                $list[$i] = $stored;
                $found = true;
            }
        }
        if (!$found) {
            $list[] = $stored;
        }
        usort($list, fn (array $a, array $b) => strcasecmp((string)$a['name'], (string)$b['name']));
        $this->storage->writeJson(self::FILE, $list);
        return $connection;
    }

    public function delete(string $id): void
    {
        $this->storage->writeJson(self::FILE, array_values(array_filter($this->all(), fn (array $c) => $c['id'] !== $id)));
    }
}
