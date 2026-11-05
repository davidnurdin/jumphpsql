<?php

namespace Tests;

use App\Db\ConnectionStore;
use App\Db\MysqlConnection;
use App\Form\ConnectionForm;
use App\Form\MainForm;
use App\Form\TransferForm;
use JumPHP\Kernel\Engine;
use JumPHP\Testing\GameTestCase;

final class MainFormTest extends GameTestCase
{
    private ?MysqlConnection $db = null;

    protected function setUp(): void
    {
        parent::setUp();
        Engine::get()->storage->delete(ConnectionStore::FILE);      // each test starts with no saved connection
    }

    protected function tearDown(): void
    {
        $scene = Engine::get()->scenes->current();
        $scene?->exit();          // the form's connections closed: no transaction left to lock the next test's seed
        $this->db?->close();
        parent::tearDown();
    }

    /** The test server, seeded, or the test is skipped. */
    private function server(): MysqlConnection
    {
        $this->db = TestServer::connect();
        if ($this->db === null) {
            self::markTestSkipped('No MySQL / MariaDB test server on ' . TestServer::config()['port'] . ' (see tests/TestServer.php)');
        }
        TestServer::seed($this->db);
        return $this->db;
    }

    /** Frames until $ready says yes (the server answers between frames). */
    private function until(callable $ready, string $what, int $frames = 300): void
    {
        for ($i = 0; $i < $frames; $i++) {
            if ($ready()) {
                return;
            }
            $this->game->run(1);
            usleep(2000);
        }
        $scene = $this->game->scene();
        self::fail("Waited too long: $what" . (isset($scene->status) ? ' (status: ' . $scene->widget('status')->props['text'] . ')' : ''));
    }

    private function startConnected(): MainForm
    {
        $this->game->start(MainForm::class, ['connect' => TestServer::config() + ['database' => TestServer::DATABASE]]);
        /** @var MainForm $form */
        $form = $this->game->scene();
        $customers = "t\x1ftest\x1f" . TestServer::DATABASE . "\x1fcustomers";
        $this->until(fn () => $form->widget('tree')->node($customers) !== null, 'the tables in the tree');
        return $form;
    }

    private function openTable(MainForm $form, string $table): void
    {
        $form->widget('tree')->select("t\x1ftest\x1f" . TestServer::DATABASE . "\x1f" . $table);
        $this->until(fn () => $form->page() !== null && $form->total() !== null, "the rows of $table");
    }

    public function testANewConnectionIsTypedSavedAndShownInTheTree(): void
    {
        $this->game->start(MainForm::class);
        /** @var MainForm $form */
        $form = $this->game->scene();
        self::assertStringContainsString('New connection', $form->widget('status')->props['text']);
        $form->click($form->widget('newConnection'));
        $this->game->run(2);
        $dialog = $this->game->scene();
        self::assertInstanceOf(ConnectionForm::class, $dialog);
        $dialog->click($dialog->widget('name'));
        $dialog->type('Shop');
        $dialog->widget('host')->text = 'db.example';
        $dialog->widget('port')->text = '3307';
        $this->game->run(1);
        $this->game->shot('connection-dialog');
        $dialog->click($dialog->widget('save'));
        $this->game->run(2);
        self::assertSame($form, $this->game->scene(), 'the dialog closed');
        $saved = Engine::get()->storage->readJson(ConnectionStore::FILE, []);
        self::assertSame('Shop', $saved[0]['name']);
        self::assertSame(3307, $saved[0]['port']);
        self::assertSame('Shop', $form->widget('tree')->props['nodes'][0]['label']);
        self::assertSame('db.example:3307', $form->widget('tree')->props['nodes'][0]['detail']);
    }

    public function testTheConnectionDialogTestsTheServer(): void
    {
        $this->server();
        $this->game->start(MainForm::class);
        $this->game->scene()->click($this->game->scene()->widget('newConnection'));
        $this->game->run(2);
        $dialog = $this->game->scene();
        $c = TestServer::config();
        $dialog->widget('host')->text = $c['host'];
        $dialog->widget('port')->text = (string)$c['port'];
        $dialog->widget('user')->text = $c['user'];
        $dialog->widget('password')->text = 'wrong';
        $dialog->click($dialog->widget('test'));
        $this->until(fn () => str_contains($dialog->widget('result')->props['text'], 'denied'), 'a refused password');
        $dialog->widget('password')->text = $c['password'];
        $dialog->click($dialog->widget('test'));
        $this->until(fn () => str_starts_with($dialog->widget('result')->props['text'], 'Connected'), 'a connection');
        self::assertStringContainsString('MariaDB', $dialog->widget('result')->props['text'] . (getenv('JUMPHPSQL_TEST_PORT') ? ' MariaDB' : ''));
    }

