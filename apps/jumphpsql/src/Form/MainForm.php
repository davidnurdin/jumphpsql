<?php

namespace App\Form;

use App\Db\ConnectionStore;
use App\Db\Endpoint;
use App\Db\MysqlConnection;
use App\Db\MysqlError;
use App\Db\Promise;
use App\Db\ResultSet;
use App\Db\Sql;
use App\Gui\DataGrid;
use App\Gui\SqlEditor;
use App\Gui\TreeView;
use JumPHP\Gui\Button;
use JumPHP\Gui\CheckBox;
use JumPHP\Gui\ComboBox;
use JumPHP\Gui\Container;
use JumPHP\Gui\Form;
use JumPHP\Gui\Image;
use JumPHP\Gui\Label;
use JumPHP\Gui\TextBox;
use JumPHP\Gui\Theme;

/**
 * The main window: the saved connections on the left (a tree: connection > databases > tables, opened as they load), and
 * on the right the selected table's rows (filter, sort, pages, edited in place then saved in one transaction), its
 * structure, or a SQL editor and the rows of its queries. Dumps and imports run in TransferForm, connections are edited in
 * ConnectionForm. Every query goes through MysqlConnection: answers come back between the frames, nothing waits.
 *
 * @property Image $logo
 * @property Label $heading
 * @property Button $newConnection
 * @property Button $editConnection
 * @property Button $connect
 * @property Button $refresh
 * @property Button $dump
 * @property Button $import
 * @property CheckBox $dark
 * @property TreeView $tree
 * @property Button $tabData
 * @property Button $tabStructure
 * @property Button $tabSql
 * @property Label $where
 * @property Container $dataPage
 * @property TextBox $filter
 * @property Button $applyFilter
 * @property Button $prevPage
 * @property Label $pageInfo
 * @property Button $nextPage
 * @property DataGrid $grid
 * @property Button $addRow
 * @property Button $deleteRow
 * @property Label $changesInfo
 * @property Button $discard
 * @property Button $save
 * @property Container $structurePage
 * @property DataGrid $columnsGrid
 * @property SqlEditor $createSql
 * @property Container $sqlPage
 * @property SqlEditor $editor
 * @property Button $run
 * @property Button $runAll
 * @property ComboBox $results
 * @property Label $resultInfo
 * @property DataGrid $resultGrid
 * @property Label $status
 */
final class MainForm extends Form
{
    public const PAGE_SIZE = 200;
    public const MAX_RESULT_ROWS = 5000;
    private const SEP = "\x1f";

    /** @var list<array<string, mixed>> the saved connections */
    private array $connections = [];
    /** @var array<string, Endpoint> connection id => its open connection (and its SSH tunnel) */
    private array $live = [];
    /** @var array<string, array<string, string>> connection id => the secrets typed this session (not saved) */
    private array $secrets = [];
    /** @var array<string, bool> tree node id => open */
    private array $open = [];
    /** @var array<string, list<string>> connection id => its databases */
    private array $databases = [];
    /** @var array<string, array<string, list<array{name: string, type: string, rows: ?string, size: ?string}>>> */
    private array $tables = [];
    /** @var array<string, true> nodes whose children are loading */
    private array $loading = [];

    // what is selected
    private string $conn = '';
    private string $database = '';
    private string $table = '';
    private string $tableType = '';
    private string $tab = 'data';

    // the table open in the data page
    private int $offset = 0;
    private ?int $total = null;
    private bool $hasMore = false;
    private string $whereSql = '';
    private int $sortColumn = -1;
    private bool $sortDesc = false;
    /** @var list<string> */
    private array $primaryKey = [];
    private ?ResultSet $page = null;
    /** a counter: answers of an older request are dropped */
    private int $generation = 0;
    private bool $saving = false;

    /** @var list<ResultSet> the results of the last SQL run */
    private array $lastResults = [];

    public function __construct(private ConnectionStore $store)
    {
        parent::__construct();
    }

    protected function loaded(array $params = []): void
    {
        foreach ($this->live as $endpoint) {
            $endpoint->close();
        }
        $this->live = $this->databases = $this->tables = $this->loading = $this->open = $this->lastResults = [];
        $this->conn = $this->database = $this->table = $this->tableType = '';
        $this->page = null;
        $this->generation++;
        $this->connections = $this->store->all();
        $this->editor->setText((string)($this->engine->storage->read('editor.sql') ?? ''));
        $this->setTab('data');
        $this->buildTree();
        $this->updateButtons();
        $this->status->text = $this->connections === [] ? 'Add a connection to a MySQL or MariaDB server: New connection'
            : 'Open a connection on the left (double click, or the arrow)';
        if (!empty($params['demo'])) {
            // the smoke test (bin/jumphp test): the SQL page with a query typed, a connection made if a server answers
            $this->editor->setText("SELECT VERSION(), NOW();\nSHOW DATABASES;");
            $this->setTab('sql');
        }
        if (isset($params['connect']) && is_array($params['connect'])) {
            // tests: a connection given (not saved), opened at once
            $c = $params['connect'] + ConnectionStore::BLANK + ['id' => 'test'];
            $c['id'] = $c['id'] !== '' ? $c['id'] : 'test';
            $this->connections = [$c];
            $this->secrets[$c['id']] = array_intersect_key($c, array_flip(ConnectionStore::SECRETS));
            $this->buildTree();
            $this->tree->select($this->nodeId('c', $c['id']));
            $this->tree->toggle($this->nodeId('c', $c['id']), true);
        }
    }

