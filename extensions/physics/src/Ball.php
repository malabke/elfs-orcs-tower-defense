<?php

namespace JumPHP\Physics2d;

/** A moving ball. $trail keeps its last positions (newest last) for drawing. */
final class Ball
{
    /** @var list<array{0: float, 1: float}> */
    public array $trail = [];
    public int $hits = 0;
    public bool $escaped = false;
    /** @var array<string, mixed> anything the game wants to keep with it (a name, a score...) */
    public array $data = [];

    public function __construct(
        public float $x,
        public float $y,
        public float $vx = 0,
        public float $vy = 0,
        public float $radius = 8,
        public string $color = '#ffffff',
        public int $trailLength = 24,
    ) {
    }

    public function speed(): float
    {
        return sqrt($this->vx * $this->vx + $this->vy * $this->vy);
    }
}