    public function testOpeningAConnectionListsItsDatabasesAndTables(): void
    {
        $this->server();
        $form = $this->startConnected();
        $labels = array_column($form->widget('tree')->props['nodes'], 'label');
        self::assertContains(TestServer::DATABASE, $labels);
        self::assertContains('customers', $labels);
        self::assertContains('londoners', $labels);
        $this->game->run(1);
        $this->game->shot('tree');
    }

    public function testATableShowsItsRowsAndACellEditedIsSaved(): void
    {
        $db = $this->server();
        $form = $this->startConnected();
        $this->openTable($form, 'customers');
        $grid = $form->widget('grid');
        self::assertSame(['id', 'name', 'city', 'photo'], $grid->props['columns']);
        self::assertSame(3, $grid->rowCount());
        self::assertSame('0x89504E47', $grid->value(0, 3), 'binary values show as hex');
        self::assertSame(3, $form->total());
        $grid->selectCell(1, 2);
        $grid->setValue(1, 2, 'Paris');
        self::assertTrue($form->widget('save')->props['enabled']);
        $this->game->run(1);
        $this->game->shot('edited');
        $form->click($form->widget('save'));
        $this->until(fn () => !$grid->hasChanges(), 'the save');
        self::assertSame([['Paris']], TestServer::rows($db, 'SELECT city FROM ' . TestServer::DATABASE . '.customers WHERE id = 2'));
        self::assertSame([['0x89504E47']], TestServer::rows($db, 'SELECT CONCAT(\'0x\', HEX(photo)) FROM ' . TestServer::DATABASE . '.customers WHERE id = 1'),
            'the other columns are not touched');
    }

    public function testARowAddedAndARowDeletedAreSavedTogether(): void
    {
        $db = $this->server();
        $form = $this->startConnected();
        $this->openTable($form, 'customers');
        $this->until(fn () => $form->primaryKey() === ['id'], 'the primary key');
        $grid = $form->widget('grid');
        $grid->selectCell(2, 0);
        $form->click($form->widget('deleteRow'));
        $form->click($form->widget('addRow'));
        $grid->cancelEdit();
        $grid->setValue(3, 1, 'Margaret');
        $grid->setValue(3, 2, null);
        self::assertSame(['DELETE FROM `jumphpsql_test`.`customers` WHERE `id` <=> \'3\' LIMIT 1',
            'INSERT INTO `jumphpsql_test`.`customers` (`name`, `city`) VALUES (\'Margaret\', NULL)'], $form->changeStatements());
        $form->click($form->widget('save'));
        $this->until(fn () => !$grid->hasChanges() && $form->page() !== null && $form->total() === 3 && $grid->rowCount() === 3, 'the save');
        self::assertSame([['Ada'], ['Grace'], ['Margaret']], TestServer::rows($db, 'SELECT name FROM ' . TestServer::DATABASE . '.customers ORDER BY id'));
    }

    public function testInATableWithoutPrimaryKeyOnlyOneOfTwoSameRowsIsDeleted(): void
    {
        $db = $this->server();
        $form = $this->startConnected();
        $this->openTable($form, 'notes');
        $grid = $form->widget('grid');
        $grid->selectCell(2, 0);
        $form->click($form->widget('deleteRow'));
        $form->click($form->widget('save'));
        $this->until(fn () => !$grid->hasChanges() && $form->total() === 2, 'the save');
        self::assertSame([['1']], TestServer::rows($db, 'SELECT COUNT(*) FROM ' . TestServer::DATABASE . ".notes WHERE body = 'same'"));
        self::assertSame("l'été", $grid->value(0, 0), 'utf-8 kept');
    }

    public function testAFailedSaveChangesNothing(): void
    {
        $db = $this->server();
        $form = $this->startConnected();
        $this->openTable($form, 'customers');
        $grid = $form->widget('grid');
        $grid->setValue(0, 2, 'Oxford');
        $grid->setValue(1, 1, null);          // name is NOT NULL: the second statement fails
        $form->click($form->widget('save'));
        $this->until(fn () => str_starts_with($form->widget('status')->props['text'], 'Nothing saved'), 'the error');
        self::assertTrue($grid->hasChanges(), 'the changes wait to be fixed');
        self::assertSame([['London']], TestServer::rows($db, 'SELECT city FROM ' . TestServer::DATABASE . '.customers WHERE id = 1'), 'rolled back');
    }