    public function exit(): void
    {
        foreach ($this->live as $endpoint) {
            $endpoint->close();
        }
        $this->live = [];
    }

    protected function tick(float $dt): void
    {
        foreach ($this->live as $endpoint) {
            $endpoint->poll();
        }
    }

    // connections -----------------------------------------------------------------------------------------------------

    /** @return array<string, mixed>|null */
    private function connection(string $id): ?array
    {
        foreach ($this->connections as $c) {
            if ($c['id'] === $id) {
                return $c;
            }
        }
        return null;
    }

    /** @return array<string, mixed> a saved connection, with the secrets typed this session when they are not saved */
    public function config(string $id): array
    {
        $c = $this->connection($id) ?? ConnectionStore::BLANK;
        foreach ($this->secrets[$id] ?? [] as $key => $value) {
            if ((string)($c[$key] ?? '') === '') {
                $c[$key] = $value;
            }
        }
        return $c;
    }

    /** @return array<string, mixed> what another connection to the same server uses (dump, import): through its SSH tunnel */
    public function transferConfig(string $id): array
    {
        $endpoint = $this->live[$id] ?? null;
        return $endpoint !== null && $endpoint->isReady() ? $endpoint->mysqlConfig() : $this->config($id);
    }

    private function liveDb(string $id): ?MysqlConnection
    {
        $db = ($this->live[$id] ?? null)?->db;
        return $db !== null && $db->state !== 'closed' ? $db : null;
    }

    public function db(): ?MysqlConnection
    {
        return $this->liveDb($this->conn);
    }

    private function openConnection(string $id): Promise
    {
        $endpoint = $this->live[$id] ?? null;
        if ($endpoint !== null) {
            return $endpoint->open();
        }
        $c = $this->config($id);
        $endpoint = $this->live[$id] = new Endpoint($c, $this->engine->storage->path('known_hosts'));
        $endpoint->onClose = function (string $why) use ($id): void {
            unset($this->live[$id]);
            $this->status->text = $this->connection($id)['name'] . ': ' . $why;
            $this->buildTree();
            $this->updateButtons();
        };
        $this->loading[$this->nodeId('c', $id)] = true;
        $this->status->text = $endpoint->usesSsh() ? 'Opening the SSH tunnel to ' . $c['sshHost'] . '…' : 'Connecting to ' . $c['host'] . ':' . $c['port'] . '…';
        $this->buildTree();
        $promise = $endpoint->open();
        $promise->then(function (MysqlConnection $db) use ($id, $endpoint): void {
            unset($this->loading[$this->nodeId('c', $id)]);
            $this->status->text = $this->connection($id)['name'] . ': connected, ' . $db->serverVersion . ($endpoint->usesSsh() ? ' (through SSH)' : '');
            $this->updateButtons();
            $this->loadDatabases($id);
        }, function (\Throwable $e) use ($id): void {
            ($this->live[$id] ?? null)?->close();
            unset($this->loading[$this->nodeId('c', $id)], $this->live[$id]);
            $this->open[$this->nodeId('c', $id)] = false;
            $this->status->text = $this->connection($id)['name'] . ': ' . $this->describe($e);
            $this->buildTree();
            $this->updateButtons();
        });
        return $promise;
    }

    private function disconnect(string $id): void
    {
        $endpoint = $this->live[$id] ?? null;
        unset($this->live[$id]);
        $endpoint?->close();
        unset($this->databases[$id], $this->tables[$id]);
        $this->open[$this->nodeId('c', $id)] = false;
        if ($this->conn === $id) {
            $this->closeTable();
        }
        $this->status->text = ($this->connection($id)['name'] ?? '') . ': disconnected';
        $this->buildTree();
        $this->updateButtons();
    }

