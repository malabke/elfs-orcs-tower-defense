<?php

namespace App\Td;

/**
 * The side's hero (heroes.yaml): sent anywhere on the road, it stops one enemy on the ground and fights it, shoots
 * when it has a ranged attack, and its skill (a blast around it) fires by itself when it is ready and enemies crowd
 * around. Fallen, it comes back at its post after a while.
 */
final class Hero
{
    public float $x;
    public float $y;
    /** where it walks to (a point of the road) */
    public float $destX;
    public float $destY;
    public float $hp;
    public readonly float $maxHp;
    public ?Enemy $target = null;
    public float $respawn = 0.0;
    public float $cooldown = 0.0;
    /** seconds before its skill is ready */
    public float $skillTimer = 0.0;
    public float $age = 0.0;
    public bool $facingLeft = false;
    public float $sinceSkill = 99.0;

    public function __construct(public readonly array $def, public float $homeX, public float $homeY)
    {
        $this->x = $this->destX = $homeX;
        $this->y = $this->destY = $homeY;
        $this->hp = $this->maxHp = (float)$def['hp'];
    }

    public function isAlive(): bool
    {
        return $this->hp > 0;
    }

    public function isWalking(): bool
    {
        return hypot($this->destX - $this->x, $this->destY - $this->y) > 1;
    }

    public function isFighting(): bool
    {
        return $this->target !== null && $this->isAlive() && !$this->isWalking()
            && hypot($this->target->x - $this->x, $this->target->y - $this->y) <= 10;
    }

    public function skillReady(): bool
    {
        return $this->isAlive() && $this->skillTimer <= 0;
    }

    /** Walks towards ($x, $y) at its speed. */
    public function walk(float $x, float $y, float $h): void
    {
        $dx = $x - $this->x;
        $dy = $y - $this->y;
        $d = hypot($dx, $dy);
        if ($d < 0.5) {
            return;
        }
        $move = min($d, (float)$this->def['speed'] * $h);
        $this->x += $dx / $d * $move;
        $this->y += $dy / $d * $move;
        if (abs($dx) > 0.5) {
            $this->facingLeft = $dx < 0;
        }
        $this->age += $h;
    }
}
