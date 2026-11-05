<?php

namespace App\Gui;

use JumPHP\Gui\Pointer;
use JumPHP\Gui\Theme;
use JumPHP\Gui\Widget;
use JumPHP\Native\Clipboard;
use JumPHP\Renderer\Renderer;
use JumPHP\Util\Color;

/**
 * A grid of rows and columns, like a spreadsheet: the rows of a table or of a query. Header (a click sorts, its edges
 * resize the columns), row numbers, both scroll bars, the wheel (Shift: sideways), the arrows, Page up / down.
 * `editable`: a double click, Return or F2 edits a cell in place (Return keeps it, Escape forgets it, Tab goes to the next
 * one, Ctrl+0 sets NULL); the changes wait in `changes` / `rowStatus` until the form saves them.
 * Events: select (int $row, int $col), edit (int $row, int $col, ?string $value), activate (int $row), sort (int $col),
 * check (int $row, bool $checked).
 *
 * @property list<string> $columns
 * @property list<list<?string>> $rows
 * @property bool $editable
 * @property int $sortColumn
 * @property bool $sortDesc
 * @property string $emptyText
 */
class DataGrid extends Widget
{
    public const DEFAULTS = ['columns' => [], 'rows' => [], 'numeric' => [], 'editable' => false, 'checkable' => false,
        'rowNumbers' => true, 'sortColumn' => -1, 'sortDesc' => false, 'readonlyColumns' => [], 'emptyText' => 'No rows',
        'height' => 'fill', 'fontSize' => 13];

    public int $selRow = -1;
    public int $selCol = 0;
    public float $scrollX = 0;
    public float $scrollY = 0;
    /** @var array<int, float> column => width in dp (resized by the user) */
    public array $widths = [];
    /** @var array<int, bool> */
    public array $checked = [];
    /** @var array<int, array<int, ?string>> row => column => new value, not saved yet */
    public array $changes = [];
    /** @var array<int, string> row => 'new' | 'deleted' */
    public array $rowStatus = [];
    /** @var array{row: int, col: int, text: string, cursor: int}|null the cell being typed in */
    public ?array $editing = null;

    private mixed $texture = null;
    /** @var array{0: int, 1: int} */
    private array $textureSize = [0, 0];
    private bool $dirty = true;
    /** @var array<int, float> auto widths (dp), from the data */
    private array $auto = [];
    private ?string $drag = null;
    private float $dragFrom = 0;
    private float $dragStart = 0;
    private int $resizing = -1;
    private float $blink = 0;

    public static function events(): array
    {
        return ['select', 'edit', 'activate', 'sort', 'check', 'click'];
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
        if ($name === 'columns' || $name === 'rows') {
            $this->auto = [];
        }
        $this->dirty = true;
        parent::changed($name, $old);
    }

    /**
     * New data: the selection, the changes and the scroll start again (unless $keepScroll).
     *
     * @param list<string> $columns
     * @param list<list<?string>> $rows
     * @param list<bool> $numeric
     */
    public function setData(array $columns, array $rows, array $numeric = [], bool $keepScroll = false): void
    {
        if ($columns !== $this->props['columns']) {
            $this->widths = [];
        }
        $this->props['columns'] = $columns;
        $this->props['rows'] = $rows;
        $this->props['numeric'] = $numeric;
        $this->auto = [];
        $this->changes = [];
        $this->rowStatus = [];
        $this->checked = [];
        $this->editing = null;
        if (!$keepScroll) {
            $this->scrollX = $this->scrollY = 0;
            $this->selRow = -1;
            $this->selCol = 0;
        } else {
            $this->selRow = min($this->selRow, count($rows) - 1);
        }
        $this->dirty = true;
        $this->form?->invalidate();
    }

    public function rowCount(): int
    {
        return count((array)$this->props['rows']);
    }

    /** A cell as shown (with its change). */
    public function value(int $row, int $col): ?string
    {
        if (isset($this->changes[$row]) && array_key_exists($col, $this->changes[$row])) {
            return $this->changes[$row][$col];
        }
        return $this->props['rows'][$row][$col] ?? null;
    }

    /** The original value of a cell (before its change). */
    public function original(int $row, int $col): ?string
    {
        return $this->props['rows'][$row][$col] ?? null;
    }

    public function setValue(int $row, int $col, ?string $value): void
    {
        if ($row < 0 || $row >= $this->rowCount() || in_array($col, (array)$this->props['readonlyColumns'], true)) {
            return;
        }
        if ($value === $this->original($row, $col) && ($this->rowStatus[$row] ?? '') !== 'new') {
            unset($this->changes[$row][$col]);
            if (($this->changes[$row] ?? null) === []) {
                unset($this->changes[$row]);
            }
        } else {
            $this->changes[$row][$col] = $value;
        }
        $this->dirty = true;
        $this->emit('edit', $row, $col, $value);
    }

