<?php

namespace Tests;

use App\Ui\Text;
use JumPHP\Renderer\Renderer;
use JumPHP\Renderer\TextDrawer;
use JumPHP\Util\Color;
use PHPUnit\Framework\TestCase;

/**
 * Text that must fit a box: lines broken at spaces, too long words cut between letters.
 */
final class TextTest extends TestCase
{
    /** 8 px per character, like the pixel font. */
    private static function font(): TextDrawer
    {
        return new class implements TextDrawer {
            public function draw(Renderer $r, string $text, float $x, float $y, float $scale = 1, Color|array|string $color = [255, 255, 255],
                                 string $align = 'left', Color|array|string|null $shadow = null): void
            {
            }

            public function width(string $text, float $scale = 1): int
            {
                return (int)(mb_strlen($text) * 8 * $scale);
            }

            public function height(float $scale = 1): int
            {
                return (int)(8 * $scale);
            }

            public function lineHeight(float $scale = 1): int
            {
                return (int)(10 * $scale);
            }
        };
    }

    public function testLinesBreakAtSpaces(): void
    {
        self::assertSame(['RIDERS THAT', 'HOLD THE', 'ROAD'], Text::wrap(self::font(), 'RIDERS THAT HOLD THE ROAD', 96));
    }

    public function testAWordWiderThanTheBoxIsCut(): void
    {
        $lines = Text::wrap(self::font(), 'ВСАДНИКИ, ПЕРЕКРЫВАЮЩИЕ ДОРОГУ', 80);
        foreach ($lines as $line) {
            self::assertLessThanOrEqual(80, self::font()->width($line), $line);
        }
        // no letter lost
        self::assertSame('ВСАДНИКИ,ПЕРЕКРЫВАЮЩИЕДОРОГУ', str_replace(' ', '', implode('', $lines)));
    }

    public function testCjkTextBreaksBetweenCharacters(): void
    {
        $lines = Text::wrap(self::font(), '道をふさぐ騎兵', 24);
        self::assertSame(['道をふ', 'さぐ騎', '兵'], $lines);
    }
}
