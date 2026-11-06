<?php

namespace JumPHP\Tiles;

/** Caves by cellular automaton: random noise, smoothed a few times, then only the biggest open area is kept (everything is reachable). */
final class Caves
{
    /** @return Grid 0 = floor, 1 = wall (the border is always wall) */
    public static function generate(int $width, int $height, int $seed = 1, float $fill = 0.45, int $steps = 4): Grid
    {
        $rng = new Rng($seed);
        $g = new Grid($width, $height, 1);
        for ($y = 1; $y < $height - 1; $y++) {
            for ($x = 1; $x < $width - 1; $x++) {
                $g->set($x, $y, $rng->chance($fill) ? 1 : 0);
            }
        }
        for ($i = 0; $i < $steps; $i++) {
            $next = new Grid($width, $height, 1);
            for ($y = 1; $y < $height - 1; $y++) {
                for ($x = 1; $x < $width - 1; $x++) {
                    $walls = 0;
                    for ($dy = -1; $dy <= 1; $dy++) {
                        for ($dx = -1; $dx <= 1; $dx++) {
                            $walls += $g->get($x + $dx, $y + $dy);
                        }
                    }
                    $next->set($x, $y, $walls >= 5 ? 1 : 0);
                }
            }
            $g = $next;
        }
        return self::keepBiggest($g);
    }

    /** Fills every open area but the biggest with wall. */
    public static function keepBiggest(Grid $g): Grid
    {
        $seen = [];
        $best = [];
        for ($y = 0; $y < $g->height; $y++) {
            for ($x = 0; $x < $g->width; $x++) {
                if ($g->get($x, $y) === 0 && !isset($seen["$x,$y"])) {
                    $region = $g->region($x, $y);
                    $seen += $region;
                    if (count($region) > count($best)) {
                        $best = $region;
                    }
                }
            }
        }
        $out = new Grid($g->width, $g->height, 1);
        foreach ($best as $key => $_) {
            [$x, $y] = array_map('intval', explode(',', $key));
            $out->set($x, $y, 0);
        }
        return $out;
    }
}