    public function hasChanges(): bool
    {
        return $this->changes !== [] || $this->rowStatus !== [];
    }

    /** A new empty row at the end, selected, its first cell edited. */
    public function addRow(?array $values = null): int
    {
        $rows = (array)$this->props['rows'];
        $row = $values ?? array_fill(0, count((array)$this->props['columns']), null);
        $rows[] = $row;
        $this->props['rows'] = $rows;
        $index = count($rows) - 1;
        $this->rowStatus[$index] = 'new';
        $this->changes[$index] = [];
        $this->auto = [];
        $this->selectCell($index, 0);
        $this->dirty = true;
        return $index;
    }

    /** Marks a row to delete (or forgets a new one); again: not deleted any more. */
    public function toggleDeleted(int $row): void
    {
        if ($row < 0 || $row >= $this->rowCount()) {
            return;
        }
        if (($this->rowStatus[$row] ?? '') === 'new') {
            $rows = (array)$this->props['rows'];
            array_splice($rows, $row, 1);
            $this->props['rows'] = $rows;
            unset($this->rowStatus[$row], $this->changes[$row]);
            $this->rowStatus = $this->shiftKeys($this->rowStatus, $row);
            $this->changes = $this->shiftKeys($this->changes, $row);
            $this->selRow = min($row, count($rows) - 1);
        } elseif (($this->rowStatus[$row] ?? '') === 'deleted') {
            unset($this->rowStatus[$row]);
        } else {
            $this->rowStatus[$row] = 'deleted';
        }
        $this->dirty = true;
    }

    /** @param array<int, mixed> $map @return array<int, mixed> */
    private function shiftKeys(array $map, int $removed): array
    {
        $out = [];
        foreach ($map as $k => $v) {
            $out[$k > $removed ? $k - 1 : $k] = $v;
        }
        return $out;
    }

    public function discardChanges(): void
    {
        $rows = [];
        foreach ((array)$this->props['rows'] as $i => $row) {
            if (($this->rowStatus[$i] ?? '') !== 'new') {
                $rows[] = $row;
            }
        }
        $this->props['rows'] = $rows;
        $this->changes = [];
        $this->rowStatus = [];
        $this->editing = null;
        $this->selRow = min($this->selRow, count($rows) - 1);
        $this->dirty = true;
    }

    public function redraw(): void
    {
        $this->dirty = true;
    }

    // geometry -----------------------------------------------------------------------------------------------------

    private function theme(): ?Theme
    {
        return $this->form?->theme;
    }

    private function font(Theme $t): MonoFont
    {
        return MonoFont::get($this->form?->sdlOrNull(), max(6, (int)round((float)$this->props['fontSize'] * $t->scale)));
    }

    public function rowHeight(Theme $t): float
    {
        return round($this->font($t)->height + $t->px(7));
    }

    private function numbersWidth(Theme $t): float
    {
        if (!$this->props['rowNumbers']) {
            return 0;
        }
        return strlen((string)max(1, $this->rowCount())) * $this->font($t)->advance + $t->px(16);
    }

    private function checkWidth(Theme $t): float
    {
        return $this->props['checkable'] ? $t->px(28) : 0;
    }

    /** @return list<float> column widths in pixels */
    public function columnWidths(Theme $t): array
    {
        $font = $this->font($t);
        $columns = (array)$this->props['columns'];
        if (count($this->auto) !== count($columns)) {
            $this->auto = [];
            $sample = array_slice((array)$this->props['rows'], 0, 200);
            foreach ($columns as $i => $name) {
                $chars = mb_strlen((string)$name) + 2;
                foreach ($sample as $row) {
                    $chars = max($chars, min(40, mb_strlen((string)($row[$i] ?? '(NULL)'))));
                }
                $this->auto[$i] = ($chars * $font->advance + $t->px(14)) / $t->scale;
            }
        }
        $out = [];
        foreach ($columns as $i => $name) {
            $out[] = $t->px(max(36, $this->widths[$i] ?? min(360, $this->auto[$i])));
        }
        return $out;
    }

    /** @return array{0: float, 1: float, 2: float, 3: float} the cells' area: x, y, w, h (without header and bars) */
    private function body(Theme $t): array
    {
        $bar = $t->px(10);
        $header = $this->rowHeight($t) + $t->px(2);
        $left = $this->numbersWidth($t) + $this->checkWidth($t);
        return [$this->x + $left, $this->y + $header, max(0, $this->w - $left - $bar), max(0, $this->h - $header - $bar)];
    }

