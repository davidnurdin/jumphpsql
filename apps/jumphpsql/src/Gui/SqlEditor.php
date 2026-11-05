<?php

namespace App\Gui;

use JumPHP\Gui\Pointer;
use JumPHP\Gui\Theme;
use JumPHP\Gui\Widget;
use JumPHP\Native\Clipboard;
use JumPHP\Renderer\Renderer;
use JumPHP\Util\Color;

/**
 * A SQL editor: line numbers, colours (keywords, strings, numbers, comments, `names`), a cursor that goes everywhere
 * (arrows, Home / End, Page up / down, a click), a selection (Shift + moves, a drag, a double click selects a word,
 * Ctrl+A), Ctrl+C / X / V, Ctrl+Z / Ctrl+Y, Tab / Shift+Tab indent, Return keeps the indent, Ctrl+/ comments lines.
 * `readonly`: shows a text (a CREATE TABLE) that can be selected and copied.
 * Events: change (string $text), run (string $sql: Ctrl+Return = the selection or the statement under the cursor),
 * runAll (F5 / Ctrl+R).
 *
 * @property string $text
 */
class SqlEditor extends Widget
{
    public const DEFAULTS = ['text' => '', 'readonly' => false, 'height' => 'fill', 'fontSize' => 14, 'placeholder' => ''];

    public const KEYWORDS = ['ACCESSIBLE', 'ADD', 'AFTER', 'AGAINST', 'ALGORITHM', 'ALL', 'ALTER', 'ANALYZE', 'AND', 'AS', 'ASC',
        'AUTO_INCREMENT', 'BEFORE', 'BEGIN', 'BETWEEN', 'BIGINT', 'BINARY', 'BLOB', 'BOOLEAN', 'BOTH', 'BY', 'CALL', 'CASCADE',
        'CASE', 'CHANGE', 'CHAR', 'CHARACTER', 'CHARSET', 'CHECK', 'COLLATE', 'COLUMN', 'COLUMNS', 'COMMENT', 'COMMIT',
        'CONSTRAINT', 'CREATE', 'CROSS', 'CURRENT_TIMESTAMP', 'DATABASE', 'DATABASES', 'DATE', 'DATETIME', 'DECIMAL',
        'DECLARE', 'DEFAULT', 'DEFINER', 'DELETE', 'DELIMITER', 'DESC', 'DESCRIBE', 'DISTINCT', 'DIV', 'DO', 'DOUBLE', 'DROP',
        'DUPLICATE', 'EACH', 'ELSE', 'ELSEIF', 'END', 'ENGINE', 'ENUM', 'ESCAPE', 'EXISTS', 'EXPLAIN', 'FALSE', 'FETCH',
        'FIRST', 'FLOAT', 'FOR', 'FOREIGN', 'FROM', 'FULL', 'FULLTEXT', 'FUNCTION', 'GRANT', 'GROUP', 'HAVING', 'IF',
        'IGNORE', 'IN', 'INDEX', 'INNER', 'INSERT', 'INT', 'INTEGER', 'INTERVAL', 'INTO', 'IS', 'JOIN', 'JSON', 'KEY', 'KEYS',
        'KILL', 'LAST', 'LEADING', 'LEFT', 'LIKE', 'LIMIT', 'LOCK', 'LONGTEXT', 'LOOP', 'MEDIUMINT', 'MEDIUMTEXT', 'MODIFY',
        'NATURAL', 'NOT', 'NULL', 'OFFSET', 'ON', 'OPTIMIZE', 'OR', 'ORDER', 'OUTER', 'OVER', 'PARTITION', 'PRIMARY',
        'PROCEDURE', 'PROCESSLIST', 'REFERENCES', 'REGEXP', 'RENAME', 'REPAIR', 'REPEAT', 'REPLACE', 'RESTRICT', 'RETURN',
        'RETURNS', 'REVOKE', 'RIGHT', 'RLIKE', 'ROLLBACK', 'ROW', 'ROWS', 'SCHEMA', 'SELECT', 'SET', 'SHOW', 'SIGNED',
        'SMALLINT', 'START', 'STATUS', 'TABLE', 'TABLES', 'TEMPORARY', 'TEXT', 'THEN', 'TIME', 'TIMESTAMP', 'TINYINT',
        'TINYTEXT', 'TO', 'TRANSACTION', 'TRIGGER', 'TRUE', 'TRUNCATE', 'UNION', 'UNIQUE', 'UNLOCK', 'UNSIGNED', 'UPDATE',
        'USE', 'USING', 'VALUES', 'VARBINARY', 'VARCHAR', 'VARIABLES', 'VIEW', 'WHEN', 'WHERE', 'WHILE', 'WITH', 'XOR',
        'YEAR', 'ZEROFILL', 'COUNT', 'SUM', 'AVG', 'MIN', 'MAX', 'NOW', 'CONCAT', 'COALESCE', 'IFNULL', 'CAST', 'CONVERT'];