    private function loadDatabases(string $id): void
    {
        $db = $this->liveDb($id);
        if ($db === null) {
            return;
        }
        $this->loading[$this->nodeId('c', $id)] = true;
        $db->first('SHOW DATABASES')->then(function (ResultSet $r) use ($id): void {
            unset($this->loading[$this->nodeId('c', $id)]);
            $this->databases[$id] = array_map('strval', $r->column());
            $this->open[$this->nodeId('c', $id)] = true;
            // the connection's default database opens by itself
            $default = (string)($this->connection($id)['database'] ?? '');
            if ($default !== '' && in_array($default, $this->databases[$id], true) && !isset($this->open[$this->nodeId('d', $id, $default)])) {
                $this->open[$this->nodeId('d', $id, $default)] = true;
                $this->loadTables($id, $default);
            }
            foreach ($this->tables[$id] ?? [] as $database => $_) {
                if (in_array($database, $this->databases[$id], true)) {
                    $this->loadTables($id, $database);
                } else {
                    unset($this->tables[$id][$database]);
                }
            }
            $this->buildTree();
        }, function (\Throwable $e) use ($id): void {
            unset($this->loading[$this->nodeId('c', $id)]);
            $this->status->text = $this->describe($e);
            $this->buildTree();
        });
    }

    private function loadTables(string $id, string $database): void
    {
        $db = $this->liveDb($id);
        if ($db === null) {
            return;
        }
        $node = $this->nodeId('d', $id, $database);
        $this->loading[$node] = true;
        $db->first('SELECT TABLE_NAME, TABLE_TYPE, TABLE_ROWS, DATA_LENGTH + INDEX_LENGTH FROM information_schema.TABLES WHERE TABLE_SCHEMA = '
            . Sql::value($database) . ' ORDER BY TABLE_NAME')->then(function (ResultSet $r) use ($id, $database, $node): void {
                unset($this->loading[$node]);
                $this->tables[$id][$database] = array_map(fn (array $row) => ['name' => (string)$row[0], 'type' => (string)$row[1],
                    'rows' => $row[2], 'size' => $row[3]], $r->rows);
                $this->buildTree();
            }, function (\Throwable $e) use ($node): void {
                unset($this->loading[$node]);
                $this->status->text = $this->describe($e);
                $this->buildTree();
            });
    }

    // the tree --------------------------------------------------------------------------------------------------------

    private function nodeId(string $kind, string ...$parts): string
    {
        return $kind . self::SEP . implode(self::SEP, $parts);
    }

    public function buildTree(): void
    {
        $nodes = [];
        foreach ($this->connections as $c) {
            $id = (string)$c['id'];
            $cid = $this->nodeId('c', $id);
            $live = isset($this->live[$id]) && $this->live[$id]->isReady();
            $via = !empty($c['ssh']) ? ' via ' . $c['sshHost'] : '';
            $open = !empty($this->open[$cid]) && isset($this->databases[$id]);
            $nodes[] = ['id' => $cid, 'label' => (string)$c['name'], 'depth' => 0, 'icon' => $live ? 'server' : 'server-off',
                'color' => $c['color'], 'children' => true, 'open' => $open, 'conn' => $id,
                'detail' => isset($this->loading[$cid]) ? '…' : $c['host'] . ($c['port'] != 3306 ? ':' . $c['port'] : '') . $via];
            if (!$open) {
                continue;
            }
            foreach ($this->databases[$id] as $database) {
                $did = $this->nodeId('d', $id, $database);
                $tables = $this->tables[$id][$database] ?? null;
                $dbOpen = !empty($this->open[$did]) && $tables !== null;
                $nodes[] = ['id' => $did, 'label' => $database, 'depth' => 1, 'icon' => 'database', 'children' => true, 'open' => $dbOpen,
                    'conn' => $id, 'db' => $database,
                    'detail' => isset($this->loading[$did]) ? '…' : ($tables !== null ? (string)count($tables) : '')];
                if (!$dbOpen) {
                    continue;
                }
                foreach ($tables as $t) {
                    $view = $t['type'] === 'VIEW';
                    $nodes[] = ['id' => $this->nodeId('t', $id, $database, $t['name']), 'label' => $t['name'], 'depth' => 2,
                        'icon' => $view ? 'view' : 'table', 'children' => false, 'conn' => $id, 'db' => $database, 'table' => $t['name'],
                        'type' => $t['type'], 'detail' => $view ? 'view' : ($t['rows'] !== null ? '~' . $this->count((int)$t['rows']) : '')];
                }
            }
        }
        $this->tree->nodes = $nodes;
    }

    private function count(int $n): string
    {
        return match (true) {
            $n >= 1_000_000 => round($n / 1_000_000, 1) . 'M',
            $n >= 10_000 => round($n / 1000) . 'k',
            default => (string)$n,
        };
    }

