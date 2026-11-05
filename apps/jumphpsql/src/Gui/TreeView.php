<?php

namespace App\Gui;

use JumPHP\Gui\Pointer;
use JumPHP\Gui\Theme;
use JumPHP\Gui\Widget;
use JumPHP\Renderer\Renderer;
use JumPHP\Util\Color;

/**
 * A tree of nodes (connections > databases > tables), each with an icon, that open and close: a click on the arrow or a
 * double click opens a node, the arrows move, Right / Left open / close.
 * `nodes`: a flat list in order, each ['id', 'label', 'depth', 'icon', 'open' (bool), 'children' (bool: may have some),
 * 'detail' (a muted text after the label), 'color' (the icon's)]. The form fills the children when a node opens.
 * Events: select (array $node), toggle (array $node, bool $open), activate (array $node).
 *
 * @property list<array<string, mixed>> $nodes
 * @property string $selected
 */
class TreeView extends Widget
{
    public const DEFAULTS = ['nodes' => [], 'selected' => '', 'height' => 'fill'];

    public float $scrollY = 0;
    private float $dragFrom = 0;
    private float $dragged = 0;

    public static function events(): array
    {
        return ['select', 'toggle', 'activate', 'click'];
    }

    public function focusable(): bool
    {
        return $this->isEnabled();
    }

    protected function content(Theme $t, float $maxWidth, string $breakpoint): array
    {
        return [$t->px(200), $t->px(200)];
    }

    /** @return list<array<string, mixed>> the nodes shown (not inside a closed one) */
    public function visibleNodes(): array
    {
        $out = [];
        $closedDepth = PHP_INT_MAX;
        foreach ((array)$this->props['nodes'] as $node) {
            $depth = (int)($node['depth'] ?? 0);
            if ($depth > $closedDepth) {
                continue;
            }
            $closedDepth = PHP_INT_MAX;
            $out[] = $node;
            if (!empty($node['children']) && empty($node['open'])) {
                $closedDepth = $depth;
            }
        }
        return $out;
    }

    /** @return array<string, mixed>|null */
    public function node(string $id): ?array
    {
        foreach ((array)$this->props['nodes'] as $node) {
            if ($node['id'] === $id) {
                return $node;
            }
        }
        return null;
    }

    /** @return array<string, mixed>|null */
    public function selectedNode(): ?array
    {
        return $this->node((string)$this->props['selected']);
    }

    public function rowHeight(Theme $t): float
    {
        return round($t->font()->lineHeight() + $t->px(9));
    }

    private function clampScroll(Theme $t): void
    {
        $content = count($this->visibleNodes()) * $this->rowHeight($t) + $t->px(8);
        $this->scrollY = max(0, min($this->scrollY, $content - $this->h));
    }

    public function render(Renderer $r, Theme $t): void
    {
        $border = $this->focused ? $t->color('focus') : $t->color('border');
        $t->frame($r, $this->x, $this->y, $this->w, $this->h, $t->color('surface'), $border);
        $this->clampScroll($t);
        $font = $t->font();
        $muted = $t->font($t->fontSize * 0.85);
        $rh = $this->rowHeight($t);
        $b = max(1, round($t->scale));
        $top = $this->y + $t->px(4);
        foreach ($this->visibleNodes() as $i => $node) {
            $y = $top + $i * $rh - $this->scrollY;
            if ($y < $this->y + $b || $y + $rh > $this->y + $this->h - $b) {
                continue;
            }
            $indent = $this->x + $t->px(6) + (int)($node['depth'] ?? 0) * $t->px(16);
            if ($node['id'] === $this->props['selected']) {
                $r->fillRect($this->x + $b, $y, $this->w - 2 * $b, $rh, $this->focused ? $t->color('selection') : $t->color('hover'));
            }
            $cy = $y + $rh / 2;
            if (!empty($node['children'])) {
                $this->arrow($r, $indent + $t->px(5), $cy, $t->px(4), !empty($node['open']), $t->color('muted'));
            }
            $ix = $indent + $t->px(14);
            $this->icon($r, $t, (string)($node['icon'] ?? ''), $ix, $cy, $node['color'] ?? null);
            $tx = $ix + $t->px(22);
            $label = (string)($node['label'] ?? '');
            $room = $this->x + $this->w - $tx - $t->px(8);
            $label = $this->fit($font, $label, $room);
            $font->draw($r, $label, $tx, $cy - $font->height() / 2, 1, $t->color('text'));
            $detail = (string)($node['detail'] ?? '');
            if ($detail !== '') {
                $dx = $tx + $font->width($label) + $t->px(8);
                if ($dx + $muted->width($detail) < $this->x + $this->w - $t->px(6)) {
                    $muted->draw($r, $detail, $dx, $cy - $muted->height() / 2, 1, $t->color('muted'));
                }
            }
        }
        $content = count($this->visibleNodes()) * $rh + $t->px(8);
        if ($content > $this->h) {
            $barW = $t->px(4);
            $thumbH = max($t->px(20), $this->h * $this->h / $content);
            $thumbY = $this->y + ($this->h - $thumbH) * $this->scrollY / max(1, $content - $this->h);
            $t->box($r, $this->x + $this->w - $barW - $t->px(2), $thumbY, $barW, $thumbH, $t->color('border'), $barW / 2);
        }
    }