    private const F5 = 1073741886;

    /** @var list<string> */
    private array $lines = [''];
    public int $line = 0;
    public int $col = 0;
    /** @var array{0: int, 1: int}|null where the selection started */
    public ?array $anchor = null;
    private float $scrollX = 0;
    private float $scrollY = 0;
    private float $blink = 0;
    private bool $selecting = false;
    private bool $dragBar = false;
    private bool $textOn = false;
    /** @var list<array{0: string, 1: int, 2: int}> */
    private array $undo = [];
    /** @var list<array{0: string, 1: int, 2: int}> */
    private array $redo = [];
    private string $lastEdit = '';
    /** @var array<int, list<array{0: string, 1: string}>> line => tokens [text, kind] */
    private array $tokens = [];
    private mixed $texture = null;
    /** @var array{0: int, 1: int} */
    private array $textureSize = [0, 0];
    private bool $dirty = true;
    private string $drawn = '';
    /** @var array<string, bool> */
    private static array $keywordSet = [];

    public static function events(): array
    {
        return ['change', 'run', 'runAll', 'click'];
    }

    public function __construct(array $props = [])
    {
        parent::__construct($props);
        $this->lines = explode("\n", str_replace(["\r\n", "\r"], "\n", (string)$this->props['text']));
        self::$keywordSet = self::$keywordSet ?: array_fill_keys(self::KEYWORDS, true);
    }

    public function focusable(): bool
    {
        return $this->isEnabled();
    }

    public function stretches(string $axis): bool
    {
        return true;
    }

    protected function changed(string $name, mixed $old): void
    {
        if ($name === 'text') {
            $this->lines = explode("\n", str_replace(["\r\n", "\r"], "\n", (string)$this->props['text']));
            $this->line = min($this->line, count($this->lines) - 1);
            $this->col = min($this->col, mb_strlen($this->lines[$this->line]));
            $this->anchor = null;
            $this->tokens = [];
            if ($this->props['readonly']) {
                $this->line = $this->col = 0;
                $this->scrollX = $this->scrollY = 0;
            }
        }
        $this->dirty = true;
        parent::changed($name, $old);
    }

    public function text(): string
    {
        return implode("\n", $this->lines);
    }

    /** Replaces the whole text (undoable). */
    public function setText(string $text): void
    {
        $this->snapshot('set');
        $this->apply(explode("\n", str_replace(["\r\n", "\r"], "\n", $text)));
        $this->line = $this->col = 0;
        $this->anchor = null;
    }

    /** The cursor as an offset in characters of the text. */
    public function offset(): int
    {
        $n = 0;
        for ($i = 0; $i < $this->line; $i++) {
            $n += mb_strlen($this->lines[$i]) + 1;
        }
        return $n + $this->col;
    }

    public function selectedText(): string
    {
        if ($this->anchor === null || $this->anchor === [$this->line, $this->col]) {
            return '';
        }
        [$a, $b] = $this->range();
        return $this->slice($a, $b);
    }

    /** @return array{0: array{0: int, 1: int}, 1: array{0: int, 1: int}} the selection, start first */
    private function range(): array
    {
        $a = $this->anchor ?? [$this->line, $this->col];
        $b = [$this->line, $this->col];
        return $a <= $b ? [$a, $b] : [$b, $a];
    }

    /** @param array{0: int, 1: int} $a @param array{0: int, 1: int} $b */
    private function slice(array $a, array $b): string
    {
        if ($a[0] === $b[0]) {
            return mb_substr($this->lines[$a[0]], $a[1], $b[1] - $a[1]);
        }
        $out = [mb_substr($this->lines[$a[0]], $a[1])];
        for ($i = $a[0] + 1; $i < $b[0]; $i++) {
            $out[] = $this->lines[$i];
        }
        $out[] = mb_substr($this->lines[$b[0]], 0, $b[1]);
        return implode("\n", $out);
    }

    // editing ------------------------------------------------------------------------------------------------------

    /** @param list<string> $lines */
    private function apply(array $lines): void
    {
        $this->lines = $lines === [] ? [''] : $lines;
        $this->props['text'] = implode("\n", $this->lines);
        $this->tokens = [];
        $this->dirty = true;
        $this->emit('change', $this->props['text']);
    }

