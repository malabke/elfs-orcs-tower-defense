<?php

namespace JumPHP\Effects;

use JumPHP\Renderer\Renderer;
use JumPHP\Util\Color;

/** Darker edges, as a frame of translucent bands (the engine draws no gradients): `Vignette::render($r, 0.5)` last in render(). */
final class Vignette
{
    /**
     * @return list<array{0: int, 1: int, 2: int, 3: int, 4: int}> [x, y, width, height, alpha 0..255]: the outermost band is the darkest
     */
    public static function shapes(int $w, int $h, float $strength = 0.5, int $steps = 8): array
    {
        $strength = max(0.0, min(1.0, $strength));
        $steps = max(1, min(24, $steps));
        if ($strength <= 0) {
            return [];
        }
        $band = max(1, (int)round(min($w, $h) * 0.35 / $steps));
        $out = [];
        for ($i = 0; $i < $steps; $i++) {
            $alpha = (int)round($strength * 180 * ((($steps - $i) / $steps) ** 2) / 16) * 16;
            if ($alpha <= 0) {
                continue;
            }
            $inset = $i * $band;
            if ($w - 2 * $inset <= 0 || $h - 2 * $inset <= 0) {
                break;
            }
            $out[] = [$inset, $inset, $w - 2 * $inset, $band, $alpha];                      // top
            $out[] = [$inset, $h - $inset - $band, $w - 2 * $inset, $band, $alpha];           // bottom
            $out[] = [$inset, $inset + $band, $band, $h - 2 * ($inset + $band), $alpha];      // left
            $out[] = [$w - $inset - $band, $inset + $band, $band, $h - 2 * ($inset + $band), $alpha];  // right
        }
        return $out;
    }

    public static function render(Renderer $r, float $strength = 0.5, int $steps = 8, string $color = '#000000'): void
    {
        $c = Color::of($color);
        foreach (self::shapes($r->getWidth(), $r->getHeight(), $strength, $steps) as [$x, $y, $w, $h, $alpha]) {
            if ($w > 0 && $h > 0) {
                $r->fillRect($x, $y, $w, $h, $c->withAlpha($alpha));
            }
        }
    }
}