    /** @param array<string, mixed> $node */
    public function onTreeSelect(TreeView $sender, array $node): void
    {
        $conn = (string)$node['conn'];
        $database = (string)($node['db'] ?? '');
        $table = (string)($node['table'] ?? '');
        if ($conn !== $this->conn || $database !== $this->database || $table !== $this->table) {
            if ($table !== '') {
                $this->openTable($conn, $database, $table, (string)($node['type'] ?? 'BASE TABLE'));
            } else {
                $this->closeTable();
            }
        }
        $this->conn = $conn;
        $this->database = $database;
        $this->updateButtons();
    }

    /** @param array<string, mixed> $node */
    public function onTreeToggle(TreeView $sender, array $node, bool $open): void
    {
        $id = (string)$node['id'];
        $this->open[$id] = $open;
        if ($open) {
            $conn = (string)$node['conn'];
            if (!isset($node['db'])) {
                isset($this->live[$conn]) && $this->live[$conn]->isReady() ? $this->loadDatabases($conn) : $this->openConnection($conn);
            } elseif (!isset($this->tables[$conn][(string)$node['db']])) {
                $this->loadTables($conn, (string)$node['db']);
            }
        }
        $this->buildTree();
    }

    /** @param array<string, mixed> $node */
    public function onTreeActivate(TreeView $sender, array $node): void
    {
        if (isset($node['table'])) {
            $this->setTab('data');
        }
    }

    // the table's rows ------------------------------------------------------------------------------------------------

    private function closeTable(): void
    {
        $this->generation++;
        $this->table = $this->tableType = '';
        $this->page = null;
        $this->primaryKey = [];
        $this->grid->setData([], []);
        $this->grid->editable = false;
        $this->columnsGrid->setData([], []);
        $this->createSql->setText('');
        $this->pageInfo->text = '';
        $this->where->text = $this->database !== '' ? $this->database : 'No table open';
        $this->updateButtons();
    }

    public function openTable(string $conn, string $database, string $table, string $type = 'BASE TABLE'): void
    {
        $this->conn = $conn;
        $this->database = $database;
        $this->table = $table;
        $this->tableType = $type;
        $this->offset = 0;
        $this->total = null;
        $this->sortColumn = -1;
        $this->sortDesc = false;
        $this->whereSql = '';
        $this->filter->text = '';
        $this->primaryKey = [];
        $this->page = null;
        $this->grid->setData([], []);
        $this->grid->sortColumn = -1;
        $this->grid->editable = $type !== 'VIEW';
        $this->grid->emptyText = 'Loading…';
        $this->where->text = $database . '.' . $table;
        $this->loadStructure();
        $this->loadPage(true);
        $this->updateButtons();
    }

    private function tableKey(): string
    {
        return $this->conn . self::SEP . $this->database . self::SEP . $this->table;
    }

    private function qualified(): string
    {
        return Sql::table($this->database, $this->table);
    }

    private function loadStructure(): void
    {
        $db = $this->db();
        if ($db === null) {
            return;
        }
        // the table, not the generation: loadPage() counts its own requests just after
        $table = $this->tableKey();
        $db->first('SHOW FULL COLUMNS FROM ' . $this->qualified())->then(function (ResultSet $r) use ($table): void {
            if ($table !== $this->tableKey()) {
                return;
            }
            $keep = ['Field', 'Type', 'Null', 'Key', 'Default', 'Extra', 'Collation', 'Comment'];
            $index = array_flip($r->names());
            $this->columnsGrid->setData($keep, array_map(fn (array $row) => array_map(fn (string $k) => $row[$index[$k] ?? -1] ?? null, $keep), $r->rows));
            $this->primaryKey = [];
            foreach ($r->rows as $row) {
                if (($row[$index['Key'] ?? -1] ?? '') === 'PRI') {
                    $this->primaryKey[] = (string)$row[$index['Field']];
                }
            }
        }, fn (\Throwable $e) => $this->status->text = $this->describe($e));
        $db->first('SHOW CREATE TABLE ' . $this->qualified())->then(function (ResultSet $r) use ($table): void {
            if ($table === $this->tableKey()) {
                $this->createSql->setText((string)($r->rows[0][1] ?? '') . ';');
            }
        }, fn () => null);
    }

