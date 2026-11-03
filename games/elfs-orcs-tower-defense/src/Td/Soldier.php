<?php

namespace App\Td;

/**
 * A soldier of a barracks / stables: it waits at its post on the road, stops one enemy on the ground and fights
 * it; when it falls, it comes back after a while.
 */
final class Soldier
{
    public float $x;
    public float $y;
    public float $hp;
    public float $maxHp;
    public float $damage;
    public ?Enemy $target = null;
    /** seconds before it comes back (dead ones only) */
    public float $respawn = 0.0;
    public float $age = 0.0;
    public bool $facingLeft = false;

    public function __construct(public float $homeX, public float $homeY, array $stats)
    {
        $this->x = $homeX;
        $this->y = $homeY;
        $this->maxHp = $this->hp = (float)$stats['hp'];
        $this->damage = (float)$stats['damage'];
    }

    public function isAlive(): bool
    {
        return $this->hp > 0;
    }

    /** It is next to its enemy: the enemy is stopped. */
    public function isFighting(): bool
    {
        return $this->target !== null && $this->isAlive() && hypot($this->target->x - $this->x, $this->target->y - $this->y) <= 9;
    }

    /** New stats after an upgrade: same share of health. */
    public function update(array $stats): void
    {
        $ratio = $this->maxHp > 0 ? $this->hp / $this->maxHp : 1.0;
        $this->maxHp = (float)$stats['hp'];
        $this->hp = $this->isAlive() ? $this->maxHp * $ratio : 0.0;
        $this->damage = (float)$stats['damage'];
    }

    /** Walks towards ($x, $y) at 60 px/s. */
    public function walk(float $x, float $y, float $h): void
    {
        $dx = $x - $this->x;
        $dy = $y - $this->y;
        $d = hypot($dx, $dy);
        if ($d < 0.5) {
            return;
        }
        $move = min($d, 60 * $h);
        $this->x += $dx / $d * $move;
        $this->y += $dy / $d * $move;
        if (abs($dx) > 0.5) {
            $this->facingLeft = $dx < 0;
        }
        $this->age += $h;
    }
}
