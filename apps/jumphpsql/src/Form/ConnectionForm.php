<?php

namespace App\Form;

use App\Db\ConnectionStore;
use App\Db\Endpoint;
use App\Db\MysqlConnection;
use App\Db\MysqlError;
use App\Db\SshTunnel;
use JumPHP\Kernel\Platform;
use JumPHP\Gui\Button;
use JumPHP\Gui\CheckBox;
use JumPHP\Gui\ComboBox;
use JumPHP\Gui\Container;
use JumPHP\Gui\Form;
use JumPHP\Gui\Label;
use JumPHP\Gui\TextBox;

/**
 * A connection's settings, a dialog: Test tries them (through the SSH tunnel when it is ticked), Save keeps them
 * (var/connections.json) and closes with ['dialog' => 'connection', 'saved' => <the connection>], Delete with
 * ['dialog' => 'connection', 'deleted' => <id>]. Params: ['connection' => <a saved one>] to edit it, nothing for a new one.
 *
 * @property Label $heading
 * @property Container $mysqlBox
 * @property Container $sshBox
 * @property TextBox $name
 * @property Label $hostLabel
 * @property TextBox $host
 * @property TextBox $port
 * @property TextBox $user
 * @property TextBox $password
 * @property CheckBox $savePassword
 * @property TextBox $database
 * @property CheckBox $useSsh
 * @property Container $sshPanel
 * @property TextBox $sshHost
 * @property TextBox $sshPort
 * @property TextBox $sshUser
 * @property ComboBox $sshAuth
 * @property Container $sshKeyBox
 * @property TextBox $sshKey
 * @property TextBox $sshPassphrase
 * @property Container $sshPasswordBox
 * @property TextBox $sshPassword
 * @property Label $sshHint
 * @property Label $result
 * @property Button $test
 * @property Button $delete
 * @property Button $cancel
 * @property Button $save
 */
final class ConnectionForm extends Form
{
    /** @var array<string, mixed> */
    private array $connection = ConnectionStore::BLANK;
    private ?Endpoint $trial = null;

    public function __construct(private ConnectionStore $store)
    {
        parent::__construct();
    }

    protected function loaded(array $params = []): void
    {
        $this->connection = (array)($params['connection'] ?? []) + ConnectionStore::BLANK;
        $c = $this->connection;
        $editing = $c['id'] !== '';
        $this->heading->text = $editing ? 'Edit connection' : 'New connection';
        $this->name->text = (string)$c['name'];
        $this->host->text = (string)$c['host'];
        $this->port->text = (string)$c['port'];
        $this->user->text = (string)$c['user'];
        $this->password->text = (string)$c['password'];
        $this->savePassword->checked = (bool)$c['savePassword'];
        $this->database->text = (string)$c['database'];
        $this->useSsh->checked = (bool)$c['ssh'] && !Platform::isWasm();
        // a browser cannot run ssh: no tunnel option there (the database is reached through the playground's TCP bridge)
        $this->sshBox->visible = !Platform::isWasm();
        $this->sshHost->text = (string)$c['sshHost'];
        $this->sshPort->text = (string)$c['sshPort'];
        $this->sshUser->text = (string)$c['sshUser'];
        $this->sshAuth->selected = max(0, (int)array_search($c['sshAuth'], array_keys(SshTunnel::AUTHS), true));
        $this->sshKey->text = (string)$c['sshKey'];
        $this->sshPassphrase->text = (string)$c['sshPassphrase'];
        $this->sshPassword->text = (string)$c['sshPassword'];
        $this->delete->visible = $editing;
        $this->result->text = '';
        $this->trial = null;
        $this->showSsh();
        $this->setFocus($editing ? $this->password : $this->name);
    }

    private function authKey(): string
    {
        return array_keys(SshTunnel::AUTHS)[$this->sshAuth->selected] ?? 'agent';
    }