    private function snapshot(string $kind): void
    {
        if ($kind === 'type' && $this->lastEdit === 'type') {
            return;
        }
        $this->lastEdit = $kind;
        $this->undo[] = [implode("\n", $this->lines), $this->line, $this->col];
        if (count($this->undo) > 300) {
            array_shift($this->undo);
        }
        $this->redo = [];
    }

    private function deleteSelection(): bool
    {
        if ($this->anchor === null || $this->anchor === [$this->line, $this->col]) {
            $this->anchor = null;
            return false;
        }
        [$a, $b] = $this->range();
        $lines = $this->lines;
        $head = mb_substr($lines[$a[0]], 0, $a[1]);
        $tail = mb_substr($lines[$b[0]], $b[1]);
        array_splice($lines, $a[0], $b[0] - $a[0] + 1, [$head . $tail]);
        [$this->line, $this->col] = $a;
        $this->anchor = null;
        $this->apply($lines);
        return true;
    }

    public function insert(string $text): void
    {
        if ($this->props['readonly']) {
            return;
        }
        $this->snapshot(strlen($text) === 1 && $text !== "\n" ? 'type' : 'insert');
        $this->deleteSelection();
        $lines = $this->lines;
        $current = $lines[$this->line];
        $head = mb_substr($current, 0, $this->col);
        $tail = mb_substr($current, $this->col);
        $parts = explode("\n", str_replace(["\r\n", "\r", "\t"], ["\n", "\n", '    '], $text));
        if (count($parts) === 1) {
            $lines[$this->line] = $head . $parts[0] . $tail;
            $this->col += mb_strlen($parts[0]);
        } else {
            $new = [$head . $parts[0]];
            for ($i = 1; $i < count($parts) - 1; $i++) {
                $new[] = $parts[$i];
            }
            $last = end($parts);
            $new[] = $last . $tail;
            array_splice($lines, $this->line, 1, $new);
            $this->line += count($parts) - 1;
            $this->col = mb_strlen($last);
        }
        $this->apply($lines);
        $this->reveal();
    }

    private function backspace(): void
    {
        if ($this->props['readonly']) {
            return;
        }
        $this->snapshot('delete');
        if ($this->deleteSelection()) {
            return;
        }
        $lines = $this->lines;
        if ($this->col > 0) {
            $current = $lines[$this->line];
            // four spaces of indent go at once
            $back = preg_match('/^ +$/', mb_substr($current, 0, $this->col)) && $this->col % 4 === 0 ? 4 : 1;
            $lines[$this->line] = mb_substr($current, 0, $this->col - $back) . mb_substr($current, $this->col);
            $this->col -= $back;
        } elseif ($this->line > 0) {
            $this->col = mb_strlen($lines[$this->line - 1]);
            $lines[$this->line - 1] .= $lines[$this->line];
            array_splice($lines, $this->line, 1);
            $this->line--;
        }
        $this->apply($lines);
    }

    private function deleteForward(): void
    {
        if ($this->props['readonly']) {
            return;
        }
        $this->snapshot('delete');
        if ($this->deleteSelection()) {
            return;
        }
        $lines = $this->lines;
        $current = $lines[$this->line];
        if ($this->col < mb_strlen($current)) {
            $lines[$this->line] = mb_substr($current, 0, $this->col) . mb_substr($current, $this->col + 1);
        } elseif ($this->line < count($lines) - 1) {
            $lines[$this->line] .= $lines[$this->line + 1];
            array_splice($lines, $this->line + 1, 1);
        }
        $this->apply($lines);
    }

    private function newLine(): void
    {
        preg_match('/^\s*/', $this->lines[$this->line], $m);
        $indent = mb_substr($m[0], 0, $this->col);
        $this->insert("\n" . $indent);
    }

    /** Tab / Shift+Tab: indents or outdents the selected lines (or inserts spaces). */
    private function indent(bool $out): void
    {
        if ($this->props['readonly']) {
            return;
        }
        $multi = $this->anchor !== null && $this->anchor[0] !== $this->line;
        if (!$multi && !$out) {
            $this->insert(str_repeat(' ', 4 - $this->col % 4));
            return;
        }
        $this->snapshot('indent');
        [$a, $b] = $this->range();
        $lines = $this->lines;
        for ($i = $a[0]; $i <= $b[0]; $i++) {
            if ($out) {
                $remove = strlen($lines[$i]) - strlen(ltrim(substr($lines[$i], 0, 4), ' '));
                $lines[$i] = substr($lines[$i], $remove);
                if ($i === $this->line) {
                    $this->col = max(0, $this->col - $remove);
                }
                if ($this->anchor !== null && $i === $this->anchor[0]) {
                    $this->anchor[1] = max(0, $this->anchor[1] - $remove);
                }
            } else {
                $lines[$i] = '    ' . $lines[$i];
                if ($i === $this->line) {
                    $this->col += 4;
                }
                if ($this->anchor !== null && $i === $this->anchor[0]) {
                    $this->anchor[1] += 4;
                }
            }
        }
        $this->apply($lines);
    }