    /** The current page of the open table (with its count when $count). */
    public function loadPage(bool $count = false): void
    {
        $db = $this->db();
        if ($db === null || $this->table === '') {
            return;
        }
        $generation = ++$this->generation;
        $where = $this->whereSql !== '' ? ' WHERE ' . $this->whereSql : '';
        $order = '';
        if ($this->sortColumn >= 0 && $this->page !== null && isset($this->page->columns[$this->sortColumn])) {
            $order = ' ORDER BY ' . Sql::ident($this->page->columns[$this->sortColumn]['name']) . ($this->sortDesc ? ' DESC' : '');
        }
        $this->status->text = 'Loading ' . $this->table . '…';
        $sql = 'SELECT * FROM ' . $this->qualified() . $where . $order . ' LIMIT ' . (self::PAGE_SIZE + 1) . ' OFFSET ' . $this->offset;
        $db->first($sql)->then(function (ResultSet $r) use ($generation): void {
            if ($generation !== $this->generation) {
                return;
            }
            $this->hasMore = count($r->rows) > self::PAGE_SIZE;
            $r->rows = array_slice($r->rows, 0, self::PAGE_SIZE);
            $this->page = $r;
            $numeric = [];
            foreach ($r->columns as $i => $_) {
                $numeric[] = $r->isNumeric($i);
            }
            $this->grid->emptyText = $this->whereSql !== '' ? 'No row matches the filter' : 'The table is empty';
            $this->grid->setData($r->names(), array_map(fn (array $row) => $this->shown($r, $row), $r->rows), $numeric);
            $this->grid->sortColumn = $this->sortColumn;
            $this->grid->sortDesc = $this->sortDesc;
            $this->status->text = $this->table . ': ' . count($r->rows) . ' row(s) in ' . sprintf('%.3f s', $r->time)
                . ($this->tableType !== 'VIEW' && $this->primaryKey === [] ? ' — no primary key: rows are found by all their values' : '');
            $this->updatePageInfo();
            $this->updateButtons();
        }, function (\Throwable $e) use ($generation): void {
            if ($generation !== $this->generation) {
                return;
            }
            $this->grid->emptyText = 'Error';
            $this->grid->setData([], []);
            $this->status->text = $this->describe($e);
            $this->updateButtons();
        });
        if ($count) {
            $db->first('SELECT COUNT(*) FROM ' . $this->qualified() . $where)->then(function (ResultSet $r) use ($generation): void {
                if ($generation === $this->generation) {
                    $this->total = (int)($r->rows[0][0] ?? 0);
                    $this->updatePageInfo();
                }
            }, fn () => null);
        }
    }

    /**
     * A row as the grid shows it: binary values as 0x… (cut), the others as they are.
     *
     * @param list<?string> $row
     * @return list<?string>
     */
    private function shown(ResultSet $r, array $row): array
    {
        foreach ($row as $i => $value) {
            if ($value !== null && $r->isBinary($i)) {
                $row[$i] = '0x' . strtoupper(bin2hex(substr($value, 0, 64))) . (strlen($value) > 64 ? '…' : '');
            }
        }
        return $row;
    }

    private function updatePageInfo(): void
    {
        $shown = $this->page !== null ? count($this->page->rows) : 0;
        $this->pageInfo->text = $shown === 0 ? ($this->total !== null ? '0 of ' . number_format($this->total) : '')
            : number_format($this->offset + 1) . '–' . number_format($this->offset + $shown) . ($this->total !== null ? ' of ' . number_format($this->total) : '');
        $this->prevPage->enabled = $this->offset > 0;
        $this->nextPage->enabled = $this->hasMore;
    }

    /** Paging, sorting and filtering read the rows again: changes not saved would be lost. */
    private function busyWithChanges(): bool
    {
        if ($this->grid->hasChanges()) {
            $this->status->text = 'Save or discard the changes first';
            return true;
        }
        return false;
    }

    public function onFilterSubmit(TextBox $sender, string $text): void
    {
        $this->onApplyFilterClick($this->applyFilter);
    }

    public function onApplyFilterClick(Button $sender): void
    {
        if ($this->table === '' || $this->busyWithChanges()) {
            return;
        }
        $where = trim($this->filter->text);
        $this->whereSql = (string)preg_replace('/^WHERE\s+/i', '', $where);
        $this->offset = 0;
        $this->loadPage(true);
    }

    public function onPrevPageClick(Button $sender): void
    {
        if ($this->busyWithChanges()) {
            return;
        }
        $this->offset = max(0, $this->offset - self::PAGE_SIZE);
        $this->loadPage();
    }

    public function onNextPageClick(Button $sender): void
    {
        if (!$this->hasMore || $this->busyWithChanges()) {
            return;
        }
        $this->offset += self::PAGE_SIZE;
        $this->loadPage();
    }

    public function onGridSort(DataGrid $sender, int $col): void
    {
        if ($this->busyWithChanges()) {
            return;
        }
        if ($this->sortColumn === $col) {
            if ($this->sortDesc) {
                $this->sortColumn = -1;      // a third click: back to the table's order
                $this->sortDesc = false;
            } else {
                $this->sortDesc = true;
            }
        } else {
            $this->sortColumn = $col;
            $this->sortDesc = false;
        }
        $this->offset = 0;
        $this->loadPage();
    }

    public function onGridEdit(DataGrid $sender, int $row, int $col, ?string $value): void
    {
        $this->updateButtons();
    }

