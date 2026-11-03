<?php

namespace App\Td;

/**
 * A tower on a building slot: its level (1..3), its specialization (level 4), what it aims at.
 */
final class Tower
{
    public const TARGETING = ['first', 'last', 'strongest', 'closest'];
    public const MAX_LEVEL = 3;

    public int $level = 1;
    public ?string $spec = null;
    public string $targeting = 'first';
    public float $cooldown = 0.0;
    /** gold spent on it (selling gives back a part) */
    public int $invested;
    /** @var array<string, mixed> */
    public array $stats;
    /** seconds since the last attack (drawing) */
    public float $sinceShot = 99.0;
    /** where the last attack went (drawing) */
    public float $aimX = 0.0;
    public float $aimY = 0.0;
    /** @var list<Soldier> barracks */
    public array $soldiers = [];
    /**
     * What a barrier / trap / barracks puts on the road: its point (path, distance, x, y), and for a wall its health.
     * @var array{path: int, distance: float, x: float, y: float, hp: float, maxHp: float, timer: float}|null
     */
    public ?array $road = null;
    /** from the auras around it */
    public float $buffDamage = 1.0;
    public float $buffRate = 1.0;
    /** @var array<int, bool> enemies already hit (ambush: the first hit is critical) */
    public array $hitIds = [];
    public float $incomeTimer = 0.0;

    public function __construct(public readonly string $type, public readonly float $x, public readonly float $y,
                                private GameData $data)
    {
        $this->invested = $data->upgradeCost($type, 1);
        $this->stats = $data->towerStats($type, 1);
        $this->aimX = $x;
        $this->aimY = $y + 10;
    }

    /** What the next upgrade costs: null at the top. With a specialization name: its price. */
    public function nextCost(?string $spec = null): ?int
    {
        if ($this->spec !== null) {
            return null;
        }
        if ($this->level < self::MAX_LEVEL) {
            return $this->data->upgradeCost($this->type, $this->level + 1);
        }
        $specs = $this->specNames();
        return $spec !== null || $specs !== [] ? $this->data->upgradeCost($this->type, 4, $spec ?? $specs[0]) : null;
    }

    /** @return list<string> the two specializations (keys of towers.yaml) */
    public function specNames(): array
    {
        return array_keys($this->data->tower($this->type)['specs'] ?? []);
    }

    public function canSpecialize(): bool
    {
        return $this->level === self::MAX_LEVEL && $this->spec === null && $this->specNames() !== [];
    }

    public function upgrade(): void
    {
        $this->invested += (int)$this->nextCost();
        $this->level++;
        $this->stats = $this->data->towerStats($this->type, $this->level);
    }

    public function specialize(string $spec): void
    {
        $this->invested += $this->data->upgradeCost($this->type, 4, $spec);
        $this->spec = $spec;
        $this->level = self::MAX_LEVEL + 1;
        $this->stats = $this->data->towerStats($this->type, self::MAX_LEVEL, $spec);
    }

    public function sellValue(): int
    {
        return (int)floor($this->invested * 0.7);
    }

    public function range(): float
    {
        return (float)$this->stats['range'];
    }

    /** attack | barracks | barrier | trap | aura | income */
    public function kind(): string
    {
        return (string)$this->stats['kind'];
    }

    /** It shoots (an aura or income tower may shoot too). */
    public function attacks(): bool
    {
        return (float)$this->stats['damage'] > 0 && !in_array($this->kind(), ['barracks', 'barrier', 'trap'], true);
    }

    /** It aims at targets (a barracks / wall / trap does not, it has no targeting mode). */
    public function hasTargeting(): bool
    {
        return $this->attacks() && empty($this->stats['pulse']);
    }

    public function canHit(Enemy $enemy): bool
    {
        $targets = (string)($this->stats['targets'] ?? 'both');
        return $targets === 'both' || ($targets === 'air') === $enemy->isFlying();
    }

    public function cycleTargeting(): string
    {
        $i = array_search($this->targeting, self::TARGETING, true);
        return $this->targeting = self::TARGETING[((int)$i + 1) % count(self::TARGETING)];
    }

    /**
     * The enemies it attacks now, best first (its targeting mode).
     * @param list<Enemy> $enemies
     * @return list<Enemy>
     */
    public function pickTargets(array $enemies): array
    {
        $range2 = $this->range() ** 2;
        $inRange = [];
        foreach ($enemies as $enemy) {
            if ($enemy->isTargetable() && $this->canHit($enemy)
                && ($enemy->x - $this->x) ** 2 + ($enemy->y - $this->y) ** 2 <= $range2) {
                $inRange[] = $enemy;
            }
        }
        $key = match ($this->targeting) {
            'last' => static fn (Enemy $e) => -$e->remaining(),
            'strongest' => static fn (Enemy $e) => -$e->hp,
            'closest' => fn (Enemy $e) => ($e->x - $this->x) ** 2 + ($e->y - $this->y) ** 2,
            default => static fn (Enemy $e) => $e->remaining(),
        };
        usort($inRange, static fn (Enemy $a, Enemy $b) => [$key($a), $a->id] <=> [$key($b), $b->id]);
        return array_slice($inRange, 0, max(1, (int)$this->stats['multishot']));
    }
}
