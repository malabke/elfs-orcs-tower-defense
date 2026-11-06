<?php

namespace JumPHP\Shorts;

use JumPHP\Renderer\Renderer;

/** Shapes the Renderer lacks, for these videos: arcs and rings with a gap, trails. */
final class Draw
{
    /** A circle outline from angle $from to $to (radians, clockwise on screen), $thickness pixels. */
    public static function arc(Renderer $r, float $cx, float $cy, float $radius, float $from, float $to, float $thickness, mixed $color): void
    {
        $span = $to - $from;
        if ($span <= 0 || $radius <= 0) {
            return;
        }
        $steps = max(8, (int)ceil(abs($span) * $radius / 4));
        $half = ($thickness - 1) / 2;
        for ($k = 0; $k < max(1, (int)ceil($thickness)); $k++) {
            $rr = $radius - $half + $k;
            $px = $cx + cos($from) * $rr;
            $py = $cy + sin($from) * $rr;
            for ($i = 1; $i <= $steps; $i++) {
                $a = $from + $span * $i / $steps;
                $x = $cx + cos($a) * $rr;
                $y = $cy + sin($a) * $rr;
                $r->drawLine((int)round($px), (int)round($py), (int)round($x), (int)round($y), $color);
                [$px, $py] = [$x, $y];
            }
        }
    }

    /** A ring with its gap of $gap radians centred on $angle left out. */
    public static function ring(Renderer $r, float $cx, float $cy, float $radius, float $angle, float $gap, float $thickness, mixed $color): void
    {
        if ($gap >= 2 * M_PI) {
            return;
        }
        $from = $angle + $gap / 2;
        self::arc($r, $cx, $cy, $radius, $from, $from + 2 * M_PI - $gap, $thickness, $color);
    }

    /**
     * A fading trail of positions (oldest first) as shrinking dots of $color ([r, g, b]).
     *
     * @param list<array{0: float, 1: float}> $points
     * @param array{0: int, 1: int, 2: int} $color
     */
    public static function trail(Renderer $r, array $points, float $radius, array $color): void
    {
        $n = count($points);
        foreach ($points as $i => [$x, $y]) {
            $k = ($i + 1) / max(1, $n);
            $r->fillCircle((int)round($x), (int)round($y), (int)max(1, round($radius * $k)), [$color[0], $color[1], $color[2], (int)(200 * $k)]);
        }
    }

    /** '#rrggbb' -> [r, g, b] */
    public static function rgb(string $hex): array
    {
        $hex = ltrim($hex, '#');
        return [hexdec(substr($hex, 0, 2)), hexdec(substr($hex, 2, 2)), hexdec(substr($hex, 4, 2))];
    }
}
