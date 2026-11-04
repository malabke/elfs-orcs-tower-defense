<?php

namespace JumPHP\Particles;

/**
 * A seeded generator (Park-Miller) on floats: the same numbers on the desktop (64-bit PHP) and in the browser
 * (32-bit integers), and it never touches mt_rand()'s global seed.
 */
final class Random
{
    private float $state;

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
