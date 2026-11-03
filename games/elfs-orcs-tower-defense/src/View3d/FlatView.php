<?php

namespace App\View3d;

/** The 2D fallback (no WebGL): the map is drawn flat under the top bar. */
final class FlatView implements Projection
{
    public function __construct(private float $top = 30.0)
    {
    }

    public function screen(float $x, float $y, float $height = 0.0): array
    {
        return [$x, $y + $this->top - $height * 0.5];
    }

    public function world(float $sx, float $sy): array
    {
        return [$sx, $sy - $this->top];
    }

    public function scale(float $x, float $y): float
    {
        return 1.0;
    }
}