    public function testTheFilterAndTheSortReadTheRowsAgain(): void
    {
        $this->server();
        $form = $this->startConnected();
        $this->openTable($form, 'customers');
        $grid = $form->widget('grid');
        $form->widget('filter')->text = 'city IS NOT NULL';
        $form->click($form->widget('applyFilter'));
        $this->until(fn () => $form->total() === 2 && $grid->rowCount() === 2, 'the filter');
        $grid->emit('sort', 1);
        $grid->emit('sort', 1);           // twice: descending
        $this->until(fn () => $grid->value(0, 1) === 'Grace', 'the sort');
        self::assertSame('1–2 of 2', $form->widget('pageInfo')->props['text']);
    }

    public function testAQueryShowsItsRowsAndAnErrorIsShown(): void
    {
        $this->server();
        $form = $this->startConnected();
        $form->widget('tree')->select("d\x1ftest\x1f" . TestServer::DATABASE);
        $form->click($form->widget('tabSql'));
        self::assertSame('sql', $form->tab());
        $this->game->run(2);          // the SQL page laid out: its buttons have their place
        $form->widget('editor')->setText("UPDATE customers SET city = 'Rome' WHERE id = 3;\nSELECT name, city FROM customers ORDER BY id;");
        $form->click($form->widget('runAll'));
        $this->until(fn () => count($form->lastResults()) === 2, 'the results');
        self::assertSame(['name', 'city'], $form->widget('resultGrid')->props['columns']);
        self::assertSame('Rome', $form->widget('resultGrid')->value(2, 1));
        self::assertTrue($form->widget('results')->props['visible'], 'two results: a list to choose');
        $this->game->run(1);
        $this->game->shot('query');
        $form->runSql('SELECT nope FROM customers');
        $this->until(fn () => str_contains($form->widget('resultInfo')->props['text'], '1054'), 'the error');
    }

    public function testADumpImportedIntoAnotherDatabaseGivesTheSameRows(): void
    {
        $db = $this->server();
        $form = $this->startConnected();
        $form->widget('tree')->select("d\x1ftest\x1f" . TestServer::DATABASE);
        $file = sys_get_temp_dir() . '/jumphpsql-test-' . getmypid() . '.sql';
        $config = TestServer::config();
        Engine::get()->scenes->push(TransferForm::class, ['mode' => 'dump', 'config' => $config, 'database' => TestServer::DATABASE, 'path' => $file, 'autostart' => true]);
        $this->game->run(1);
        $dialog = $this->game->scene();
        $this->until(fn () => $dialog->job()?->done, 'the dump');
        self::assertNull($dialog->job()->error);
        $this->game->run(1);
        $this->game->shot('dump');
        self::assertStringContainsString('CREATE TABLE `customers`', (string)file_get_contents($file));
        $dialog->click($dialog->widget('start'));      // Done
        $this->game->run(1);
        self::assertStringStartsWith('Dump: ', $form->widget('status')->props['text']);

        $copy = TestServer::DATABASE . '_copy';
        $db->wait($db->query("DROP DATABASE IF EXISTS `$copy`; CREATE DATABASE `$copy`"));
        Engine::get()->scenes->push(TransferForm::class, ['mode' => 'import', 'config' => $config, 'database' => $copy, 'path' => $file, 'autostart' => true]);
        $this->game->run(1);
        $dialog = $this->game->scene();
        $this->until(fn () => $dialog->job()?->done, 'the import');
        self::assertNull($dialog->job()->error, (string)$dialog->job()->error);
        $sql = 'SELECT id, name, city, HEX(photo) FROM %s.customers ORDER BY id';
        self::assertSame(TestServer::rows($db, sprintf($sql, TestServer::DATABASE)), TestServer::rows($db, sprintf($sql, $copy)));
        self::assertSame([['Ada']], TestServer::rows($db, "SELECT name FROM `$copy`.londoners"));
        $db->wait($db->query("DROP DATABASE `$copy`"));
        @unlink($file);
    }

