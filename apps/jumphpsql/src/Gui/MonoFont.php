<?php

namespace App\Gui;

use JumPHP\Renderer\Renderer;
use JumPHP\Renderer\Sdl;
use JumPHP\Util\Color;

/**
 * A monospaced font drawn glyph by glyph from strips rendered once per colour (assets/fonts/mono.ttf, Liberation Mono):
 * the grid and the SQL editor draw thousands of short texts, which a texture per text (TtfFont) would make slow.
 * Every character is `advance` pixels wide, so a column of text is a multiplication.
 */
final class MonoFont
{
    private const ASCII = ' !"#$%&\'()*+,-./0123456789:;<=>?@ABCDEFGHIJKLMNOPQRSTUVWXYZ[\\]^_`abcdefghijklmnopqrstuvwxyz{|}~';

    /** @var array<int, self> per pixel size */
    private static array $fonts = [];

    public int $advance = 8;
    public int $height = 16;
    private mixed $font = null;
    /** @var array<string, mixed> colour => the ASCII strip */
    private array $strips = [];
    /** @var array<string, array{0: mixed, 1: int}> colour|char => texture, width (the other characters) */
    private array $glyphs = [];

    public static function get(?Sdl $sdl, int $px): self
    {
        return self::$fonts[$px] ??= new self($sdl, $px);
    }

    private function __construct(private ?Sdl $sdl, int $px)
    {
        $root = defined('JUMPHP_ROOT') ? (string)JUMPHP_ROOT : (string)getcwd();
        $file = rtrim($root, '/') . '/assets/fonts/mono.ttf';
        if ($sdl !== null && function_exists('TTF_OpenFont') && is_file($file)) {
            if (function_exists('TTF_WasInit') && !\TTF_WasInit()) {
                \TTF_Init();
            }
            $font = \TTF_OpenFont($file, $px);
            if ($font) {
                $this->font = $font;
                $w = $h = 0;
                \TTF_SizeUTF8($font, 'M', $w, $h);
                $this->advance = max(1, (int)$w);
                $this->height = (int)\TTF_FontHeight($font);
                return;
            }
        }
        $this->advance = max(1, (int)round($px * 0.6));
        $this->height = (int)round($px * 1.2);
    }

    public function width(string $text): int
    {
        return mb_strlen($text) * $this->advance;
    }

    /** How many characters fit in a width. */
    public function fits(float $width): int
    {
        return max(0, (int)floor($width / $this->advance));
    }

    /** One line (control characters shown as spaces), cut at $maxChars (0: no limit) with "…". */
    public function draw(Renderer $r, string $text, float $x, float $y, Color|array|string $color, int $maxChars = 0): void
    {
        if ($this->font === null || $text === '') {
            return;
        }
        $chars = mb_str_split($text) ?: [];
        if ($maxChars > 0 && count($chars) > $maxChars) {
            $chars = array_slice($chars, 0, max(0, $maxChars - 1));
            $chars[] = '…';
        }
        $c = Color::of($color);
        $strip = $this->strip($c);
        $x = round($x);
        $y = round($y);
        foreach ($chars as $char) {
            if ($char !== ' ') {
                $at = strpos(self::ASCII, $char);
                if ($at !== false && strlen($char) === 1) {
                    $r->copy($strip, [$at * $this->advance, 0, $this->advance, $this->height], $x, $y, $this->advance, $this->height);
                } elseif (ord($char[0]) >= 32 || strlen($char) > 1) {
                    [$texture, $w] = $this->glyph($c, $char);
                    if ($texture) {
                        $r->copy($texture, null, $x, $y, $w, $this->height);
                    }
                }
            }
            $x += $this->advance;
        }
    }

    private function strip(Color $c): mixed
    {
        $key = $c->key();
        if (!isset($this->strips[$key])) {
            $surface = \TTF_RenderUTF8_Blended($this->font, self::ASCII, new \SDL_Color($c->r, $c->g, $c->b, $c->a));
            $this->strips[$key] = \SDL_CreateTextureFromSurface($this->sdl->getRenderer()->getRenderer(), $surface);
            \SDL_FreeSurface($surface);
        }
        return $this->strips[$key];
    }

    /** @return array{0: mixed, 1: int} */
    private function glyph(Color $c, string $char): array
    {
        $key = $c->key() . '|' . $char;
        if (!isset($this->glyphs[$key])) {
            if (count($this->glyphs) > 2000) {
                foreach ($this->glyphs as [$texture]) {
                    $texture && \SDL_DestroyTexture($texture);
                }
                $this->glyphs = [];
            }
            $surface = \TTF_RenderUTF8_Blended($this->font, $char, new \SDL_Color($c->r, $c->g, $c->b, $c->a));
            if (!$surface) {
                return $this->glyphs[$key] = [null, 0];
            }
            $texture = \SDL_CreateTextureFromSurface($this->sdl->getRenderer()->getRenderer(), $surface);
            $w = $h = $f = $a = 0;
            \SDL_QueryTexture($texture, $f, $a, $w, $h);
            \SDL_FreeSurface($surface);
            $this->glyphs[$key] = [$texture, min((int)$w, $this->advance * 2)];
        }
        return $this->glyphs[$key];
    }
}
