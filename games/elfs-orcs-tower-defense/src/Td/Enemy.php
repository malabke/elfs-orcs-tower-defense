<?php

namespace App\Td;

/**
 * An attacker walking its path (or flying straight to the bastion), with the effects on it.
 */
final class Enemy
{
    private static int $nextId = 1;

    public readonly int $id;
    public float $hp;
    public readonly float $maxHp;
    public float $distance = 0.0;
    public float $x = 0.0;
    public float $y = 0.0;
    public float $dirX = 1.0;
    public float $dirY = 0.0;
    public bool $dead = false;
    /** reached the bastion */
    public bool $escaped = false;
    public float $age = 0.0;

    public float $slowFactor = 0.0;
    public float $slowTime = 0.0;
    public float $stunTime = 0.0;
    public float $burnDps = 0.0;
    public float $burnTime = 0.0;
    public float $shredAmount = 0.0;
    public float $shredTime = 0.0;
    /** the soldier (or the hero) that stops it */
    public Soldier|Hero|null $blocker = null;
    /** stopped by a wall this step */
    public bool $atBarrier = false;
    /** a hidden enemy seen by a detecting tower this step */
    public bool $revealed = false;
    /** where it was before this step (traps) */
    public float $lastDistance = 0.0;
    public float $healTimer = 0.0;

    /** @param float $hpScale its health multiplied (later maps, hard mode) */
    public function __construct(public readonly string $type, public readonly array $def, public readonly Path $path,
                                public readonly int $wave, float $hpScale = 1.0)
    {
        $this->id = self::$nextId++;
        $this->hp = $this->maxHp = (float)$def['hp'] * $hpScale;
        $this->place();
    }

    public function isFlying(): bool
    {
        return !empty($this->def['flying']);
    }

    public function armor(): string
    {
        return (string)($this->def['armor'] ?? 'light');
    }

    public function currentSpeed(): float
    {
        if ($this->stunTime > 0) {
            return 0.0;
        }
        return (float)$this->def['speed'] * (1.0 - $this->slowFactor);
    }

    /** Pixels left to the bastion. */
    public function remaining(): float
    {
        return $this->path->length - $this->distance;
    }

    public function isStealthy(): bool
    {
        return !empty($this->def['stealth']);
    }

    /** Between the two points of its tunnel (tunnel miners): out of reach, not drawn. */
    public function isUnderground(): bool
    {
        if (empty($this->def['tunnel'])) {
            return false;
        }
        [$from, $to] = $this->def['tunnel'];
        $part = $this->distance / max(1.0, $this->path->length);
        return $part > (float)$from && $part < (float)$to;
    }

    /** Towers can aim at it. */
    public function isTargetable(): bool
    {
        return !$this->dead && !$this->escaped && !$this->isUnderground() && (!$this->isStealthy() || $this->revealed);
    }

    public function dodge(): float
    {
        return (float)($this->def['dodge'] ?? 0.0);
    }

    /** Damage per second to the soldiers / walls that stop it. */
    public function attack(): float
    {
        return (float)($this->def['attack'] ?? 0.0);
    }

    /** A soldier holds it, next to it. */
    public function isHeld(): bool
    {
        return $this->blocker !== null && $this->blocker->isFighting() && $this->blocker->target === $this;
    }

    /**
     * Moves along the path (not past $stopAt: a wall) and ticks the effects. Returns the burn damage to apply this step.
     */
    public function step(float $h, float $stopAt = INF): float
    {
        $this->age += $h;
        $this->lastDistance = $this->distance;
        $burn = 0.0;
        if ($this->burnTime > 0) {
            $burn = $this->burnDps * min($h, $this->burnTime);
            $this->burnTime -= $h;
            if ($this->burnTime <= 0) {
                $this->burnDps = 0.0;
            }
        }
        if (!$this->isHeld()) {
            $next = $this->distance + $this->currentSpeed() * $h;
            // a wall ahead stops it (one already past it walks on)
            $this->atBarrier = $this->distance <= $stopAt && $next >= $stopAt;
            $this->distance = $this->atBarrier ? max($this->distance, $stopAt) : $next;
        } else {
            $this->atBarrier = false;
        }
        $this->stunTime = max(0.0, $this->stunTime - $h);
        if ($this->slowTime > 0) {
            $this->slowTime -= $h;
            if ($this->slowTime <= 0) {
                $this->slowFactor = 0.0;
            }
        }
        if ($this->shredTime > 0) {
            $this->shredTime -= $h;
            if ($this->shredTime <= 0) {
                $this->shredAmount = 0.0;
            }
        }
        if ($this->distance >= $this->path->length) {
            $this->distance = $this->path->length;
            $this->escaped = true;
        }
        $this->place();
        return $burn;
    }

    /** @param array<string, array> $effects slow / stun / burn / fear / shred (towers.yaml) */
    public function applyEffects(array $effects, callable $roll): void
    {
        if (isset($effects['slow']) && (float)$effects['slow']['factor'] >= $this->slowFactor) {
            $this->slowFactor = (float)$effects['slow']['factor'];
            $this->slowTime = max($this->slowTime, (float)$effects['slow']['time']);
        }
        // bosses shrug off half of the crowd control
        $hold = !empty($this->def['boss']) ? 0.5 : 1.0;
        if (isset($effects['stun']) && $roll() < (float)($effects['stun']['chance'] ?? 1.0)) {
            $this->stunTime = max($this->stunTime, (float)$effects['stun']['time'] * $hold);
        }
        if (isset($effects['burn'])) {
            $this->burnDps = max($this->burnDps, (float)$effects['burn']['dps']);
            $this->burnTime = max($this->burnTime, (float)$effects['burn']['time']);
        }
        if (isset($effects['fear']) && $roll() < (float)($effects['fear']['chance'] ?? 1.0)) {
            $this->distance = max(0.0, $this->distance - (float)$effects['fear']['distance'] * $hold);
            $this->place();
        }
        if (isset($effects['shred'])) {
            $this->shredAmount = max($this->shredAmount, (float)$effects['shred']['amount']);
            $this->shredTime = max($this->shredTime, (float)$effects['shred']['time']);
        }
    }

    public function isSlowed(): bool
    {
        return $this->slowTime > 0;
    }

    public function isStunned(): bool
    {
        return $this->stunTime > 0;
    }

    public function isBurning(): bool
    {
        return $this->burnTime > 0;
    }

    private function place(): void
    {
        [$this->x, $this->y, $this->dirX, $this->dirY] = $this->path->at($this->distance);
    }
}