    private function fit(\JumPHP\Renderer\TextDrawer $font, string $text, float $width): string
    {
        if ($font->width($text) <= $width) {
            return $text;
        }
        while ($text !== '' && $font->width($text . '…') > $width) {
            $text = mb_substr($text, 0, -1);
        }
        return $text . '…';
    }

    private function arrow(Renderer $r, float $cx, float $cy, float $s, bool $open, Color $color): void
    {
        for ($i = 0; $i <= (int)$s; $i++) {
            if ($open) {      // ▼
                $r->fillRect($cx - $s + $i, $cy - $s / 2 + $i, 2 * ($s - $i) + 1, 1, $color);
            } else {          // ▶
                $r->fillRect($cx - $s / 2 + $i, $cy - $s + $i, 1, 2 * ($s - $i) + 1, $color);
            }
        }
    }

    /** Little drawn icons: server, database, table, view, folder. */
    private function icon(Renderer $r, Theme $t, string $icon, float $x, float $cy, mixed $color): void
    {
        $s = $t->px(16);
        $y = $cy - $s / 2;
        $u = $s / 16;
        switch ($icon) {
            case 'server':
            case 'server-off':
                $c = Color::of(is_string($color) && $color !== '' ? $color : '#3b6ef5');
                if ($icon === 'server-off') {
                    $c = $t->color('muted');
                }
                $t->box($r, $x + $u, $y + 2 * $u, 14 * $u, 5 * $u, $c, $u * 1.5);
                $t->box($r, $x + $u, $y + 9 * $u, 14 * $u, 5 * $u, $c, $u * 1.5);
                $r->fillRect($x + 11 * $u, $y + 4 * $u, 2 * $u, 1.5 * $u, $icon === 'server' ? Color::of('#3ddc84') : $t->color('surface'));
                $r->fillRect($x + 11 * $u, $y + 11 * $u, 2 * $u, 1.5 * $u, $icon === 'server' ? Color::of('#3ddc84') : $t->color('surface'));
                break;
            case 'database':
            case 'database-off':
                $c = Color::of($icon === 'database' ? '#e2a400' : '#b9b19a');
                $r->fillRect($x + 2 * $u, $y + 3 * $u, 12 * $u, 10 * $u, $c);
                $t->box($r, $x + 2 * $u, $y + $u, 12 * $u, 4 * $u, $c->withAlpha(255), 2 * $u);
                $t->box($r, $x + 2 * $u, $y + 11 * $u, 12 * $u, 4 * $u, $c, 2 * $u);
                $t->box($r, $x + 3 * $u, $y + 1.7 * $u, 10 * $u, 2.4 * $u, Color::of('#ffe08a'), $u);
                $r->fillRect($x + 2 * $u, $y + 7 * $u, 12 * $u, $u, Color::of('#b07f00'));
                break;
            case 'table':
                $c = Color::of('#4a90d9');
                $r->fillRect($x + $u, $y + 2 * $u, 14 * $u, 12 * $u, $c);
                $r->fillRect($x + 2 * $u, $y + 5 * $u, 12 * $u, 8 * $u, $t->color('surface'));
                $r->fillRect($x + 2 * $u, $y + 8.5 * $u, 12 * $u, $u, $c);
                $r->fillRect($x + 7.5 * $u, $y + 5 * $u, $u, 8 * $u, $c);
                break;
            case 'view':
                $c = Color::of('#8e5bd9');
                $r->fillRect($x + $u, $y + 2 * $u, 14 * $u, 12 * $u, $c);
                $r->fillRect($x + 2 * $u, $y + 5 * $u, 12 * $u, 8 * $u, $t->color('surface'));
                $r->fillCircle($x + 8 * $u, $y + 9 * $u, 2.5 * $u, $c);
                break;
            case 'folder':
            default:
                $c = Color::of('#9aa3b2');
                $r->fillRect($x + $u, $y + 3 * $u, 6 * $u, 3 * $u, $c);
                $r->fillRect($x + $u, $y + 5 * $u, 14 * $u, 9 * $u, $c);
                break;
        }
    }

    public function select(string $id): void
    {
        if ($this->props['selected'] === $id) {
            return;
        }
        $this->props['selected'] = $id;
        $this->reveal();
        $node = $this->node($id);
        if ($node !== null) {
            $this->emit('select', $node);
        }
    }