    private function contentWidth(Theme $t): float
    {
        return array_sum($this->columnWidths($t));
    }

    private function contentHeight(Theme $t): float
    {
        return $this->rowCount() * $this->rowHeight($t);
    }

    private function clampScroll(Theme $t): void
    {
        [, , $bw, $bh] = $this->body($t);
        $this->scrollX = max(0, min($this->scrollX, $this->contentWidth($t) - $bw));
        $this->scrollY = max(0, min($this->scrollY, $this->contentHeight($t) - $bh));
    }

    protected function content(Theme $t, float $maxWidth, string $breakpoint): array
    {
        return [$t->px(200), $t->px(160)];
    }

    // drawing ------------------------------------------------------------------------------------------------------

    public function update(float $dt): void
    {
        if ($this->editing !== null) {
            $this->blink = fmod($this->blink + $dt, 1.0);
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
        $key = $this->focused ? 1 : 0;
        if ($this->dirty || $this->lastFocus !== $key) {
            $this->lastFocus = $key;
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
        $this->drawEditor($r, $t);
    }

    private int $lastFocus = -1;

    private function draw(Renderer $r, Theme $t): void
    {
        $this->clampScroll($t);
        $font = $this->font($t);
        $surface = $t->color('surface');
        $r->clear($surface);
        $r->fillRect($this->x, $this->y, $this->w, $this->h, $surface);
        $rh = $this->rowHeight($t);
        $hh = $rh + $t->px(2);
        [$bx, $by, $bw, $bh] = $this->body($t);
        $widths = $this->columnWidths($t);
        $columns = (array)$this->props['columns'];
        $rows = (array)$this->props['rows'];
        $numeric = (array)$this->props['numeric'];
        $line = $t->color('border');
        $headerBg = $t->color('background');
        $text = $t->color('text');
        $muted = $t->color('muted');
        $dark = $t->colors['surface'] === Theme::DARK['surface'];
        $changedBg = Color::of($dark ? '#4b4220' : '#fff3c4');
        $newBg = Color::of($dark ? '#203f2a' : '#dcf5e3');
        $deletedBg = Color::of($dark ? '#4a2626' : '#fbdcdc');
        $selection = $t->color('selection');
        $cellSel = $this->focused ? $t->color('accent') : $t->color('muted');
        $pad = $t->px(7);
        $ty = ($rh - $font->height) / 2;

        // rows
        $first = (int)floor($this->scrollY / $rh);
        $last = min(count($rows) - 1, (int)floor(($this->scrollY + $bh) / $rh));
        for ($i = $first; $i <= $last; $i++) {
            $y = $by + $i * $rh - $this->scrollY;
            $status = $this->rowStatus[$i] ?? '';
            if ($i === $this->selRow) {
                $r->fillRect($this->x, $y, $this->w, $rh, $selection);
            } elseif ($status === 'new') {
                $r->fillRect($bx, $y, $bw, $rh, $newBg);
            } elseif ($status === 'deleted') {
                $r->fillRect($bx, $y, $bw, $rh, $deletedBg);
            } elseif ($i % 2 === 1) {
                $r->fillRect($bx, $y, $bw, $rh, $t->color('background')->withAlpha(110));
            }
            $x = $bx - $this->scrollX;
            foreach ($widths as $c => $cw) {
                if ($x + $cw >= $bx && $x <= $bx + $bw) {
                    $changed = isset($this->changes[$i]) && array_key_exists($c, $this->changes[$i]);
                    if ($changed && $status !== 'new') {
                        $r->fillRect($x, $y, $cw, $rh, $changedBg);
                    }
                    if ($i === $this->selRow && $c === $this->selCol) {
                        $r->drawRect($x, $y, $cw, $rh, $cellSel, max(1, (int)round($t->scale * 1.5)));
                    }
                    $value = $this->value($i, $c);
                    $shown = $value === null ? '(NULL)' : str_replace(["\r\n", "\n", "\r", "\t"], ['↵', '↵', '↵', ' '], $value);
                    $max = $font->fits($cw - 2 * $pad);
                    $color = $value === null || $status === 'deleted' ? $muted : $text;
                    $tx = !empty($numeric[$c]) && $value !== null ? $x + $cw - $pad - min(mb_strlen($shown), $max) * $font->advance : $x + $pad;
                    $font->draw($r, $shown, $tx, $y + $ty, $color, $max);
                }
                $x += $cw;
            }
            $r->fillRect($bx, $y + $rh - 1, $bw, 1, $line->withAlpha(90));
        }
        if ($rows === []) {
            $t->font($t->fontSize * 0.9)->draw($r, (string)$this->props['emptyText'], $this->x + $this->w / 2, $by + $t->px(20), 1, $muted, 'center');
        }
        // column lines
        $x = $bx - $this->scrollX;
        foreach ($widths as $cw) {
            $x += $cw;
            if ($x > $bx && $x < $bx + $bw) {
                $r->fillRect($x - 1, $by, 1, min($bh, count($rows) * $rh - $this->scrollY), $line->withAlpha(110));
            }
        }
        // header
        $r->fillRect($this->x, $this->y, $this->w, $hh, $headerBg);
        $hfont = $t->font((float)$this->props['fontSize'] * 0.98, true);
        $x = $bx - $this->scrollX;
        foreach ($widths as $c => $cw) {
            if ($x + $cw >= $bx && $x <= $bx + $bw) {
                $name = (string)($columns[$c] ?? '');
                $arrow = (int)$this->props['sortColumn'] === $c ? ($this->props['sortDesc'] ? ' ▼' : ' ▲') : '';
                $label = $this->fitText($hfont, $name, $cw - 2 * $pad - ($arrow !== '' ? $hfont->width($arrow) : 0)) . $arrow;
                $hx = max($x + $pad, $bx + $pad);
                if ($hx + $hfont->width($label) <= $bx + $bw || $x + $pad >= $bx) {
                    $hfont->draw($r, $label, $x + $pad, $this->y + ($hh - $hfont->height()) / 2, 1, $text);
                }
                $r->fillRect($x + $cw - 1, $this->y + $t->px(5), 1, $hh - $t->px(10), $line);
            }
            $x += $cw;
        }
        // the parts left of the cells, drawn over what slid under them
        $left = $this->numbersWidth($t) + $this->checkWidth($t);
        if ($left > 0) {
            $r->fillRect($this->x, $this->y, $left, $this->h, $headerBg);
            $r->fillRect($this->x, $this->y, $left, $hh, $headerBg);
            for ($i = $first; $i <= $last; $i++) {
                $y = $by + $i * $rh - $this->scrollY;
                if ($i === $this->selRow) {
                    $r->fillRect($this->x, $y, $left, $rh, $selection);
                }
                $status = $this->rowStatus[$i] ?? '';
                $mark = $status === 'new' ? '+' : ($status === 'deleted' ? '−' : (isset($this->changes[$i]) ? '*' : ''));
                if ($this->props['rowNumbers']) {
                    $label = $mark !== '' ? $mark : (string)($i + 1);
                    $font->draw($r, $label, $this->x + $this->numbersWidth($t) - $t->px(8) - mb_strlen($label) * $font->advance, $y + $ty, $mark !== '' ? $t->color('accent') : $muted);
                }
                if ($this->props['checkable']) {
                    $s = $t->px(14);
                    $cx = $this->x + $this->numbersWidth($t) + ($this->checkWidth($t) - $s) / 2;
                    $cy = $y + ($rh - $s) / 2;
                    if (!empty($this->checked[$i])) {
                        $t->box($r, $cx, $cy, $s, $s, $t->color('accent'), $t->px(3));
                        $r->drawLine($cx + $s * 0.22, $cy + $s * 0.52, $cx + $s * 0.42, $cy + $s * 0.72, $t->color('accentText'));
                        $r->drawLine($cx + $s * 0.42, $cy + $s * 0.72, $cx + $s * 0.78, $cy + $s * 0.3, $t->color('accentText'));
                    } else {
                        $t->frame($r, $cx, $cy, $s, $s, $surface, $line, $t->px(3));
                    }
                }
            }
            $r->fillRect($this->x + $left - 1, $this->y, 1, $this->h, $line);
            if ($this->props['checkable']) {
                $all = $rows !== [] && count(array_filter($this->checked)) === count($rows);
                $s = $t->px(14);
                $cx = $this->x + $this->numbersWidth($t) + ($this->checkWidth($t) - $s) / 2;
                $all ? $t->box($r, $cx, $this->y + ($hh - $s) / 2, $s, $s, $t->color('accent'), $t->px(3))
                    : $t->frame($r, $cx, $this->y + ($hh - $s) / 2, $s, $s, $surface, $line, $t->px(3));
            }
        }
        $r->fillRect($this->x, $this->y + $hh - 1, $this->w, 1, $line);
        // bars
        $bar = $t->px(10);
        $r->fillRect($this->x + $this->w - $bar, $this->y, $bar, $this->h, $headerBg);
        $r->fillRect($this->x, $this->y + $this->h - $bar, $this->w, $bar, $headerBg);
        $ch = $this->contentHeight($t);
        if ($ch > $bh && $bh > 0) {
            $thumb = max($t->px(24), $bh * $bh / $ch);
            $ty2 = $by + ($bh - $thumb) * $this->scrollY / max(1, $ch - $bh);
            $t->box($r, $this->x + $this->w - $bar + $t->px(2), $ty2, $bar - $t->px(4), $thumb, $this->drag === 'v' ? $muted : $line, $t->px(3));
        }
        $cw2 = $this->contentWidth($t);
        if ($cw2 > $bw && $bw > 0) {
            $thumb = max($t->px(24), $bw * $bw / $cw2);
            $tx2 = $bx + ($bw - $thumb) * $this->scrollX / max(1, $cw2 - $bw);
            $t->box($r, $tx2, $this->y + $this->h - $bar + $t->px(2), $thumb, $bar - $t->px(4), $this->drag === 'h' ? $muted : $line, $t->px(3));
        }
        // the frame
        $border = $this->focused ? $t->color('focus') : $line;
        $b = max(1, round($t->scale));
        $r->fillRect($this->x, $this->y, $this->w, $b, $border);
        $r->fillRect($this->x, $this->y + $this->h - $b, $this->w, $b, $border);
        $r->fillRect($this->x, $this->y, $b, $this->h, $border);
        $r->fillRect($this->x + $this->w - $b, $this->y, $b, $this->h, $border);
    }

    private function fitText(\JumPHP\Renderer\TextDrawer $font, string $text, float $width): string
    {
        if ($font->width($text) <= $width) {
            return $text;
        }
        $lo = 0;
        $hi = mb_strlen($text);
        while ($lo < $hi) {
            $mid = intdiv($lo + $hi + 1, 2);
            if ($font->width(mb_substr($text, 0, $mid) . '…') <= $width) {
                $lo = $mid;
            } else {
                $hi = $mid - 1;
            }
        }
        return $lo > 0 ? mb_substr($text, 0, $lo) . '…' : '';
    }

    /** @return array{0: float, 1: float, 2: float, 3: float}|null a cell's box on the screen, null when hidden */
    public function cellBox(int $row, int $col): ?array
    {
        $t = $this->theme();
        if ($t === null) {
            return null;
        }
        [$bx, $by, $bw, $bh] = $this->body($t);
        $widths = $this->columnWidths($t);
        if (!isset($widths[$col])) {
            return null;
        }
        $x = $bx - $this->scrollX + array_sum(array_slice($widths, 0, $col));
        $rh = $this->rowHeight($t);
        $y = $by + $row * $rh - $this->scrollY;
        return [$x, $y, $widths[$col], $rh];
    }

    private function drawEditor(Renderer $r, Theme $t): void
    {
        if ($this->editing === null) {
            return;
        }
        $box = $this->cellBox($this->editing['row'], $this->editing['col']);
        if ($box === null) {
            return;
        }
        [$bx, $by, $bw] = $this->body($t);
        [$x, $y, $w, $h] = $box;
        $x = max($x, $bx);
        $w = max($t->px(160), min($w, $bx + $bw - $x));
        $font = $this->font($t);
        $pad = $t->px(7);
        $text = $this->editing['text'];
        $cursor = $this->editing['cursor'];
        $fit = max(1, $font->fits($w - 2 * $pad));
        $from = max(0, $cursor - $fit + 1);
        $t->box($r, $x - $t->px(1), $y - $t->px(1), $w + $t->px(2), $h + $t->px(2), $t->color('focus'), 0);
        $r->fillRect($x + $t->px(1), $y + $t->px(1), $w - $t->px(2), $h - $t->px(2), $t->color('surface'));
        $font->draw($r, mb_substr($text, $from, $fit), $x + $pad, $y + ($h - $font->height) / 2, $t->color('text'));
        if ($this->blink < 0.55) {
            $r->fillRect($x + $pad + ($cursor - $from) * $font->advance, $y + $t->px(3), max(1, $t->px(1.5)), $h - $t->px(6), $t->color('text'));
        }
    }

    // input --------------------------------------------------------------------------------------------------------

    public function wheel(float $notches): bool
    {
        $t = $this->theme();
        if ($t === null) {
            return false;
        }
        $shift = (\function_exists('SDL_GetModState') && ((int)\SDL_GetModState() & 3) !== 0);
        if ($shift) {
            $this->scrollX -= $notches * $t->px(60);
        } else {
            $this->scrollY -= $notches * 3 * $this->rowHeight($t);
        }
        $this->clampScroll($t);
        $this->dirty = true;
        return true;
    }

    public function pointerDown(Pointer $p): void
    {
        $t = $this->theme();
        if ($t === null) {
            return;
        }
        $this->commitEdit();
        [$bx, $by, $bw, $bh] = $this->body($t);
        $bar = $t->px(10);
        $this->drag = null;
        $this->resizing = -1;
        if ($p->x >= $this->x + $this->w - $bar) {
            $this->drag = 'v';
            $this->dragFrom = $p->y;
            $this->dragStart = $this->scrollY;
            $this->jumpBar('v', $p, $t);
        } elseif ($p->y >= $this->y + $this->h - $bar) {
            $this->drag = 'h';
            $this->dragFrom = $p->x;
            $this->dragStart = $this->scrollX;
            $this->jumpBar('h', $p, $t);
        } elseif ($p->y < $by && $p->x >= $bx) {
            // a column's right edge: resize it
            $x = $bx - $this->scrollX;
            foreach ($this->columnWidths($t) as $c => $cw) {
                $x += $cw;
                if (abs($p->x - $x) <= $t->px(5)) {
                    $this->resizing = $c;
                    $this->dragFrom = $p->x;
                    $this->dragStart = $cw / $t->scale;
                    break;
                }
            }
        }
        $this->dirty = true;
    }

    /** A press on a bar outside its thumb: the thumb jumps there. */
    private function jumpBar(string $axis, Pointer $p, Theme $t): void
    {
        [$bx, $by, $bw, $bh] = $this->body($t);
        if ($axis === 'v') {
            $ch = $this->contentHeight($t);
            if ($ch <= $bh) {
                return;
            }
            $thumb = max($t->px(24), $bh * $bh / $ch);
            $ty = $by + ($bh - $thumb) * $this->scrollY / max(1, $ch - $bh);
            if ($p->y < $ty || $p->y > $ty + $thumb) {
                $this->scrollY = ($p->y - $by - $thumb / 2) / max(1, $bh - $thumb) * ($ch - $bh);
            }
        } else {
            $cw = $this->contentWidth($t);
            if ($cw <= $bw) {
                return;
            }
            $thumb = max($t->px(24), $bw * $bw / $cw);
            $tx = $bx + ($bw - $thumb) * $this->scrollX / max(1, $cw - $bw);
            if ($p->x < $tx || $p->x > $tx + $thumb) {
                $this->scrollX = ($p->x - $bx - $thumb / 2) / max(1, $bw - $thumb) * ($cw - $bw);
            }
        }
        $this->clampScroll($t);
        $this->dragStart = $axis === 'v' ? $this->scrollY : $this->scrollX;
    }

    public function pointerDrag(Pointer $p): void
    {
        $t = $this->theme();
        if ($t === null) {
            return;
        }
        [, , $bw, $bh] = $this->body($t);
        if ($this->drag === 'v') {
            $ch = $this->contentHeight($t);
            $thumb = max($t->px(24), $bh * $bh / max(1, $ch));
            $this->scrollY = $this->dragStart + ($p->y - $this->dragFrom) * ($ch - $bh) / max(1, $bh - $thumb);
        } elseif ($this->drag === 'h') {
            $cw = $this->contentWidth($t);
            $thumb = max($t->px(24), $bw * $bw / max(1, $cw));
            $this->scrollX = $this->dragStart + ($p->x - $this->dragFrom) * ($cw - $bw) / max(1, $bw - $thumb);
        } elseif ($this->resizing >= 0) {
            $this->widths[$this->resizing] = max(36, $this->dragStart + ($p->x - $this->dragFrom) / $t->scale);
        } else {
            return;
        }
        $this->clampScroll($t);
        $this->dirty = true;
    }

    public function pointerUp(Pointer $p, bool $inside): void
    {
        $t = $this->theme();
        $wasDragging = $this->drag !== null || $this->resizing >= 0;
        $this->drag = null;
        $this->resizing = -1;
        $this->dirty = true;
        if ($t === null || !$inside || $wasDragging || !$this->isEnabled()) {
            return;
        }
        [$bx, $by] = $this->body($t);
        $left = $this->numbersWidth($t) + $this->checkWidth($t);
        if ($p->y < $by) {
            if ($p->x >= $bx) {
                $col = $this->columnAt($p->x, $t);
                if ($col >= 0) {
                    $this->emit('sort', $col);
                }
            } elseif ($this->props['checkable'] && $p->x >= $this->x + $this->numbersWidth($t)) {
                $all = count(array_filter($this->checked)) !== $this->rowCount();
                $this->checked = $all ? array_fill(0, $this->rowCount(), true) : [];
                $this->emit('check', -1, $all);
            }
            return;
        }
        $row = (int)floor(($p->y - $by + $this->scrollY) / $this->rowHeight($t));
        if ($row < 0 || $row >= $this->rowCount()) {
            return;
        }
        if ($this->props['checkable'] && $p->x < $this->x + $left && $p->x >= $this->x + $this->numbersWidth($t)) {
            $this->checked[$row] = empty($this->checked[$row]);
            $this->emit('check', $row, $this->checked[$row]);
            return;
        }
        $col = $p->x >= $bx ? $this->columnAt($p->x, $t) : $this->selCol;
        $this->selectCell($row, max(0, $col));
        if ($p->double) {
            if ($this->props['editable'] && $col >= 0) {
                $this->beginEdit();
            } else {
                $this->emit('activate', $row);
            }
        }
        $this->click($p);
    }

    private function columnAt(float $px, Theme $t): int
    {
        [$bx] = $this->body($t);
        $x = $bx - $this->scrollX;
        foreach ($this->columnWidths($t) as $c => $cw) {
            if ($px >= $x && $px < $x + $cw) {
                return $c;
            }
            $x += $cw;
        }
        return -1;
    }

    public function selectCell(int $row, int $col): void
    {
        $row = max(-1, min($this->rowCount() - 1, $row));
        $col = max(0, min(count((array)$this->props['columns']) - 1, $col));
        $changed = $row !== $this->selRow || $col !== $this->selCol;
        $this->selRow = $row;
        $this->selCol = $col;
        $this->reveal();
        $this->dirty = true;
        if ($changed) {
            $this->emit('select', $row, $col);
        }
    }

    /** The selected cell in sight. */
    private function reveal(): void
    {
        $t = $this->theme();
        if ($t === null || $this->selRow < 0) {
            return;
        }
        [, , $bw, $bh] = $this->body($t);
        $rh = $this->rowHeight($t);
        $this->scrollY = max(min($this->scrollY, $this->selRow * $rh), ($this->selRow + 1) * $rh - $bh);
        $widths = $this->columnWidths($t);
        $left = array_sum(array_slice($widths, 0, $this->selCol));
        $this->scrollX = max(min($this->scrollX, $left), $left + ($widths[$this->selCol] ?? 0) - $bw);
        $this->clampScroll($t);
    }

    public function beginEdit(?string $text = null): void
    {
        if (!$this->props['editable'] || $this->selRow < 0 || in_array($this->selCol, (array)$this->props['readonlyColumns'], true)
            || ($this->rowStatus[$this->selRow] ?? '') === 'deleted') {
            return;
        }
        $text ??= (string)$this->value($this->selRow, $this->selCol);
        $this->editing = ['row' => $this->selRow, 'col' => $this->selCol, 'text' => $text, 'cursor' => mb_strlen($text)];
        $this->blink = 0;
        if (\function_exists('SDL_StartTextInput') && $this->form?->sdlOrNull() !== null) {
            \SDL_StartTextInput();
        }
    }

    /** The cell being typed in takes its text. */
    public function commitEdit(): void
    {
        if ($this->editing === null) {
            return;
        }
        $e = $this->editing;
        $this->editing = null;
        $old = $this->value($e['row'], $e['col']);
        if ($e['text'] !== (string)$old || ($old === null && $e['text'] !== '')) {
            $this->setValue($e['row'], $e['col'], $e['text']);
        }
        $this->dirty = true;
    }

    public function cancelEdit(): void
    {
        $this->editing = null;
        $this->dirty = true;
    }

    public function blur(): void
    {
        $this->commitEdit();
        $this->dirty = true;
    }

    public function keys(Pointer $p): bool
    {
        if ($this->editing !== null) {
            return $this->editKeys($p);
        }
        $used = false;
        $t = $this->theme();
        $page = $t !== null ? max(1, (int)floor($this->body($t)[3] / $this->rowHeight($t)) - 1) : 10;
        foreach ($p->keys as $key) {
            if ($p->ctrl && $key === ord('c')) {
                if ($this->selRow >= 0) {
                    try {
                        Clipboard::copy((string)$this->value($this->selRow, $this->selCol));
                    } catch (\Throwable) {
                    }
                }
                $used = true;
                continue;
            }
            if ($p->ctrl && $key === ord('v') && $this->props['editable'] && $this->selRow >= 0) {
                [$row, $col] = [$this->selRow, $this->selCol];
                try {
                    Clipboard::paste()->then(fn (mixed $text) => $this->setValue($row, $col, (string)$text));
                } catch (\Throwable) {
                }
                $used = true;
                continue;
            }
            if ($p->ctrl && $key === ord('0') && $this->props['editable'] && $this->selRow >= 0) {
                $this->setValue($this->selRow, $this->selCol, null);
                $used = true;
                continue;
            }
            $row = $this->selRow;
            $col = $this->selCol;
            $to = match ($key) {
                Pointer::UP => [max(0, $row - 1), $col],
                Pointer::DOWN => [$row + 1, $col],
                Pointer::LEFT => [max(0, $row), $col - 1],
                Pointer::RIGHT => [max(0, $row), $col + 1],
                Pointer::PAGEUP => [max(0, $row - $page), $col],
                Pointer::PAGEDOWN => [$row + $page, $col],
                Pointer::HOME => [$p->ctrl ? 0 : max(0, $row), 0],
                Pointer::END => [$p->ctrl ? $this->rowCount() - 1 : max(0, $row), count((array)$this->props['columns']) - 1],
                default => null,
            };
            if ($to !== null) {
                $this->selectCell($to[0], $to[1]);
                $used = true;
            } elseif (($key === Pointer::RETURN || $key === Pointer::KP_ENTER || $key === self::F2) && $this->selRow >= 0) {
                $this->props['editable'] ? $this->beginEdit() : $this->emit('activate', $this->selRow);
                $used = true;
            } elseif ($key === Pointer::SPACE && $this->props['checkable'] && $this->selRow >= 0) {
                $this->checked[$this->selRow] = empty($this->checked[$this->selRow]);
                $this->emit('check', $this->selRow, $this->checked[$this->selRow]);
                $used = true;
            }
        }
        // typing over a selected cell starts editing it with what is typed
        if (!$used && $p->text !== [] && $this->props['editable'] && $this->selRow >= 0 && !$p->ctrl) {
            $this->beginEdit('');
            if ($this->editing !== null) {
                return $this->editKeys($p);
            }
        }
        if ($used) {
            $this->dirty = true;
        }
        return $used;
    }

    public const F2 = 1073741883;

    private function editKeys(Pointer $p): bool
    {
        $e = &$this->editing;
        $this->blink = 0;
        foreach ($p->keys as $key) {
            if ($this->editing === null) {
                break;
            }
            $len = mb_strlen($e['text']);
            switch ($key) {
                case Pointer::LEFT:
                    $e['cursor'] = max(0, $e['cursor'] - 1);
                    break;
                case Pointer::RIGHT:
                    $e['cursor'] = min($len, $e['cursor'] + 1);
                    break;
                case Pointer::HOME:
                    $e['cursor'] = 0;
                    break;
                case Pointer::END:
                    $e['cursor'] = $len;
                    break;
                case Pointer::BACKSPACE:
                    if ($e['cursor'] > 0) {
                        $e['text'] = mb_substr($e['text'], 0, $e['cursor'] - 1) . mb_substr($e['text'], $e['cursor']);
                        $e['cursor']--;
                    }
                    break;
                case Pointer::DELETE:
                    $e['text'] = mb_substr($e['text'], 0, $e['cursor']) . mb_substr($e['text'], $e['cursor'] + 1);
                    break;
                case Pointer::RETURN:
                case Pointer::KP_ENTER:
                    unset($e);
                    $this->commitEdit();
                    $this->selectCell($this->selRow + ($this->selRow < $this->rowCount() - 1 ? 1 : 0), $this->selCol);
                    return true;
                case Pointer::TAB:
                    unset($e);
                    $this->commitEdit();
                    $this->selectCell($this->selRow, $this->selCol + ($p->shift ? -1 : 1));
                    $this->beginEdit();
                    return true;
                case Pointer::ESCAPE:
                    unset($e);
                    $this->cancelEdit();
                    return true;
                case Pointer::UP:
                case Pointer::DOWN:
                    unset($e);
                    $this->commitEdit();
                    $this->selectCell($this->selRow + ($key === Pointer::UP ? -1 : 1), $this->selCol);
                    return true;
                default:
                    if ($p->ctrl && $key === ord('0')) {
                        unset($e);
                        $row = $this->selRow;
                        $col = $this->selCol;
                        $this->cancelEdit();
                        $this->setValue($row, $col, null);
                        return true;
                    }
                    if ($p->ctrl && $key === ord('v')) {
                        try {
                            Clipboard::paste()->then(function (mixed $text): void {
                                if ($this->editing !== null) {
                                    $insert = str_replace(["\r\n", "\r"], "\n", (string)$text);
                                    $c = $this->editing['cursor'];
                                    $this->editing['text'] = mb_substr($this->editing['text'], 0, $c) . $insert . mb_substr($this->editing['text'], $c);
                                    $this->editing['cursor'] += mb_strlen($insert);
                                }
                            });
                        } catch (\Throwable) {
                        }
                    }
            }
        }
        if ($this->editing !== null) {
            foreach ($p->text as $char) {
                $e['text'] = mb_substr($e['text'], 0, $e['cursor']) . $char . mb_substr($e['text'], $e['cursor']);
                $e['cursor']++;
            }
        }
        unset($e);
        return true;
    }
}
