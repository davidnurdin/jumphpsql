<?php

namespace Tests\Site;

use App\Db\MysqlConnection;
use App\Form\MainForm;
use JumPHP\Kernel\Engine;
use JumPHP\Testing\GameTestCase;
use Tests\TestServer;

/**
 * The screenshots of the web site (site/images/shot-*.png), taken from the real window against the throwaway test
 * server (tests/TestServer.php) seeded with a small "shop" database. Re-run with tools/site/shots.sh.
 */
final class SiteShotsTest extends GameTestCase
{
    private const DB = 'shop';

    private ?MysqlConnection $db = null;

    protected function tearDown(): void
    {
        Engine::get()->scenes->current()?->exit();
        $this->db?->close();
        parent::tearDown();
    }

    public function testTheTableData(): void
    {
        $form = $this->connected();
        $this->openTable($form, 'customers');
        $this->keep('data');
    }

    public function testTheStructure(): void
    {
        $form = $this->connected();
        $this->openTable($form, 'orders');
        $form->click($form->widget('tabStructure'));
        $this->game->run(30);
        $this->keep('structure');
    }

    public function testTheSqlEditor(): void
    {
        $form = $this->connected();
        $this->openTable($form, 'customers');      // the connection and the database chosen
        $form->click($form->widget('tabSql'));
        $this->game->run(2);
        $sql = "SELECT c.name, c.city, COUNT(o.id) AS orders, SUM(o.total) AS spent\n"
            . "FROM customers c JOIN orders o ON o.customer_id = c.id\n"
            . "GROUP BY c.id ORDER BY spent DESC;";
        $form->widget('editor')->setText($sql);
        $form->runSql($sql);
        $this->until(fn () => $form->lastResults() !== [], 'the result (' . $form->widget('resultInfo')->props['text'] . ')');
        $this->game->run(10);
        $this->keep('sql');
    }

    public function testTheDarkTheme(): void
    {
        $form = $this->connected();
        $this->openTable($form, 'products');
        $dark = $form->widget('dark');
        $dark->checked = true;
        $form->onDarkChange($dark, true);
        $this->game->run(10);
        $this->keep('dark');
    }

    private function connected(): MainForm
    {
        $this->db = TestServer::connect();
        if ($this->db === null) {
            self::markTestSkipped('No test server (see tests/TestServer.php)');
        }
        $this->seed($this->db);
        $this->game->start(MainForm::class, ['connect' => TestServer::config() + ['database' => self::DB]]);
        /** @var MainForm $form */
        $form = $this->game->scene();
        $this->until(fn () => $form->widget('tree')->node($this->node('customers')) !== null, 'the tables');
        return $form;
    }

    private function openTable(MainForm $form, string $table): void
    {
        $form->widget('tree')->select($this->node($table));
        $this->until(fn () => $form->page() !== null && $form->total() !== null, "the rows of $table");
        $this->game->run(10);
    }

    private function node(string $table): string
    {
        return "t\x1ftest\x1f" . self::DB . "\x1f" . $table;
    }

    private function until(callable $ready, string $what): void
    {
        for ($i = 0; $i < 400; $i++) {
            if ($ready()) {
                return;
            }
            $this->game->run(1);
            usleep(2000);
        }
        self::fail("Waited too long: $what");
    }

    private function keep(string $name): void
    {
        $file = $this->game->shot('site-' . $name);
        $out = dirname(__DIR__, 2) . '/var/site-shots';
        @mkdir($out, 0777, true);
        copy($file, "$out/$name.png");
        self::assertFileExists("$out/$name.png");
    }