    public function onAddRowClick(Button $sender): void
    {
        if ($this->page === null) {
            return;
        }
        $this->grid->addRow();
        $this->setFocus($this->grid);
        $this->grid->beginEdit('');
        $this->updateButtons();
    }

    public function onDeleteRowClick(Button $sender): void
    {
        if ($this->grid->selRow < 0) {
            return;
        }
        $this->grid->toggleDeleted($this->grid->selRow);
        $this->updateButtons();
    }

    public function onDiscardClick(Button $sender): void
    {
        $this->grid->discardChanges();
        $this->updateButtons();
    }

    /** @return list<string> the statements that save the grid's changes */
    public function changeStatements(): array
    {
        $r = $this->page;
        if ($r === null) {
            return [];
        }
        $this->grid->commitEdit();
        $names = $r->names();
        $table = $this->qualified();
        $out = [];
        foreach ($this->grid->rowStatus as $row => $status) {
            if ($status === 'deleted') {
                $out[] = 'DELETE FROM ' . $table . ' WHERE ' . $this->rowKey($row) . ' LIMIT 1';
            }
        }
        foreach ($this->grid->changes as $row => $changes) {
            $status = $this->grid->rowStatus[$row] ?? '';
            if ($status === 'deleted') {
                continue;
            }
            if ($status === 'new') {
                $cols = array_keys($changes);
                $out[] = 'INSERT INTO ' . $table . ' (' . implode(', ', array_map(fn (int $c) => Sql::ident($names[$c]), $cols)) . ') VALUES ('
                    . implode(', ', array_map(fn (int $c) => $this->literal($changes[$c], $c), $cols)) . ')';
                continue;
            }
            if ($changes === []) {
                continue;
            }
            $sets = [];
            foreach ($changes as $c => $value) {
                $sets[] = Sql::ident($names[$c]) . ' = ' . $this->literal($value, $c);
            }
            $out[] = 'UPDATE ' . $table . ' SET ' . implode(', ', $sets) . ' WHERE ' . $this->rowKey($row) . ' LIMIT 1';
        }
        return $out;
    }

    /** A value typed in a cell as SQL: binary columns take 0x… as bytes. */
    private function literal(?string $value, int $col): string
    {
        if ($value !== null && $this->page?->isBinary($col) && preg_match('/^0x([0-9a-f]*)$/i', $value, $m) && strlen($m[1]) % 2 === 0) {
            return $m[1] === '' ? "''" : '0x' . $m[1];
        }
        return Sql::value($value);
    }

    /** WHERE of a row as it was read: its primary key, else all its columns (NULL-safe). */
    private function rowKey(int $row): string
    {
        $r = $this->page;
        $names = $r->names();
        $original = $r->rows[$row] ?? [];
        $cols = $this->primaryKey !== [] && array_diff($this->primaryKey, $names) === []
            ? array_map(fn (string $n) => (int)array_search($n, $names, true), $this->primaryKey)
            : array_keys($names);
        return implode(' AND ', array_map(fn (int $c) => Sql::ident($names[$c]) . ' <=> ' . Sql::value($original[$c] ?? null, $r->isBinary($c)), $cols));
    }

    public function onSaveClick(Button $sender): void
    {
        $db = $this->db();
        $statements = $this->changeStatements();
        if ($db === null || $statements === [] || $this->saving) {
            return;
        }
        $this->saving = true;
        $this->status->text = 'Saving ' . count($statements) . ' change(s)…';
        $this->updateButtons();
        $db->query('START TRANSACTION');
        $this->chain($db, $statements, 0, function () use ($db, $statements): void {
            $db->query('COMMIT')->then(function () use ($statements): void {
                $this->saving = false;
                $this->grid->discardChanges();
                $this->status->text = count($statements) . ' change(s) saved';
                $this->loadPage(true);
            }, fn (\Throwable $e) => $this->saveFailed($e, ''));
        }, function (\Throwable $e, string $statement) use ($db): void {
            $db->query('ROLLBACK');
            $this->saveFailed($e, $statement);
        });
    }

    /** Runs statements one after the other, stopping at the first error. @param list<string> $statements */
    private function chain(MysqlConnection $db, array $statements, int $at, callable $done, callable $failed): void
    {
        if ($at >= count($statements)) {
            $done();
            return;
        }
        $db->query($statements[$at])->then(fn () => $this->chain($db, $statements, $at + 1, $done, $failed),
            fn (\Throwable $e) => $failed($e, $statements[$at]));
    }

    private function saveFailed(\Throwable $e, string $statement): void
    {
        $this->saving = false;
        $this->status->text = 'Nothing saved: ' . $this->describe($e) . ($statement !== '' ? ' — ' . mb_strimwidth($statement, 0, 160, '…') : '');
        $this->updateButtons();
    }

    // the SQL page ----------------------------------------------------------------------------------------------------