    /** Ctrl+/: "-- " in front of the lines, or taken away. */
    private function toggleComment(): void
    {
        if ($this->props['readonly']) {
            return;
        }
        $this->snapshot('comment');
        [$a, $b] = $this->range();
        $lines = $this->lines;
        $all = true;
        for ($i = $a[0]; $i <= $b[0]; $i++) {
            $all = $all && str_starts_with(ltrim($lines[$i]), '--');
        }
        for ($i = $a[0]; $i <= $b[0]; $i++) {
            $lines[$i] = $all ? (string)preg_replace('/^(\s*)-- ?/', '$1', $lines[$i]) : '-- ' . $lines[$i];
        }
        $this->col = min($this->col, mb_strlen($lines[$this->line]));
        $this->anchor = null;
        $this->apply($lines);
    }

    private function undoOrRedo(bool $redo): void
    {
        $from = $redo ? $this->redo : $this->undo;
        if ($from === []) {
            return;
        }
        $state = array_pop($from);
        $current = [implode("\n", $this->lines), $this->line, $this->col];
        if ($redo) {
            $this->redo = $from;
            $this->undo[] = $current;
        } else {
            $this->undo = $from;
            $this->redo[] = $current;
        }
        $this->lastEdit = '';
        $this->anchor = null;
        $this->apply(explode("\n", $state[0]));
        $this->line = min($state[1], count($this->lines) - 1);
        $this->col = min($state[2], mb_strlen($this->lines[$this->line]));
        $this->reveal();
    }

    private function move(int $line, int $col, bool $select): void
    {
        if ($select) {
            $this->anchor ??= [$this->line, $this->col];
        } else {
            $this->anchor = null;
        }
        $this->line = max(0, min(count($this->lines) - 1, $line));
        $this->col = max(0, min(mb_strlen($this->lines[$this->line]), $col));
        $this->lastEdit = '';
        $this->blink = 0;
        $this->dirty = true;
        $this->reveal();
    }

    private function wordAt(int $line, int $col): array
    {
        $chars = mb_str_split($this->lines[$line]) ?: [];
        $from = $to = min($col, count($chars));
        while ($from > 0 && preg_match('/\w/u', $chars[$from - 1])) {
            $from--;
        }
        while ($to < count($chars) && preg_match('/\w/u', $chars[$to])) {
            $to++;
        }
        return [$from, $to];
    }

    // geometry -----------------------------------------------------------------------------------------------------

    private function font(Theme $t): MonoFont
    {
        return MonoFont::get($this->form?->sdlOrNull(), max(6, (int)round((float)$this->props['fontSize'] * $t->scale)));
    }

    private function lineHeight(Theme $t): float
    {
        return round($this->font($t)->height * 1.25);
    }

    private function gutter(Theme $t): float
    {
        return strlen((string)count($this->lines)) * $this->font($t)->advance + $t->px(22);
    }

    protected function content(Theme $t, float $maxWidth, string $breakpoint): array
    {
        return [$t->px(240), $t->px(120)];
    }

    private function reveal(): void
    {
        $t = $this->form?->theme;
        if ($t === null || $this->h <= 0) {
            return;
        }
        $lh = $this->lineHeight($t);
        $pad = $t->px(6);
        $this->scrollY = max(min($this->scrollY, $this->line * $lh), ($this->line + 1) * $lh + 2 * $pad - $this->h);
        $font = $this->font($t);
        $x = $this->col * $font->advance;
        $room = $this->w - $this->gutter($t) - $t->px(20);
        $this->scrollX = max(min($this->scrollX, $x), $x - $room);
        $this->scrollX = max(0, $this->scrollX);
        $this->scrollY = max(0, $this->scrollY);
        $this->dirty = true;
    }

    private function clampScroll(Theme $t): void
    {
        $content = count($this->lines) * $this->lineHeight($t) + $t->px(12);
        $this->scrollY = max(0, min($this->scrollY, $content - $this->h));
        $this->scrollX = max(0, $this->scrollX);
    }

    // drawing ------------------------------------------------------------------------------------------------------

