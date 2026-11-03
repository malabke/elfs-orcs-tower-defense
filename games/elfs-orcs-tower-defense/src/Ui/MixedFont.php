<?php

namespace App\Ui;

use JumPHP\Renderer\Renderer;
use JumPHP\Renderer\TextDrawer;
use JumPHP\Util\Color;

/**
 * Two fonts as one: the characters of the CJK blocks (kana, hanzi / kanji, hangul) with the fallback font,
 * everything else with the main one. The language menu writes "日本語" in a Latin language this way.
 */
final class MixedFont implements TextDrawer
{
    public function __construct(private TextDrawer $main, private TextDrawer $fallback)
    {
    }

    public function draw(Renderer $r, string $text, float $x, float $y, float $scale = 1, Color|array|string $color = [255, 255, 255],
                         string $align = 'left', Color|array|string|null $shadow = null): void
    {
        $line = $this->lineHeight($scale);
        foreach (explode("\n", $text) as $n => $row) {
            $w = $this->width($row, $scale);
            $cx = $align === 'center' ? $x - $w / 2 : ($align === 'right' ? $x - $w : $x);
            foreach (self::runs($row) as [$cjk, $run]) {
                $font = $cjk ? $this->fallback : $this->main;
                // runs share a vertical middle
                $dy = ($line - $font->lineHeight($scale)) / 2;
                $font->draw($r, $run, $cx, $y + $n * $line + $dy, $scale, $color, 'left', $shadow);
                $cx += $font->width($run, $scale);
            }
        }
    }

    public function width(string $text, float $scale = 1): int
    {
        $max = 0;
        foreach (explode("\n", $text) as $row) {
            $w = 0;
            foreach (self::runs($row) as [$cjk, $run]) {
                $w += ($cjk ? $this->fallback : $this->main)->width($run, $scale);
            }
            $max = max($max, $w);
        }
        return $max;
    }

    public function height(float $scale = 1): int
    {
        return max($this->main->height($scale), $this->fallback->height($scale));
    }

    public function lineHeight(float $scale = 1): int
    {
        return max($this->main->lineHeight($scale), $this->fallback->lineHeight($scale));
    }

    public static function isCjk(string $char): bool
    {
        return mb_ord($char, 'UTF-8') >= 0x2E80;
    }

    /** @return list<array{0: bool, 1: string}> pieces of text of one font */
    private static function runs(string $text): array
    {
        $runs = [];
        foreach (preg_split('//u', $text, -1, PREG_SPLIT_NO_EMPTY) ?: [] as $char) {
            $cjk = self::isCjk($char);
            $last = count($runs) - 1;
            if ($last >= 0 && $runs[$last][0] === $cjk) {
                $runs[$last][1] .= $char;
            } else {
                $runs[] = [$cjk, $char];
            }
        }
        return $runs;
    }
}