    private function seed(MysqlConnection $db): void
    {
        $d = self::DB;
        $db->wait($db->query(<<<SQL
            DROP DATABASE IF EXISTS `$d`;
            CREATE DATABASE `$d` CHARACTER SET utf8mb4;
            CREATE TABLE `$d`.customers (id INT AUTO_INCREMENT PRIMARY KEY, name VARCHAR(80) NOT NULL, email VARCHAR(120) NOT NULL,
                city VARCHAR(80) NULL, country CHAR(2) NOT NULL, created_at DATETIME NOT NULL);
            INSERT INTO `$d`.customers (name, email, city, country, created_at) VALUES
                ('Ada Lovelace', 'ada@example.org', 'London', 'GB', '2026-01-12 09:14:00'),
                ('Grace Hopper', 'grace@example.org', 'New York', 'US', '2026-01-15 17:02:00'),
                ('Alan Turing', 'alan@example.org', 'Manchester', 'GB', '2026-02-03 11:40:00'),
                ('Margaret Hamilton', 'margaret@example.org', 'Boston', 'US', '2026-02-20 08:31:00'),
                ('Linus Torvalds', 'linus@example.org', 'Portland', 'US', '2026-03-01 22:05:00'),
                ('Rasmus Lerdorf', 'rasmus@example.org', NULL, 'DK', '2026-03-09 14:27:00'),
                ('Barbara Liskov', 'barbara@example.org', 'Cambridge', 'US', '2026-04-11 10:00:00'),
                ('Edsger Dijkstra', 'edsger@example.org', 'Eindhoven', 'NL', '2026-04-28 16:45:00'),
                ('Frances Allen', 'frances@example.org', 'Peru', 'US', '2026-05-06 13:12:00'),
                ('Donald Knuth', 'don@example.org', 'Stanford', 'US', '2026-05-19 07:58:00'),
                ('Sophie Wilson', 'sophie@example.org', 'Cambridge', 'GB', '2026-06-02 19:21:00'),
                ('Monty Widenius', 'monty@example.org', 'Helsinki', 'FI', '2026-06-24 12:00:00');
            CREATE TABLE `$d`.products (id INT AUTO_INCREMENT PRIMARY KEY, sku VARCHAR(20) NOT NULL UNIQUE, name VARCHAR(120) NOT NULL,
                price DECIMAL(8,2) NOT NULL, stock INT NOT NULL DEFAULT 0);
            INSERT INTO `$d`.products (sku, name, price, stock) VALUES
                ('KB-101', 'Mechanical keyboard', 89.90, 42), ('MS-220', 'Wireless mouse', 24.50, 130),
                ('MN-270', '27" monitor', 279.00, 12), ('HD-512', 'SSD 512 GB', 59.99, 76),
                ('CB-USB', 'USB-C cable', 9.90, 400), ('HS-300', 'Headset', 49.00, 0),
                ('WC-080', 'Webcam', 39.90, 18), ('DK-010', 'Docking station', 149.00, 7);
            CREATE TABLE `$d`.orders (id INT AUTO_INCREMENT PRIMARY KEY, customer_id INT NOT NULL, total DECIMAL(10,2) NOT NULL,
                status ENUM('new','paid','shipped') NOT NULL DEFAULT 'new', ordered_at DATETIME NOT NULL,
                KEY customer (customer_id), FOREIGN KEY (customer_id) REFERENCES `$d`.customers(id));
            INSERT INTO `$d`.orders (customer_id, total, status, ordered_at) VALUES
                (1, 369.00, 'shipped', '2026-02-01 10:00:00'), (2, 24.50, 'shipped', '2026-02-11 12:00:00'),
                (1, 59.99, 'paid', '2026-03-03 09:30:00'), (3, 149.00, 'shipped', '2026-03-14 15:10:00'),
                (5, 89.90, 'new', '2026-04-02 18:40:00'), (4, 328.90, 'paid', '2026-04-20 11:05:00'),
                (6, 9.90, 'shipped', '2026-05-01 08:00:00'), (2, 279.00, 'paid', '2026-05-22 14:00:00'),
                (8, 49.00, 'new', '2026-06-08 16:30:00'), (10, 188.90, 'shipped', '2026-06-30 10:15:00'),
                (12, 59.99, 'paid', '2026-07-04 09:00:00'), (7, 24.50, 'new', '2026-07-19 20:20:00');
            CREATE VIEW `$d`.best_customers AS SELECT customer_id, SUM(total) AS spent FROM `$d`.orders GROUP BY customer_id;
            SQL));
    }
}
