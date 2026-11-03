<?php

namespace App\Ui;

use JumPHP\Renderer\Renderer;
use JumPHP\Renderer\TextDrawer;

/**
 * Text that must fit a width: lines broken at spaces (anywhere between CJK characters), and a scale that
 * shrinks when one word is still too wide. Translations run 30-40 % longer than English: never assume a width.
 */
final class Text
{
    /** @return list<string> */
    public static function wrap(TextDrawer $font, string $text, float $maxWidth, float $scale = 1): array
    {
        $lines = [];
        foreach (explode("\n", $text) as $paragraph) {
            $line = '';
            foreach (self::words($paragraph) as $word) {
                $candidate = $line === '' ? ltrim($word) : $line . $word;
                if ($line !== '' && $font->width($candidate, $scale) > $maxWidth) {
                    $lines[] = rtrim($line);
                    $line = ltrim($word);
                } else {
                    $line = $candidate;
                }
            }
            $lines[] = rtrim($line);
        }
        // a single word wider than the box (German, Russian): cut it between letters
        $fitted = [];
        foreach ($lines as $line) {
            while ($line !== '' && $font->width($line, $scale) > $maxWidth) {
                $chars = preg_split('//u', $line, -1, PREG_SPLIT_NO_EMPTY) ?: [];
                $head = '';
                foreach ($chars as $char) {
                    if ($head !== '' && $font->width($head . $char, $scale) > $maxWidth) {
                        break;
                    }
                    $head .= $char;
                }
                $fitted[] = $head;
                $line = ltrim(substr($line, strlen($head)));
            }
            $fitted[] = $line;
        }
        return $fitted;
    }

    /** Draws wrapped text; returns the height used. */
    public static function draw(Renderer $r, TextDrawer $font, string $text, float $x, float $y, float $maxWidth,
                                array $color = [255, 255, 255], string $align = 'left', ?array $shadow = [0, 0, 0], float $scale = 1): int
    {
        $line = self::lineStep($font, $scale);
        $lines = self::wrap($font, $text, $maxWidth, $scale);
        foreach ($lines as $i => $row) {
            $font->draw($r, $row, $x, $y + $i * $line, $scale, $color, $align, $shadow);
        }
        return count($lines) * $line;
    }

    public static function height(TextDrawer $font, string $text, float $maxWidth, float $scale = 1): int
    {
        return count(self::wrap($font, $text, $maxWidth, $scale)) * self::lineStep($font, $scale);
    }

    /** From one line to the next: the font's own line skip, at most its height + 3 px (Press Start 2P's is twice its height). */
    public static function lineStep(TextDrawer $font, float $scale = 1): int
    {
        return min($font->lineHeight($scale), $font->height($scale) + (int)round(3 * $scale));
    }

    /** The biggest whole scale (at most $scale) at which one line of $text fits $maxWidth (at least 1). */
    public static function fitScale(TextDrawer $font, string $text, float $maxWidth, float $scale): float
    {
        while ($scale > 1 && $font->width($text, $scale) > $maxWidth) {
            $scale--;
        }
        return $scale;
    }

    /** Words with their leading space; each CJK character is a word of its own. @return list<string> */
    private static function words(string $text): array
    {
        $words = [];
        $word = '';
        foreach (preg_split('//u', $text, -1, PREG_SPLIT_NO_EMPTY) ?: [] as $char) {
            if ($char === ' ') {
                $word !== '' && $words[] = $word;
                $word = ' ';
            } elseif (MixedFont::isCjk($char)) {
                $word !== '' && $words[] = $word;
                $words[] = $char;
                $word = '';
            } else {
                $word .= $char;
            }
        }
        $word !== '' && $words[] = $word;
        return $words;
    }
}
