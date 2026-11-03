<?php

namespace App\View3d;

/**
 * Between the map (x, y in pixels of the 480 x 240 play area, a height above the ground) and the screen (the 480 x 270
 * view): the 3D camera, or the flat view of the 2D fallback. The scene reads clicks and places its 2D overlays
 * (ring menu, bars, numbers) through it.
 */
interface Projection
{
    /** Where a point of the map is on the screen. @return array{0: float, 1: float} */
    public function screen(float $x, float $y, float $height = 0.0): array;

    /** The point of the ground under a point of the screen. @return array{0: float, 1: float} */
    public function world(float $sx, float $sy): array;

    /** Screen pixels for one map pixel at that point of the ground (sideways). */
    public function scale(float $x, float $y): float;
}