    private function reveal(): void
    {
        $t = $this->form?->theme;
        if ($t === null) {
            return;
        }
        foreach ($this->visibleNodes() as $i => $node) {
            if ($node['id'] === $this->props['selected']) {
                $rh = $this->rowHeight($t);
                $this->scrollY = max(min($this->scrollY, $i * $rh), ($i + 1) * $rh + $t->px(8) - $this->h);
                $this->clampScroll($t);
                return;
            }
        }
    }

    /** Opens or closes a node (the form adds its children on 'toggle'). */
    public function toggle(string $id, ?bool $open = null): void
    {
        $nodes = (array)$this->props['nodes'];
        foreach ($nodes as $i => $node) {
            if ($node['id'] === $id && !empty($node['children'])) {
                $open ??= empty($node['open']);
                if ((bool)($node['open'] ?? false) === $open) {
                    return;
                }
                $nodes[$i]['open'] = $open;
                $this->props['nodes'] = $nodes;
                $this->emit('toggle', $nodes[$i], $open);
                return;
            }
        }
    }

    public function wheel(float $notches): bool
    {
        $t = $this->form?->theme;
        if ($t === null) {
            return false;
        }
        $before = $this->scrollY;
        $this->scrollY -= $notches * 3 * $this->rowHeight($t);
        $this->clampScroll($t);
        return $before !== $this->scrollY;
    }

    public function pointerDown(Pointer $p): void
    {
        $this->dragFrom = $p->y;
        $this->dragged = 0;
    }

    public function pointerDrag(Pointer $p): void
    {
        $t = $this->form?->theme;
        if ($t === null) {
            return;
        }
        $dy = $p->y - $this->dragFrom;
        $this->dragFrom = $p->y;
        $this->dragged += abs($dy);
        if ($this->dragged > 6) {
            $this->scrollY -= $dy;
            $this->clampScroll($t);
        }
    }

    public function pointerUp(Pointer $p, bool $inside): void
    {
        $t = $this->form?->theme;
        if ($t === null || !$inside || $this->dragged > 6 || !$this->isEnabled()) {
            return;
        }
        $index = (int)floor(($p->y - $this->y - $t->px(4) + $this->scrollY) / $this->rowHeight($t));
        $nodes = $this->visibleNodes();
        $node = $nodes[$index] ?? null;
        if ($node === null) {
            return;
        }
        $indent = $this->x + $t->px(6) + (int)($node['depth'] ?? 0) * $t->px(16);
        $onArrow = !empty($node['children']) && $p->x >= $indent - $t->px(4) && $p->x <= $indent + $t->px(13);
        $this->select((string)$node['id']);
        if ($onArrow) {
            $this->toggle((string)$node['id']);
        } elseif ($p->double) {
            if (!empty($node['children']) && empty($node['open'])) {
                $this->toggle((string)$node['id'], true);
            }
            $this->emit('activate', $this->node((string)$node['id']) ?? $node);
        }
        $this->click($p);
    }

    public function keys(Pointer $p): bool
    {
        $nodes = $this->visibleNodes();
        $at = -1;
        foreach ($nodes as $i => $node) {
            if ($node['id'] === $this->props['selected']) {
                $at = $i;
            }
        }
        $used = false;
        foreach ($p->keys as $key) {
            $node = $nodes[$at] ?? null;
            switch ($key) {
                case Pointer::UP:
                    $at = max(0, $at - 1);
                    break;
                case Pointer::DOWN:
                    $at = min(count($nodes) - 1, $at + 1);
                    break;
                case Pointer::HOME:
                    $at = 0;
                    break;
                case Pointer::END:
                    $at = count($nodes) - 1;
                    break;
                case Pointer::RIGHT:
                    if ($node !== null && !empty($node['children'])) {
                        $this->toggle((string)$node['id'], true);
                    }
                    return true;
                case Pointer::LEFT:
                    if ($node !== null && !empty($node['open'])) {
                        $this->toggle((string)$node['id'], false);
                    } elseif ($node !== null) {
                        // to the parent
                        for ($i = $at - 1; $i >= 0; $i--) {
                            if (($nodes[$i]['depth'] ?? 0) < ($node['depth'] ?? 0)) {
                                $this->select((string)$nodes[$i]['id']);
                                break;
                            }
                        }
                    }
                    return true;
                case Pointer::RETURN:
                case Pointer::KP_ENTER:
                    if ($node !== null) {
                        if (!empty($node['children']) && empty($node['open'])) {
                            $this->toggle((string)$node['id'], true);
                        }
                        $this->emit('activate', $this->node((string)$node['id']) ?? $node);
                    }
                    return true;
                default:
                    continue 2;
            }
            $used = true;
            if (isset($nodes[$at])) {
                $this->select((string)$nodes[$at]['id']);
            }
        }
        return $used;
    }
}
