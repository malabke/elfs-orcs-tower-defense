<?php

namespace JumPHP\Collision;

/** A ray met a shape: how far along it (distance), the point, the surface's normal there (pointing back at the ray), and the shape. */
final class RayHit
{
    public function __construct(public readonly float $distance, public readonly float $x, public readonly float $y, public readonly float $nx, public readonly float $ny, public readonly Shape $shape, public readonly string|int|null $id = null)
    {
    }
}
