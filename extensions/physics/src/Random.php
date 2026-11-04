<?php

namespace JumPHP\Physics2d;

/** A seeded generator (Park-Miller) on floats: the same numbers with 64-bit and 32-bit PHP (the browser). */
final class Random
{
    private float $state = 1.0;

    public function __construct(int $seed = 1)
    {
        $this->seed($seed);
    }

    public function seed(int $seed): void
    {
        $s = fmod(abs((float)$seed), 2147483647.0);
        $this->state = $s < 1 ? 1.0 : $s;
    }

    /** 0 <= x < 1 */
    public function next(): float
    {
        $this->state = fmod($this->state * 16807.0, 2147483647.0);
        return ($this->state - 1) / 2147483646.0;
    }

    public function between(float $min, float $max): float
    {
        return $min + ($max - $min) * $this->next();
    }
}
