<?php

namespace JumPHP\Collision;

/** A shape in the plane: where it is (its bounding box), whether a point is in it, and a move. Circle, Box and the convex Polygon implement it. */
interface Shape
{
    /** @return array{0: float, 1: float, 2: float, 3: float} x1, y1, x2, y2 of the smallest box that holds it */
    public function bounds(): array;

    public function contains(float $x, float $y): bool;

    /** @return array{0: float, 1: float} its centre */
    public function center(): array;

    public function moveTo(float $x, float $y): static;

    public function translate(float $dx, float $dy): static;
}
