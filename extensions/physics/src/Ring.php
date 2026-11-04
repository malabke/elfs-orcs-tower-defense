<?php

namespace JumPHP\Physics2d;

/**
 * A circle (or an arc) balls bounce on from the inside: it can turn ($spin, rad/s), have a gap ($gap, radians, centred
 * on $angle) a ball goes through, grow or shrink ($grow px/s, between $minRadius and $maxRadius), grow on each hit
 * ($growOnHit) and open its gap on each hit ($openOnHit). A ball farther than its radius has escaped it.
 * $solid = false: drawn, never hit (a finished ring).
 */
final class Ring
{
    public bool $solid = true;
    public int $hits = 0;
    /** @var array<string, mixed> */
    public array $data = [];

    public function __construct(
        public float $x,
        public float $y,
        public float $radius,
        public float $gap = 0,
        public float $angle = 0,
        public float $spin = 0,
        public float $grow = 0,
        public float $growOnHit = 0,
        public float $openOnHit = 0,
        public float $minRadius = 4,
        public float $maxRadius = 10000,
        public float $thickness = 3,
        public string $color = '#ffffff',
    ) {
    }

    /** Is the direction $a (radians, from the centre) inside the gap? */
    public function inGap(float $a): bool
    {
        if ($this->gap <= 0) {
            return false;
        }
        $d = fmod($a - $this->angle, 2 * M_PI);
        if ($d > M_PI) {
            $d -= 2 * M_PI;
        } elseif ($d < -M_PI) {
            $d += 2 * M_PI;
        }
        return abs($d) <= $this->gap / 2;
    }
}
