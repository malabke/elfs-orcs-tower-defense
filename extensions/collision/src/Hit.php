<?php

namespace JumPHP\Collision;

/**
 * Two shapes overlap: the NORMAL points from the first to the second (a unit vector) and the DEPTH is how far they overlap along it. Move the
 * first by -normal * depth (or the second by +normal * depth) and they just touch: that is the minimum translation.
 */
final class Hit
{
    public function __construct(public readonly float $nx, public readonly float $ny, public readonly float $depth)
    {
    }

    /** @return array{0: float, 1: float} what to add to the FIRST shape's position to get it out */
    public function pushOutOfFirst(): array
    {
        return [-$this->nx * $this->depth, -$this->ny * $this->depth];
    }

    /** @return array{0: float, 1: float} what to add to the SECOND shape's position to get it out */
    public function pushOutOfSecond(): array
    {
        return [$this->nx * $this->depth, $this->ny * $this->depth];
    }

    /** The same hit seen from the other shape. */
    public function flipped(): self
    {
        return new self(-$this->nx, -$this->ny, $this->depth);
    }
}