    private function showSsh(): void
    {
        $on = $this->useSsh->checked;
        $auth = $this->authKey();
        $this->sshPanel->visible = $on;
        $this->sshKeyBox->visible = $auth === 'key';
        $this->sshPasswordBox->visible = $auth === 'password';
        $this->hostLabel->text = $on ? 'Host (as the SSH server sees it)' : 'Host';
        $agent = getenv('SSH_AUTH_SOCK');
        $this->sshHint->text = match ($auth) {
            'agent' => $agent ? 'Uses the keys of your session (ssh-agent).' : 'No ssh-agent in this session (SSH_AUTH_SOCK is not set).',
            'key' => 'The passphrase is given to ssh only if the key asks for it.',
            'password' => 'The password is given to ssh, never written to a file.',
            default => 'ssh decides: ~/.ssh/config (ProxyJump, IdentityFile...), the default keys, the agent.',
        } . ' The host key is accepted the first time, then checked.';
    }

    public function onUseSshChange(CheckBox $sender, bool $checked): void
    {
        $this->showSsh();
        if ($checked && trim($this->sshHost->text) === '') {
            $this->setFocus($this->sshHost);
        }
    }

    public function onSshAuthChange(ComboBox $sender, int $index, mixed $item = null): void
    {
        $this->showSsh();
    }

    /** @return array<string, mixed> the connection as typed */
    public function typed(): array
    {
        $host = trim($this->host->text) !== '' ? trim($this->host->text) : '127.0.0.1';
        $port = (int)trim($this->port->text) ?: 3306;
        $user = trim($this->user->text) !== '' ? trim($this->user->text) : 'root';
        $ssh = $this->useSsh->checked;
        $sshHost = trim($this->sshHost->text);
        $name = trim($this->name->text) !== '' ? trim($this->name->text)
            : "$user@" . ($ssh && $sshHost !== '' ? $sshHost : $host) . ($port !== 3306 ? ":$port" : '');
        return ['name' => $name, 'host' => $host, 'port' => $port, 'user' => $user, 'password' => $this->password->text,
            'savePassword' => $this->savePassword->checked, 'database' => trim($this->database->text),
            'ssh' => $ssh, 'sshHost' => $sshHost, 'sshPort' => (int)trim($this->sshPort->text) ?: 22, 'sshUser' => trim($this->sshUser->text),
            'sshAuth' => $this->authKey(), 'sshKey' => trim($this->sshKey->text), 'sshPassphrase' => $this->sshPassphrase->text,
            'sshPassword' => $this->sshPassword->text] + $this->connection;
    }

    public function onTestClick(Button $sender): void
    {
        $this->trial?->close();
        $c = $this->typed();
        $this->result->text = ($c['ssh'] ? 'Opening the SSH tunnel to ' . $c['sshHost'] . '…' : 'Connecting to ' . $c['host'] . ':' . $c['port'] . '…');
        $this->result->color = '';
        $trial = $this->trial = new Endpoint(['tcp' => $this->engine->tcp()] + $c + ['timeout' => 8], $this->engine->storage->path('known_hosts'));
        $trial->open()->then(function (MysqlConnection $db) use ($trial): void {
            $this->result->text = 'Connected: server ' . $db->serverVersion . ($trial->usesSsh() ? ', through SSH' : '');
            $this->result->color = '#1f9d55';
            $trial->close();
        }, function (\Throwable $e): void {
            $this->result->text = $e instanceof MysqlError ? $e->describe() : $e->getMessage();
            $this->result->color = '#d64545';
        });
    }

    public function onSaveClick(Button $sender): void
    {
        $this->trial?->close();
        $this->close(['dialog' => 'connection', 'saved' => $this->store->save($this->typed())]);
    }

    public function onDeleteClick(Button $sender): void
    {
        $this->trial?->close();
        $this->store->delete((string)$this->connection['id']);
        $this->close(['dialog' => 'connection', 'deleted' => (string)$this->connection['id']]);
    }

    public function onCancelClick(Button $sender): void
    {
        $this->trial?->close();
        $this->close(null);
    }

    protected function tick(float $dt): void
    {
        $this->trial?->poll();
        // Escape only: the engine's 'back' action is also Backspace, which the text boxes need
        if ($this->engine->input->getKeyboard()->isKeyPressed(\SDLK_ESCAPE)) {
            $this->onCancelClick($this->cancel);
        }
    }
}
