<?php

namespace App\Form;

use App\Db\Dumper;
use App\Db\Importer;
use JumPHP\Gui\Button;
use JumPHP\Gui\CheckBox;
use JumPHP\Gui\Container;
use JumPHP\Gui\Form;
use JumPHP\Gui\Label;
use JumPHP\Gui\ProgressBar;
use JumPHP\Gui\TextBox;
use JumPHP\Native\FilePicker;
use JumPHP\Native\Native;

/**
 * A dump (a database, or some of its tables, to a .sql file) or an import (a .sql file run in a database), in a dialog,
 * on a connection of its own, between the frames: the progress shows, Close during the work cancels it.
 * Params: ['mode' => 'dump'|'import', 'config' => <the connection>, 'database' => 'shop', 'tables' => ['customers'] (dump,
 * optional), 'path' => <file>, 'autostart' => bool]. Closes with ['dialog' => 'transfer', 'mode', 'ok', 'message', 'path'].
 *
 * @property Label $heading
 * @property Label $subject
 * @property TextBox $path
 * @property Button $browse
 * @property Container $dumpOptions
 * @property CheckBox $structure
 * @property CheckBox $data
 * @property CheckBox $drop
 * @property CheckBox $createDatabase
 * @property Container $importOptions
 * @property CheckBox $continueOnError
 * @property ProgressBar $progress
 * @property Label $message
 * @property Button $cancel
 * @property Button $start
 */
final class TransferForm extends Form
{
    private string $mode = 'dump';
    /** @var array<string, mixed> */
    private array $config = [];
    private string $database = '';
    /** @var list<string>|null */
    private ?array $tables = null;
    private Dumper|Importer|null $job = null;
    private bool $finished = false;

    protected function loaded(array $params = []): void
    {
        $this->mode = ($params['mode'] ?? 'dump') === 'import' ? 'import' : 'dump';
        $this->config = (array)($params['config'] ?? []);
        $this->database = (string)($params['database'] ?? '');
        $this->tables = isset($params['tables']) ? array_values((array)$params['tables']) : null;
        $this->job = null;
        $this->finished = false;
        $dump = $this->mode === 'dump';
        $this->heading->text = $dump ? 'Dump to a .sql file' : 'Import a .sql file';
        $what = $this->tables !== null ? implode(', ', $this->tables) . ' of ' . $this->database : 'the database ' . $this->database;
        $this->subject->text = ($dump ? 'Saves ' : 'Runs the file in ') . $what . ' (' . ($this->config['name'] ?? $this->config['host'] ?? '') . ')';
        $this->dumpOptions->visible = $dump;
        $this->importOptions->visible = !$dump;
        $this->browse->visible = Native::supports($dump ? 'save' : 'open');
        $this->path->text = (string)($params['path'] ?? ($dump ? $this->defaultPath() : ''));
        $this->path->placeholder = $dump ? '' : '/path/to/script.sql';
        $this->progress->value = 0;
        $this->message->text = '';
        $this->message->color = '';
        $this->start->enabled = true;
        $this->start->text = 'Start';
        $this->cancel->text = 'Close';
        if (!empty($params['autostart'])) {
            $this->onStartClick($this->start);
        }
    }

    /** var/dumps/<database>[-<table>]-<date>.sql: the app's data folder (the user types another path if they want). */
    private function defaultPath(): string
    {
        $name = $this->database . ($this->tables !== null && count($this->tables) === 1 ? '-' . $this->tables[0] : '');
        $name = (string)preg_replace('/[^A-Za-z0-9_.-]+/', '_', $name);
        return $this->engine->storage->path('dumps/' . $name . '-' . date('Y-m-d-His') . '.sql');
    }

    public function onBrowseClick(Button $sender): void
    {
        $task = $this->mode === 'dump'
            ? FilePicker::save(['file_name' => basename($this->path->text), 'filters' => ['SQL' => ['sql']]])
            : FilePicker::open(['filters' => ['SQL' => ['sql'], 'All files' => ['*']]]);
        $task->then(function (mixed $file): void {
            if (is_string($file) && $file !== '') {
                $this->path->text = $file;
            }
        }, fn (\Throwable $e) => $this->message->text = $e->getMessage());
    }

    public function onStartClick(Button $sender): void
    {
        if ($this->job !== null && !$this->job->done) {
            return;
        }
        if ($this->finished) {
            $this->close($this->outcome());
            return;
        }
        $path = trim($this->path->text);
        if ($path === '') {
            $this->message->text = 'A file, please';
            $this->setFocus($this->path);
            return;
        }
        if (str_starts_with($path, '~/') && getenv('HOME')) {
            $path = getenv('HOME') . substr($path, 1);
        }
        $this->job = $this->mode === 'dump'
            ? new Dumper($this->config, $this->database, $path, ['tables' => $this->tables, 'structure' => $this->structure->checked,
                'data' => $this->data->checked, 'drop' => $this->drop->checked, 'createDatabase' => $this->createDatabase->checked])
            : new Importer($this->config, $this->database, $path, $this->continueOnError->checked);
        $this->start->enabled = false;
        $this->cancel->text = 'Cancel';
        $this->message->color = '';
        $this->job->start();
    }

    public function onCancelClick(Button $sender): void
    {
        if ($this->job !== null && !$this->job->done) {
            $this->job->cancel();
            return;
        }
        $this->close($this->finished ? $this->outcome() : null);
    }

    /** @return array<string, mixed> */
    private function outcome(): array
    {
        return ['dialog' => 'transfer', 'mode' => $this->mode, 'ok' => $this->job !== null && $this->job->error === null,
            'message' => $this->job !== null ? $this->job->message : '', 'path' => $this->job !== null ? $this->job->path : ''];
    }

    public function job(): Dumper|Importer|null
    {
        return $this->job;
    }

    protected function tick(float $dt): void
    {
        // Escape only: the engine's 'back' action is also Backspace, which the text boxes need
        if ($this->engine->input->getKeyboard()->isKeyPressed(\SDLK_ESCAPE)) {
            $this->onCancelClick($this->cancel);
            return;
        }
        $job = $this->job;
        if ($job === null) {
            return;
        }
        if (!$job->done) {
            $job->poll();
        }
        $this->progress->value = (int)round($job->progress * 100);
        $this->message->text = $job->message;
        if ($job->done && !$this->finished) {
            $this->finished = true;
            $this->message->color = $job->error === null ? '#1f9d55' : '#d64545';
            if ($job instanceof Importer && $job->errors !== [] && $job->error === null) {
                $this->message->text = $job->message . "\n" . implode("\n", array_slice($job->errors, 0, 3));
            }
            $this->start->enabled = true;
            $this->start->text = 'Done';
            $this->cancel->text = 'Close';
        }
    }
}
