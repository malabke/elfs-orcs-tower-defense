<?php

namespace App\Td;

/**
 * Something a tower threw: it follows its target and hits it (or the ground where it was, when it died).
 */
final class Projectile
{
    public bool $done = false;
    public float $fromX;
    public float $fromY;

    /** @param array<string, mixed> $stats the tower's stats when it shot */
    public function __construct(public float $x, public float $y, public ?Enemy $target, public float $targetX,
                                public float $targetY, public readonly array $stats, public readonly bool $crit,
                                public readonly string $tower)
    {
        $this->fromX = $x;
        $this->fromY = $y;
    }

    public function kind(): string
    {
        return (string)($this->stats['projectile'] ?? 'arrow');
    }

    /** Moves; true when it arrived. */
    public function step(float $h): bool
    {
        if ($this->target !== null && !$this->target->dead && !$this->target->escaped) {
            $this->targetX = $this->target->x;
            $this->targetY = $this->target->y;
        } else {
            $this->target = null;
        }
        $dx = $this->targetX - $this->x;
        $dy = $this->targetY - $this->y;
        $dist = hypot($dx, $dy);
        $move = (float)$this->stats['speed'] * $h;
        if ($dist <= $move || $dist < 1) {
            $this->x = $this->targetX;
            $this->y = $this->targetY;
            return true;
        }
        $this->x += $dx / $dist * $move;
        $this->y += $dy / $dist * $move;
        return false;
    }
}