    public function onEditorRun(SqlEditor $sender, string $sql): void
    {
        $this->runSql($sql);
    }

    public function onEditorRunAll(SqlEditor $sender, string $sql): void
    {
        $this->runSql($sql);
    }

    public function onRunClick(Button $sender): void
    {
        $selected = $this->editor->selectedText();
        $this->runSql($selected !== '' ? $selected : Sql::statementAt($this->editor->text(), $this->editor->offset()));
    }

    public function onRunAllClick(Button $sender): void
    {
        $this->runSql($this->editor->text());
    }

    public function runSql(string $sql): void
    {
        $this->engine->storage->write('editor.sql', $this->editor->text());
        $sql = trim($sql);
        if ($sql === '') {
            return;
        }
        $db = $this->db();
        if ($db === null) {
            $this->resultInfo->text = 'Open a connection first (on the left)';
            $this->resultInfo->color = '#d64545';
            return;
        }
        if ($this->database !== '' && $db->database !== $this->database) {
            $db->useDatabase($this->database)->then(null, fn () => null);
        }
        $this->resultInfo->text = 'Running…';
        $this->resultInfo->color = '';
        $this->run->enabled = $this->runAll->enabled = false;
        $db->query($sql, self::MAX_RESULT_ROWS)->then(function (array $results) use ($sql): void {
            $this->run->enabled = $this->runAll->enabled = true;
            $this->lastResults = $results;
            $this->results->items = array_map(fn (ResultSet $r, int $i) => ($i + 1) . '. ' . $r->summary(), $results, array_keys($results));
            $this->results->visible = count($results) > 1;
            // the last result with rows (else the last one)
            $show = count($results) - 1;
            for ($i = count($results) - 1; $i >= 0; $i--) {
                if ($results[$i]->hasRows()) {
                    $show = $i;
                    break;
                }
            }
            $this->results->selected = $show;
            $this->showResult($show);
            if (!Sql::isRead($sql) && preg_match('/\b(CREATE|DROP|ALTER|RENAME|TRUNCATE)\b/i', $sql)) {
                $this->loadDatabases($this->conn);       // the tree follows the new / dropped tables
            }
        }, function (\Throwable $e): void {
            $this->run->enabled = $this->runAll->enabled = true;
            $this->resultInfo->text = $this->describe($e);
            $this->resultInfo->color = '#d64545';
        });
    }

    public function onResultsChange(ComboBox $sender, int $index, mixed $item = null): void
    {
        $this->showResult($index);
    }

    private function showResult(int $index): void
    {
        $r = $this->lastResults[$index] ?? null;
        if ($r === null) {
            return;
        }
        $numeric = [];
        foreach ($r->columns as $i => $_) {
            $numeric[] = $r->isNumeric($i);
        }
        $this->resultGrid->emptyText = $r->hasRows() ? 'No rows' : $r->summary();
        $this->resultGrid->setData($r->names(), array_map(fn (array $row) => $this->shown($r, $row), $r->rows), $numeric);
        $this->resultInfo->text = $r->summary() . ($r->info !== '' ? ' — ' . $r->info : '');
        $this->resultInfo->color = '#1f9d55';
    }

    /** @return list<ResultSet> */
    public function lastResults(): array
    {
        return $this->lastResults;
    }

    // buttons, tabs, dialogs ------------------------------------------------------------------------------------------

    private function setTab(string $tab): void
    {
        $this->tab = $tab;
        $this->dataPage->visible = $tab === 'data';
        $this->structurePage->visible = $tab === 'structure';
        $this->sqlPage->visible = $tab === 'sql';
        $this->tabData->style = $tab === 'data' ? 'primary' : 'flat';
        $this->tabStructure->style = $tab === 'structure' ? 'primary' : 'flat';
        $this->tabSql->style = $tab === 'sql' ? 'primary' : 'flat';
        $this->setFocus($tab === 'sql' ? $this->editor : ($tab === 'data' ? $this->grid : $this->columnsGrid));
    }

    public function onTabDataClick(Button $sender): void
    {
        $this->setTab('data');
    }

    public function onTabStructureClick(Button $sender): void
    {
        $this->setTab('structure');
    }

    public function onTabSqlClick(Button $sender): void
    {
        $this->setTab('sql');
    }

