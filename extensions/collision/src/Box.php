<?php

namespace JumPHP\Collision;

/** An axis-aligned rectangle: x, y its top-left corner. */
final class Box implements Shape
{
    public function __construct(public float $x, public float $y, public float $width, public float $height)
    {
        if ($width <= 0 || $height <= 0) {
            throw new \InvalidArgumentException('A box needs a width and a height above 0.');
        }
    }

    public function bounds(): array
    {
        return [$this->x, $this->y, $this->x + $this->width, $this->y + $this->height];
    }

    public function contains(float $x, float $y): bool
    {
        return $x >= $this->x && $x <= $this->x + $this->width && $y >= $this->y && $y <= $this->y + $this->height;
    }

    public function center(): array
    {
        return [$this->x + $this->width / 2, $this->y + $this->height / 2];
    }

    /** Its CENTRE goes there (like the other shapes). */
    public function moveTo(float $x, float $y): static
    {
        [$this->x, $this->y] = [$x - $this->width / 2, $y - $this->height / 2];
        return $this;
    }

    public function translate(float $dx, float $dy): static
    {
        $this->x += $dx;
        $this->y += $dy;
        return $this;
    }

    public function toPolygon(): Polygon
    {
        return new Polygon([[$this->x, $this->y], [$this->x + $this->width, $this->y], [$this->x + $this->width, $this->y + $this->height], [$this->x, $this->y + $this->height]]);
    }
}