    public function update(float $dt): void
    {
        $this->blink = $this->focused ? fmod($this->blink + $dt, 1.0) : 0;
        // the system's text input (Form only starts it for its own text boxes)
        if ($this->focused && !$this->textOn && !$this->props['readonly'] && \function_exists('SDL_StartTextInput') && $this->form?->sdlOrNull() !== null) {
            \SDL_StartTextInput();
        }
        $this->textOn = $this->focused;
    }

    /** @return list<array{0: string, 1: string}> */
    private function lineTokens(int $n): array
    {
        if ($this->tokens === []) {
            $this->tokenize();
        }
        return $this->tokens[$n] ?? [];
    }

    private function tokenize(): void
    {
        $this->tokens = [];
        $inComment = false;
        foreach ($this->lines as $n => $line) {
            $out = [];
            $i = 0;
            $len = strlen($line);
            while ($i < $len) {
                if ($inComment) {
                    $end = strpos($line, '*/', $i);
                    $stop = $end === false ? $len : $end + 2;
                    $out[] = [substr($line, $i, $stop - $i), 'comment'];
                    $inComment = $end === false;
                    $i = $stop;
                    continue;
                }
                $c = $line[$i];
                if ($c === '/' && ($line[$i + 1] ?? '') === '*') {
                    $inComment = true;
                    continue;
                }
                if ($c === '#' || ($c === '-' && ($line[$i + 1] ?? '') === '-')) {
                    $out[] = [substr($line, $i), 'comment'];
                    break;
                }
                if ($c === "'" || $c === '"' || $c === '`') {
                    $j = $i + 1;
                    while ($j < $len && $line[$j] !== $c) {
                        $j += $line[$j] === '\\' && $c !== '`' ? 2 : 1;
                    }
                    $out[] = [substr($line, $i, min($len, $j + 1) - $i), $c === '`' ? 'ident' : 'string'];
                    $i = $j + 1;
                    continue;
                }
                if (preg_match('/\G(\d+(\.\d+)?|0x[0-9a-f]+)\b/Ai', $line, $m, 0, $i)) {
                    $out[] = [$m[0], 'number'];
                    $i += strlen($m[0]);
                    continue;
                }
                if (preg_match('/\G[A-Za-z_][A-Za-z0-9_$]*/A', $line, $m, 0, $i)) {
                    $out[] = [$m[0], isset(self::$keywordSet[strtoupper($m[0])]) ? 'keyword' : 'text'];
                    $i += strlen($m[0]);
                    continue;
                }
                if (preg_match('/\G[^\'"`#\-\/A-Za-z_0-9]+|\G./As', $line, $m, 0, $i)) {
                    $out[] = [$m[0], 'text'];
                    $i += strlen($m[0]);
                }
            }
            $this->tokens[$n] = $out;
        }
    }

    public function render(Renderer $r, Theme $t): void
    {
        $size = [(int)ceil($this->w), (int)ceil($this->h)];
        if ($size[0] < 4 || $size[1] < 4) {
            return;
        }
        if ($this->texture === null || $this->textureSize !== $size) {
            if ($this->texture !== null) {
                \SDL_DestroyTexture($this->texture);
            }
            $this->texture = $r->createTargetTexture($size[0], $size[1]);
            $this->textureSize = $size;
            $this->dirty = true;
        }
        $key = ($this->focused ? 'f' : '') . $t->colors['surface'];
        if ($this->dirty || $this->drawn !== $key) {
            $this->drawn = $key;
            $this->dirty = false;
            $previous = $r->getTarget();
            [$ox, $oy] = $r->translation();
            $r->setTarget($this->texture);
            $r->translate(-$ox - $this->x, -$oy - $this->y);
            $this->draw($r, $t);
            $r->translate($ox + $this->x, $oy + $this->y);
            $r->setTarget($previous);
        }
        $r->copy($this->texture, null, $this->x, $this->y, $size[0], $size[1]);
        // the cursor, over the cached picture
        if ($this->focused && $this->blink < 0.55) {
            $font = $this->font($t);
            $lh = $this->lineHeight($t);
            $cx = $this->x + $this->gutter($t) + $t->px(6) + $this->col * $font->advance - $this->scrollX;
            $cy = $this->y + $t->px(6) + $this->line * $lh - $this->scrollY;
            if ($cx >= $this->x + $this->gutter($t) && $cx < $this->x + $this->w && $cy >= $this->y && $cy + $lh <= $this->y + $this->h) {
                $r->fillRect($cx, $cy + $t->px(1), max(1, $t->px(1.6)), $lh - $t->px(2), $t->color('text'));
            }
        }
    }