    private function updateButtons(): void
    {
        $selected = $this->conn !== '';
        $live = $this->db() !== null && $this->db()->isReady();
        $this->editConnection->enabled = $selected;
        $this->connect->enabled = $selected;
        $this->connect->text = $live ? 'Disconnect' : 'Connect';
        $this->refresh->enabled = $live;
        $this->dump->enabled = $live && $this->database !== '';
        $this->import->enabled = $live && $this->database !== '';
        $editable = $live && $this->page !== null && $this->tableType !== 'VIEW' && !$this->saving;
        $this->grid->editable = $editable;
        $this->addRow->enabled = $editable;
        $this->deleteRow->enabled = $editable && $this->grid->rowCount() > 0;
        $changes = $this->grid->hasChanges();
        $this->save->enabled = $editable && $changes;
        $this->discard->enabled = $changes && !$this->saving;
        $n = count($this->grid->changes) + count(array_filter($this->grid->rowStatus, fn (string $s) => $s === 'deleted'));
        $this->changesInfo->text = $changes ? $n . ' row(s) changed, not saved' : '';
        $this->where->text = $this->table !== '' ? $this->database . '.' . $this->table
            : ($this->database !== '' ? $this->database : ($selected ? (string)($this->connection($this->conn)['name'] ?? '') : 'No table open'));
    }

    public function onNewConnectionClick(Button $sender): void
    {
        $this->scenes()->push(ConnectionForm::class);
    }

    public function onEditConnectionClick(Button $sender): void
    {
        $c = $this->connection($this->conn);
        if ($c !== null) {
            $this->scenes()->push(ConnectionForm::class, ['connection' => $this->config($this->conn)]);
        }
    }

    public function onConnectClick(Button $sender): void
    {
        if ($this->conn === '') {
            return;
        }
        if ($this->db() !== null) {
            $this->disconnect($this->conn);
            return;
        }
        $this->open[$this->nodeId('c', $this->conn)] = true;
        $this->openConnection($this->conn);
    }

    public function onRefreshClick(Button $sender): void
    {
        if ($this->conn === '' || $this->db() === null) {
            return;
        }
        $this->loadDatabases($this->conn);
        if ($this->table !== '' && !$this->busyWithChanges()) {
            $this->loadStructure();
            $this->loadPage(true);
        }
    }

    public function onDumpClick(Button $sender): void
    {
        if ($this->database === '') {
            return;
        }
        $this->scenes()->push(TransferForm::class, ['mode' => 'dump', 'config' => $this->transferConfig($this->conn), 'database' => $this->database]
            + ($this->table !== '' ? ['tables' => [$this->table]] : []));
    }

    public function onImportClick(Button $sender): void
    {
        if ($this->database === '') {
            return;
        }
        $this->scenes()->push(TransferForm::class, ['mode' => 'import', 'config' => $this->transferConfig($this->conn), 'database' => $this->database]);
    }

    public function resume(mixed $result = null): void
    {
        if (!is_array($result)) {
            return;
        }
        if (($result['dialog'] ?? '') === 'connection') {
            if (isset($result['saved'])) {
                $c = (array)$result['saved'];
                $id = (string)$c['id'];
                $this->secrets[$id] = array_intersect_key($c, array_flip(ConnectionStore::SECRETS));
                $keys = array_flip(['host', 'port', 'user', 'ssh', 'sshHost', 'sshPort', 'sshUser', 'sshAuth', 'sshKey']);
                $changed = ($this->connection($id) ?? []) !== [] && array_intersect_key($this->connection($id), $keys) != array_intersect_key($c, $keys);
                $this->connections = $this->store->all();
                if ($changed) {
                    $this->disconnect($id);
                }
                $this->conn = $id;
                $this->buildTree();
                $this->tree->select($this->nodeId('c', $id));
                $this->status->text = $c['name'] . ' saved: open it on the left (double click)';
            } elseif (isset($result['deleted'])) {
                $this->disconnect((string)$result['deleted']);
                $this->connections = $this->store->all();
                $this->conn = $this->database = '';
                $this->buildTree();
                $this->status->text = 'Connection deleted';
            }
            $this->updateButtons();
            return;
        }
        if (($result['dialog'] ?? '') === 'transfer') {
            $this->status->text = ($result['mode'] === 'dump' ? 'Dump: ' : 'Import: ') . $result['message'];
            if ($result['mode'] === 'import' && $this->conn !== '') {
                $this->loadDatabases($this->conn);
            }
        }
    }

    public function onDarkChange(CheckBox $sender, bool $checked): void
    {
        $this->theme->colors = $checked ? Theme::DARK : Theme::LIGHT;
        $this->root->background = $this->theme->colors['background'];
        $this->grid->redraw();
        $this->columnsGrid->redraw();
        $this->resultGrid->redraw();
    }

    private function describe(\Throwable $e): string
    {
        return $e instanceof MysqlError ? $e->describe() : $e->getMessage();
    }

    // for the tests -----------------------------------------------------------------------------------------------------

    public function tab(): string
    {
        return $this->tab;
    }

    public function page(): ?ResultSet
    {
        return $this->page;
    }

    /** @return list<string> */
    public function primaryKey(): array
    {
        return $this->primaryKey;
    }

    public function total(): ?int
    {
        return $this->total;
    }
}
