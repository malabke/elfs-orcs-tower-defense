<?php

namespace JumPHP\Collision;

final class Circle implements Shape
{
    public function __construct(public float $x, public float $y, public float $radius)
    {
        if ($radius <= 0) {
            throw new \InvalidArgumentException('A circle needs a radius above 0.');
        }
    }

    public function bounds(): array
    {
        return [$this->x - $this->radius, $this->y - $this->radius, $this->x + $this->radius, $this->y + $this->radius];
    }

    public function contains(float $x, float $y): bool
    {
        return ($x - $this->x) ** 2 + ($y - $this->y) ** 2 <= $this->radius ** 2;
    }

    public function center(): array
    {
        return [$this->x, $this->y];
    }

    /** Its centre goes there. */
    public function moveTo(float $x, float $y): static
    {
        [$this->x, $this->y] = [$x, $y];
        return $this;
    }

    public function translate(float $dx, float $dy): static
    {
        $this->x += $dx;
        $this->y += $dy;
        return $this;
    }
}