    private function draw(Renderer $r, Theme $t): void
    {
        $this->clampScroll($t);
        $dark = $t->colors['surface'] === Theme::DARK['surface'];
        $palette = $dark
            ? ['keyword' => '#cc7832', 'string' => '#6a8759', 'number' => '#6897bb', 'comment' => '#7f848e', 'ident' => '#b48ead', 'text' => $t->colors['text']]
            : ['keyword' => '#0033b3', 'string' => '#067d17', 'number' => '#1750eb', 'comment' => '#8c8c8c', 'ident' => '#871094', 'text' => $t->colors['text']];
        $surface = $this->props['readonly'] ? $t->color('background') : $t->color('surface');
        $r->clear($surface);
        $font = $this->font($t);
        $lh = $this->lineHeight($t);
        $gutter = $this->gutter($t);
        $pad = $t->px(6);
        $left = $this->x + $gutter + $pad - $this->scrollX;
        $ty = ($lh - $font->height) / 2;
        $first = max(0, (int)floor(($this->scrollY - $pad) / $lh));
        $last = min(count($this->lines) - 1, (int)ceil(($this->scrollY + $this->h) / $lh));
        [$sa, $sb] = $this->anchor !== null && $this->anchor !== [$this->line, $this->col] ? $this->range() : [null, null];
        $maxChars = $font->fits($this->w - $gutter) + 2;
        $skip = (int)floor($this->scrollX / max(1, $font->advance));
        for ($n = $first; $n <= $last; $n++) {
            $y = $this->y + $pad + $n * $lh - $this->scrollY;
            if ($n === $this->line && $this->focused && $sa === null && !$this->props['readonly']) {
                $r->fillRect($this->x + $gutter, $y, $this->w - $gutter, $lh, $t->color('hover')->withAlpha(150));
            }
            if ($sa !== null && $n >= $sa[0] && $n <= $sb[0]) {
                $from = $n === $sa[0] ? $sa[1] : 0;
                $to = $n === $sb[0] ? $sb[1] : mb_strlen($this->lines[$n]) + 1;
                $r->fillRect($left + $from * $font->advance, $y, max($font->advance / 2, ($to - $from) * $font->advance), $lh, $t->color('selection'));
            }
            $x = $left;
            $col = 0;
            foreach ($this->lineTokens($n) as [$text, $kind]) {
                $len = mb_strlen($text);
                if ($col + $len > $skip && $col < $skip + $maxChars) {
                    $cut = max(0, $skip - $col);
                    $font->draw($r, mb_substr($text, $cut, $maxChars), $x + $cut * $font->advance, $y + $ty, $palette[$kind] ?? $palette['text']);
                }
                $x += $len * $font->advance;
                $col += $len;
                if ($col > $skip + $maxChars) {
                    break;
                }
            }
        }
        if (implode('', $this->lines) === '' && $this->props['placeholder'] !== '') {
            $t->font()->draw($r, (string)$this->props['placeholder'], $left, $this->y + $pad + $ty, 1, $t->color('muted'));
        }
        // the gutter
        $r->fillRect($this->x, $this->y, $gutter, $this->h, $t->color('background'));
        $r->fillRect($this->x + $gutter - 1, $this->y, 1, $this->h, $t->color('border'));
        for ($n = $first; $n <= $last; $n++) {
            $y = $this->y + $pad + $n * $lh - $this->scrollY;
            $label = (string)($n + 1);
            $font->draw($r, $label, $this->x + $gutter - $t->px(10) - strlen($label) * $font->advance, $y + $ty,
                $n === $this->line ? $t->color('text') : $t->color('muted'));
        }
        // the bar
        $content = count($this->lines) * $lh + 2 * $pad;
        if ($content > $this->h) {
            $barW = $t->px(5);
            $thumbH = max($t->px(24), $this->h * $this->h / $content);
            $thumbY = $this->y + ($this->h - $thumbH) * $this->scrollY / max(1, $content - $this->h);
            $t->box($r, $this->x + $this->w - $barW - $t->px(2), $thumbY, $barW, $thumbH, $t->color('border'), $barW / 2);
        }
        $border = $this->focused ? $t->color('focus') : $t->color('border');
        $b = max(1, round($t->scale));
        $r->fillRect($this->x, $this->y, $this->w, $b, $border);
        $r->fillRect($this->x, $this->y + $this->h - $b, $this->w, $b, $border);
        $r->fillRect($this->x, $this->y, $b, $this->h, $border);
        $r->fillRect($this->x + $this->w - $b, $this->y, $b, $this->h, $border);
    }

