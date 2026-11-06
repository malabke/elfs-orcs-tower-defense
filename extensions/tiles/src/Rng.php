<?php

namespace JumPHP\Tiles;

/** A seeded random generator on floats (Lehmer's): the same seed gives the same numbers on every platform, the browser's 32-bit PHP included. */
final class Rng
{
    private float $state;

    public function __construct(int $seed = 1)
    {
        $this->state = (float)max(1, abs($seed) % 2147483646 + 1);
    }

    /** 0 <= x < 1 */
    public function float(): float
    {
        $this->state = fmod($this->state * 16807.0, 2147483647.0);
        return ($this->state - 1) / 2147483646.0;
    }

    /** @return int a whole number from $min to $max, both included */
    public function between(int $min, int $max): int
    {
        return $min + (int)floor($this->float() * ($max - $min + 1));
    }

    public function chance(float $p): bool
    {
        return $this->float() < $p;
    }

    /** @template T @param list<T> $list @return T */
    public function pick(array $list): mixed
    {
        return $list[$this->between(0, count($list) - 1)];
    }
}