    public function testBackspaceInTheConnectionDialogErasesAndEscapeCloses(): void
    {
        $this->game->start(MainForm::class);
        $form = $this->game->scene();
        $form->click($form->widget('newConnection'));
        $this->game->run(2);
        $dialog = $this->game->scene();
        $dialog->click($dialog->widget('name'));
        $dialog->type('Shopx');
        $keyboard = Engine::get()->input->getKeyboard();
        $keyboard->down(\SDLK_BACKSPACE);
        $this->game->run(1);
        $keyboard->up(\SDLK_BACKSPACE);
        $this->game->run(1);
        self::assertSame($dialog, $this->game->scene(), 'Backspace does not close the dialog');
        self::assertSame('Shop', $dialog->widget('name')->props['text']);
        $keyboard->down(\SDLK_ESCAPE);
        $this->game->run(1);
        $keyboard->up(\SDLK_ESCAPE);
        $this->game->run(1);
        self::assertSame($form, $this->game->scene(), 'Escape closes it');
    }

    public function testTheSshPartOfTheDialogFollowsTheAuthenticationAndIsSaved(): void
    {
        $this->game->start(MainForm::class);
        $form = $this->game->scene();
        $form->click($form->widget('newConnection'));
        $this->game->run(2);
        $dialog = $this->game->scene();
        self::assertFalse($dialog->widget('sshPanel')->props['visible']);
        $dialog->click($dialog->widget('useSsh'));
        $this->game->run(2);
        self::assertTrue($dialog->widget('sshPanel')->props['visible']);
        self::assertFalse($dialog->widget('sshKeyBox')->props['visible'], 'the session key needs no file');
        $dialog->widget('sshHost')->text = 'bastion.example';
        $dialog->widget('sshUser')->text = 'me';
        $dialog->widget('sshAuth')->select(1);
        $this->game->run(2);
        self::assertTrue($dialog->widget('sshKeyBox')->props['visible']);
        $dialog->widget('sshKey')->text = '~/.ssh/id_ed25519';
        $dialog->widget('sshPassphrase')->text = 'phrase';
        $dialog->widget('savePassword')->checked = false;
        $this->game->run(1);
        $this->game->shot('ssh-dialog');
        $dialog->click($dialog->widget('save'));
        $this->game->run(2);
        $saved = Engine::get()->storage->readJson(ConnectionStore::FILE, [])[0];
        self::assertTrue($saved['ssh']);
        self::assertSame(['bastion.example', 'me', 'key', '~/.ssh/id_ed25519', ''], [$saved['sshHost'], $saved['sshUser'], $saved['sshAuth'],
            $saved['sshKey'], $saved['sshPassphrase']], 'the passphrase is not saved: "save the password" is off');
        self::assertStringContainsString('via bastion.example', $form->widget('tree')->props['nodes'][0]['detail']);
        self::assertSame('phrase', $form->config($saved['id'])['sshPassphrase'], 'kept for this session');
    }

    public function testTheWindowWorksThroughAnSshTunnel(): void
    {
        $db = $this->server();
        $ssh = TestServer::sshConfig();
        if ($ssh === null) {
            self::markTestSkipped('No SSH test server (see tests/TestServer.php)');
        }
        $this->game->start(MainForm::class, ['connect' => ['database' => TestServer::DATABASE] + $ssh]);
        /** @var MainForm $form */
        $form = $this->game->scene();
        $customers = "t\x1ftest\x1f" . TestServer::DATABASE . "\x1fcustomers";
        $this->until(fn () => $form->widget('tree')->node($customers) !== null, 'the tables, through the tunnel', 600);
        self::assertStringContainsString('through SSH', $form->widget('status')->props['text']);
        $this->openTable($form, 'customers');
        self::assertSame(3, $form->widget('grid')->rowCount());
        self::assertSame('127.0.0.1', $form->transferConfig('test')['host'], 'a dump goes through the same tunnel');
        $file = sys_get_temp_dir() . '/jumphpsql-ssh-' . getmypid() . '.sql';
        Engine::get()->scenes->push(TransferForm::class, ['mode' => 'dump', 'config' => $form->transferConfig('test'), 'database' => TestServer::DATABASE,
            'path' => $file, 'autostart' => true]);
        $this->game->run(1);
        $dialog = $this->game->scene();
        $this->until(fn () => $dialog->job()?->done, 'the dump');
        self::assertNull($dialog->job()->error);
        self::assertStringContainsString('INSERT INTO `customers`', (string)file_get_contents($file));
        @unlink($file);
        $db->close();
    }
}