    // input --------------------------------------------------------------------------------------------------------

    /** @return array{0: int, 1: int} the line and column under a point */
    private function at(Pointer $p): array
    {
        $t = $this->form?->theme;
        if ($t === null) {
            return [0, 0];
        }
        $font = $this->font($t);
        $lh = $this->lineHeight($t);
        $line = (int)floor(($p->y - $this->y - $t->px(6) + $this->scrollY) / $lh);
        $line = max(0, min(count($this->lines) - 1, $line));
        $col = (int)round(($p->x - $this->x - $this->gutter($t) - $t->px(6) + $this->scrollX) / $font->advance);
        return [$line, max(0, min(mb_strlen($this->lines[$line]), $col))];
    }

    public function wheel(float $notches): bool
    {
        $t = $this->form?->theme;
        if ($t === null) {
            return false;
        }
        $shift = \function_exists('SDL_GetModState') && ((int)\SDL_GetModState() & 3) !== 0;
        if ($shift) {
            $this->scrollX = max(0, $this->scrollX - $notches * $t->px(60));
        } else {
            $this->scrollY -= $notches * 3 * $this->lineHeight($t);
        }
        $this->clampScroll($t);
        $this->dirty = true;
        return true;
    }

    public function pointerDown(Pointer $p): void
    {
        $t = $this->form?->theme;
        $this->dragBar = $t !== null && $p->x >= $this->x + $this->w - $t->px(10);
        if ($this->dragBar) {
            return;
        }
        [$line, $col] = $this->at($p);
        $this->move($line, $col, $p->shift);
        if (!$p->shift) {
            $this->anchor = [$line, $col];
        }
        $this->selecting = true;
    }

    public function pointerDrag(Pointer $p): void
    {
        $t = $this->form?->theme;
        if ($t === null) {
            return;
        }
        if ($this->dragBar) {
            $content = count($this->lines) * $this->lineHeight($t) + $t->px(12);
            $this->scrollY = ($p->y - $this->y) / max(1, $this->h) * $content - $this->h / 2;
            $this->clampScroll($t);
            $this->dirty = true;
            return;
        }
        if ($this->selecting) {
            [$line, $col] = $this->at($p);
            $anchor = $this->anchor;
            $this->line = $line;
            $this->col = $col;
            $this->anchor = $anchor;
            $this->reveal();
        }
    }

    public function pointerUp(Pointer $p, bool $inside): void
    {
        $this->selecting = false;
        if ($this->dragBar) {
            $this->dragBar = false;
            return;
        }
        if ($this->anchor === [$this->line, $this->col]) {
            $this->anchor = null;
        }
        if ($inside && $p->double) {
            [$from, $to] = $this->wordAt($this->line, $this->col);
            $this->anchor = [$this->line, $from];
            $this->col = $to;
        }
        $this->dirty = true;
        if ($inside) {
            $this->click($p);
        }
    }

    public function blur(): void
    {
        $this->dirty = true;
    }

    public function keys(Pointer $p): bool
    {
        $t = $this->form?->theme;
        $page = $t !== null ? max(1, (int)floor($this->h / $this->lineHeight($t)) - 1) : 20;
        $used = false;
        foreach ($p->keys as $key) {
            $s = $p->shift;
            $used = true;
            if ($p->ctrl) {
                switch ($key) {
                    case ord('a'):
                        $this->anchor = [0, 0];
                        $this->line = count($this->lines) - 1;
                        $this->col = mb_strlen($this->lines[$this->line]);
                        $this->dirty = true;
                        break;
                    case ord('c'):
                    case ord('x'):
                        $text = $this->selectedText();
                        if ($text === '') {
                            $text = $this->lines[$this->line] . "\n";      // no selection: the line
                        }
                        try {
                            Clipboard::copy($text);
                        } catch (\Throwable) {
                        }
                        if ($key === ord('x') && !$this->props['readonly']) {
                            if ($this->selectedText() !== '') {
                                $this->snapshot('cut');
                                $this->deleteSelection();
                            } else {
                                $this->snapshot('cut');
                                $lines = $this->lines;
                                array_splice($lines, $this->line, 1);
                                $this->apply($lines);
                                $this->move($this->line, 0, false);
                            }
                        }
                        break;
                    case ord('v'):
                        try {
                            Clipboard::paste()->then(fn (mixed $text) => $this->insert((string)$text));
                        } catch (\Throwable) {
                        }
                        break;
                    case ord('z'):
                        $this->undoOrRedo($s);
                        break;
                    case ord('y'):
                        $this->undoOrRedo(true);
                        break;
                    case ord('/'):
                        $this->toggleComment();
                        break;
                    case ord('r'):
                        $this->emit('runAll', $this->text());
                        break;
                    case Pointer::RETURN:
                    case Pointer::KP_ENTER:
                        $selected = $this->selectedText();
                        $this->emit('run', $selected !== '' ? $selected : \App\Db\Sql::statementAt($this->text(), $this->offset()));
                        break;
                    case Pointer::HOME:
                        $this->move(0, 0, $s);
                        break;
                    case Pointer::END:
                        $this->move(count($this->lines) - 1, PHP_INT_MAX, $s);
                        break;
                    case Pointer::LEFT:
                    case Pointer::RIGHT:
                        $this->moveWord($key === Pointer::LEFT ? -1 : 1, $s);
                        break;
                    default:
                        $used = false;
                }
                continue;
            }
            switch ($key) {
                case Pointer::LEFT:
                    if (!$s && $this->anchor !== null && $this->selectedText() !== '') {
                        [$a] = $this->range();
                        $this->move($a[0], $a[1], false);
                    } elseif ($this->col === 0 && $this->line > 0) {
                        $this->move($this->line - 1, PHP_INT_MAX, $s);
                    } else {
                        $this->move($this->line, $this->col - 1, $s);
                    }
                    break;
                case Pointer::RIGHT:
                    if (!$s && $this->anchor !== null && $this->selectedText() !== '') {
                        [, $b] = $this->range();
                        $this->move($b[0], $b[1], false);
                    } elseif ($this->col >= mb_strlen($this->lines[$this->line]) && $this->line < count($this->lines) - 1) {
                        $this->move($this->line + 1, 0, $s);
                    } else {
                        $this->move($this->line, $this->col + 1, $s);
                    }
                    break;
                case Pointer::UP:
                    $this->move($this->line - 1, $this->col, $s);
                    break;
                case Pointer::DOWN:
                    $this->move($this->line + 1, $this->col, $s);
                    break;
                case Pointer::PAGEUP:
                    $this->move($this->line - $page, $this->col, $s);
                    break;
                case Pointer::PAGEDOWN:
                    $this->move($this->line + $page, $this->col, $s);
                    break;
                case Pointer::HOME:
                    // first the first non-blank character, then the start of the line
                    $indent = strlen($this->lines[$this->line]) - strlen(ltrim($this->lines[$this->line]));
                    $this->move($this->line, $this->col === $indent ? 0 : $indent, $s);
                    break;
                case Pointer::END:
                    $this->move($this->line, PHP_INT_MAX, $s);
                    break;
                case Pointer::BACKSPACE:
                    $this->backspace();
                    break;
                case Pointer::DELETE:
                    $this->deleteForward();
                    break;
                case Pointer::RETURN:
                case Pointer::KP_ENTER:
                    if ($this->props['readonly']) {
                        $used = false;
                    } else {
                        $this->newLine();
                    }
                    break;
                case Pointer::TAB:
                    if ($this->props['readonly']) {
                        $used = false;
                    } else {
                        $this->indent($s);
                    }
                    break;
                case self::F5:
                    $this->emit('runAll', $this->text());
                    break;
                case Pointer::ESCAPE:
                    $used = $this->anchor !== null;
                    $this->anchor = null;
                    $this->dirty = true;
                    break;
                default:
                    $used = false;
            }
        }
        if ($p->text !== [] && !$p->ctrl && !$this->props['readonly']) {
            $this->insert(implode('', $p->text));
            $used = true;
        }
        $this->blink = 0;
        $this->dirty = true;
        return $used;
    }

    private function moveWord(int $direction, bool $select): void
    {
        $line = $this->line;
        $col = $this->col;
        $chars = mb_str_split($this->lines[$line]) ?: [];
        if ($direction < 0) {
            if ($col === 0) {
                $this->move($line - 1, PHP_INT_MAX, $select);
                return;
            }
            while ($col > 0 && !preg_match('/\w/u', $chars[$col - 1])) {
                $col--;
            }
            while ($col > 0 && preg_match('/\w/u', $chars[$col - 1])) {
                $col--;
            }
        } else {
            if ($col >= count($chars)) {
                $this->move($line + 1, 0, $select);
                return;
            }
            while ($col < count($chars) && !preg_match('/\w/u', $chars[$col])) {
                $col++;
            }
            while ($col < count($chars) && preg_match('/\w/u', $chars[$col])) {
                $col++;
            }
        }
        $this->move($line, $col, $select);
    }
}
