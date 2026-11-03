<?php

namespace App\Td;

/**
 * One game on one map: the economy, the bastion's lives, the waves, the towers, the enemies, the projectiles,
 * the soldiers, walls and traps on the road. Pure PHP (no SDL): the scene steps it at a fixed rate (step(1/60),
 * several times per frame at x2 / x3) and draws what it holds; tests and the bot play it directly.
 *
 * What happened is kept in $events (the scene plays sounds and shows them, then clears them):
 *   ['type' => 'shot'|'hit'|'dodge'|'kill'|'leak'|'wave'|'wave_cleared'|'won'|'lost'|'build'|'upgrade'|'sell'
 *              |'income'|'heal'|'trap'|'wall_down'|'soldier_down', ...]
 */
final class Battle
{
    public const STEP = 1 / 60;
    /** how far from its post a soldier goes to stop an enemy */
    public const SOLDIER_REACH = 40.0;

    /** the side that attacks (waves.yaml): horde, or alliance against the orcs */
    public readonly string $enemySide;
    public int $gold;
    public int $lives;
    public readonly int $maxLives;
    public string $state = 'playing';
    public float $time = 0.0;
    /** waves started */
    public int $waveIndex = 0;
    /** seconds before the next wave comes by itself (null: a wave is arriving, or none is left) */
    public ?float $countdown;
    public int $kills = 0;
    public int $leaked = 0;
    /** @var list<Path> */
    public array $paths = [];
    /** @var list<array{x: float, y: float, tx: int, ty: int, tower: ?Tower}> building slots, x / y = centre */
    public array $slots = [];
    /** @var list<Enemy> */
    public array $enemies = [];
    /** @var list<Projectile> */
    public array $projectiles = [];
    /** @var list<array> */
    public array $events = [];
    /** @var list<array> */
    private array $waves;
    /** @var list<array> enemies still to send */
    private array $spawners = [];
    /** @var array<int, int> wave => enemies alive or still to send */
    private array $pending = [];
    /** @var array<int, bool> wave => everything was sent */
    private array $sent = [];
    private int $seed;
    /** the side's hero (null: a side without one) */
    public ?Hero $hero = null;
    /** seconds before the side's power can be cast again */
    public float $powerTimer = 10.0;

    public function __construct(public readonly GameData $data, public readonly string $mapId,
                                public readonly string $faction, int $seed = 1, public readonly bool $hard = false)
    {
        $map = $data->map($mapId);
        $this->enemySide = (string)$data->faction($faction)['enemy'];
        $this->waves = $data->wavesFor($mapId, $this->enemySide);
        $this->gold = (int)round((int)$map['start_gold'] * ($hard ? $data->hard['start_gold'] : 1.0));
        $this->lives = $this->maxLives = (int)$map['lives'];
        $this->countdown = (float)$map['first_wave'];
        foreach ($map['paths'] as $points) {
            $this->paths[] = Path::fromTiles($points);
        }
        foreach ($map['slots'] as [$tx, $ty]) {
            $this->slots[] = ['x' => ($tx + 1) * GameData::TILE, 'y' => ($ty + 1) * GameData::TILE, 'tx' => $tx, 'ty' => $ty, 'tower' => null];
        }
        $this->seed = $seed & 0x7fffffff ?: 1;
        $hero = $data->hero($faction);
        if ($hero !== null) {
            [$hx, $hy] = $this->paths[0]->at($this->paths[0]->length * (float)($map['hero'] ?? 0.5));
            $this->hero = new Hero($hero, $hx, $hy);
        }
    }

    // ---- the hero and the side's power ------------------------------------------------------------------------

    /** Sends the hero to the point of the road nearest to ($x, $y). */
    public function moveHero(float $x, float $y): bool
    {
        $hero = $this->hero;
        if ($hero === null || !$hero->isAlive() || $this->isOver()) {
            return false;
        }
        $point = $this->nearestRoad($x, $y);
        $this->release($hero);
        $hero->destX = $hero->homeX = $point['x'];
        $hero->destY = $hero->homeY = $point['y'];
        $this->events[] = ['type' => 'hero_move', 'x' => $point['x'], 'y' => $point['y']];
        return true;
    }

    public function power(): array
    {
        return (array)$this->data->faction($this->faction)['power'];
    }

    public function powerReady(): bool
    {
        return $this->powerTimer <= 0 && !$this->isOver();
    }

    /** The side's power, at ($x, $y). */
    public function castPower(float $x, float $y): bool
    {
        if (!$this->powerReady()) {
            return false;
        }
        $power = $this->power();
        $stats = self::attackStats(['damage' => $power['damage'], 'splash' => $power['radius'], 'damage_type' => $power['damage_type'],
            'effects' => $power['effects'] ?? []]);
        $this->hit(new Projectile($x, $y, null, $x, $y, $stats, false, 'power'));
        $this->powerTimer = (float)$power['cooldown'];
        $this->events[] = ['type' => 'power', 'x' => $x, 'y' => $y, 'radius' => (float)$power['radius']];
        return true;
    }

    /** A full set of attack stats from a few (heroes, powers). */
    private static function attackStats(array $stats): array
    {
        return $stats + ['damage' => 0.0, 'rate' => 1.0, 'range' => 0.0, 'damage_type' => 'physical', 'targets' => 'both', 'crit' => 0.0,
            'crit_mult' => 2.0, 'splash' => 0.0, 'multishot' => 1, 'spread' => 0.0, 'pulse' => false, 'effects' => [], 'speed' => 0.0,
            'projectile' => 'arrow'];
    }

    public function waveCount(): int
    {
        return count($this->waves);
    }

    public function isOver(): bool
    {
        return $this->state !== 'playing';
    }

    /** Stars earned: 3 with 18+ lives of 20, 2 with 10+, else 1 (when won). */
    public function stars(): int
    {
        if ($this->state !== 'won') {
            return 0;
        }
        $ratio = $this->lives / max(1, $this->maxLives);
        return $ratio >= 0.9 ? 3 : ($ratio >= 0.5 ? 2 : 1);
    }

    /** @return list<Tower> */
    public function towers(): array
    {
        return array_values(array_filter(array_column($this->slots, 'tower')));
    }

    // ---- the player's actions ---------------------------------------------------------------------------------

    /** The towers the player's side builds. @return list<string> */
    public function towerTypes(): array
    {
        return array_values($this->data->faction($this->faction)['towers']);
    }

    public function build(int $slot, string $type): bool
    {
        $cost = $this->data->upgradeCost($type, 1);
        if ($this->isOver() || !isset($this->slots[$slot]) || $this->slots[$slot]['tower'] !== null || $this->gold < $cost
            || !in_array($type, $this->towerTypes(), true)) {
            return false;
        }
        $this->gold -= $cost;
        $tower = new Tower($type, $this->slots[$slot]['x'], $this->slots[$slot]['y'], $this->data);
        $this->slots[$slot]['tower'] = $tower;
        $this->setUp($tower);
        $this->events[] = ['type' => 'build', 'slot' => $slot, 'tower' => $type];
        return true;
    }

    public function tower(int $slot): ?Tower
    {
        return $this->slots[$slot]['tower'] ?? null;
    }

    /** Levels 2 and 3. */
    public function upgrade(int $slot): bool
    {
        $tower = $this->tower($slot);
        if ($this->isOver() || $tower === null || $tower->level >= Tower::MAX_LEVEL || $tower->spec !== null) {
            return false;
        }
        $cost = (int)$tower->nextCost();
        if ($this->gold < $cost) {
            return false;
        }
        $this->gold -= $cost;
        $tower->upgrade();
        $this->setUp($tower);
        $this->events[] = ['type' => 'upgrade', 'slot' => $slot];
        return true;
    }

    /** Level 4: one of the two branches. */
    public function specialize(int $slot, string $spec): bool
    {
        $tower = $this->tower($slot);
        if ($this->isOver() || $tower === null || !$tower->canSpecialize() || !in_array($spec, $tower->specNames(), true)) {
            return false;
        }
        $cost = (int)$tower->nextCost($spec);
        if ($this->gold < $cost) {
            return false;
        }
        $this->gold -= $cost;
        $tower->specialize($spec);
        $this->setUp($tower);
        $this->events[] = ['type' => 'upgrade', 'slot' => $slot];
        return true;
    }

    /** Sells for 70 % of the gold spent on it. Returns the gold given back. */
    public function sell(int $slot): int
    {
        $tower = $this->tower($slot);
        if ($this->isOver() || $tower === null) {
            return 0;
        }
        foreach ($tower->soldiers as $soldier) {
            $this->release($soldier);
        }
        $value = $tower->sellValue();
        $this->gold += $value;
        $this->slots[$slot]['tower'] = null;
        $this->events[] = ['type' => 'sell', 'slot' => $slot, 'gold' => $value];
        return $value;
    }

    public function canCallWave(): bool
    {
        return !$this->isOver() && $this->countdown !== null && $this->waveIndex < $this->waveCount();
    }

    /** Sends the next wave now: gold for the seconds saved. Returns that bonus. */
    public function callNextWave(): int
    {
        if (!$this->canCallWave()) {
            return 0;
        }
        $bonus = $this->earlyBonus();
        $this->gold += $bonus;
        $this->startWave();
        return $bonus;
    }

    public function earlyBonus(): int
    {
        return $this->countdown === null ? 0 : (int)round($this->countdown * $this->data->earlyBonus);
    }

    /**
     * The next wave's enemies, for the preview: [type => count].
     * @return array<string, int>
     */
    public function nextWavePreview(): array
    {
        $wave = $this->waves[$this->waveIndex] ?? null;
        $preview = [];
        foreach ($wave['groups'] ?? [] as $group) {
            $preview[$group['enemy']] = ($preview[$group['enemy']] ?? 0) + (int)$group['count'];
        }
        return $preview;
    }

    /** Puts an enemy on a path now (tests, and waves). */
    public function spawnEnemy(string $type, int $path = 0, int $wave = -1, float $hpScale = 1.0): Enemy
    {
        $def = $this->data->enemy($type);
        $road = $this->paths[$path] ?? $this->paths[0];
        $enemy = new Enemy($type, $def, !empty($def['flying']) ? $road->direct() : $road, $wave, $hpScale);
        $this->enemies[] = $enemy;
        return $enemy;
    }

    /** Starts later in the game: $wave waves already done (tests, hot reload). */
    public function skipTo(int $wave, float $countdown = 10.0): void
    {
        $this->waveIndex = max(0, min($this->waveCount() - 1, $wave));
        $this->countdown = $countdown;
    }

    /** Plain values of what the player built (hot reload). */
    public function snapshot(): array
    {
        $towers = [];
        foreach ($this->slots as $i => $slot) {
            $t = $slot['tower'];
            if ($t !== null) {
                $towers[] = [$i, $t->type, $t->level, $t->spec, $t->targeting];
            }
        }
        return ['gold' => $this->gold, 'lives' => $this->lives, 'wave' => $this->waveIndex, 'towers' => $towers];
    }

    public function restore(array $state): void
    {
        foreach ($state['towers'] ?? [] as [$i, $type, $level, $spec, $targeting]) {
            if (!isset($this->slots[$i])) {
                continue;
            }
            $tower = new Tower($type, $this->slots[$i]['x'], $this->slots[$i]['y'], $this->data);
            for ($l = 1; $l < min(Tower::MAX_LEVEL, (int)$level); $l++) {
                $tower->upgrade();
            }
            if ($spec !== null) {
                $tower->specialize($spec);
            }
            $tower->targeting = $targeting;
            $this->slots[$i]['tower'] = $tower;
            $this->setUp($tower);
        }
        $this->gold = (int)($state['gold'] ?? $this->gold);
        $this->lives = (int)($state['lives'] ?? $this->lives);
        $this->skipTo((int)($state['wave'] ?? 0));
    }

    // ---- towers on the road -----------------------------------------------------------------------------------

    /** The point of the road nearest to ($x, $y). @return array{path: int, distance: float, x: float, y: float} */
    public function nearestRoad(float $x, float $y): array
    {
        $best = null;
        foreach ($this->paths as $i => $path) {
            $d = $path->project($x, $y);
            [$px, $py] = $path->at($d);
            $gap = hypot($px - $x, $py - $y);
            if ($best === null || $gap < $best[0]) {
                $best = [$gap, ['path' => $i, 'distance' => $d, 'x' => $px, 'y' => $py]];
            }
        }
        return $best[1];
    }

    /** After a build / an upgrade: the soldiers, the wall or the trap of the tower. */
    private function setUp(Tower $tower): void
    {
        $kind = $tower->kind();
        if (!in_array($kind, ['barracks', 'barrier', 'trap'], true)) {
            return;
        }
        if ($tower->road === null) {
            $point = $this->nearestRoad($tower->x, $tower->y);
            $tower->road = $point + ['hp' => 0.0, 'maxHp' => 0.0, 'timer' => 0.0];
        }
        if ($kind === 'barrier') {
            // a new wall is whole; an upgraded one keeps its share of health (a broken one stays broken)
            $old = $tower->road['maxHp'];
            $ratio = $old > 0 ? $tower->road['hp'] / $old : 1.0;
            $tower->road['maxHp'] = (float)$tower->stats['barrier']['hp'];
            $tower->road['hp'] = $tower->road['maxHp'] * $ratio;
        }
        if ($kind === 'barracks') {
            $stats = $tower->stats['soldiers'];
            $path = $this->paths[$tower->road['path']];
            $count = (int)$stats['count'];
            for ($i = 0; $i < $count; $i++) {
                // posts side by side along the road
                [$hx, $hy] = $path->at($tower->road['distance'] + ($i - ($count - 1) / 2) * 12);
                if (isset($tower->soldiers[$i])) {
                    $tower->soldiers[$i]->update($stats);
                } else {
                    $tower->soldiers[$i] = new Soldier($hx, $hy, $stats);
                }
            }
        }
    }

    private function release(Soldier|Hero $soldier): void
    {
        if ($soldier->target !== null && $soldier->target->blocker === $soldier) {
            $soldier->target->blocker = null;
        }
        $soldier->target = null;
    }

    // ---- the simulation ---------------------------------------------------------------------------------------

    public function step(float $h = self::STEP): void
    {
        if ($this->isOver()) {
            return;
        }
        $this->time += $h;
        if ($this->countdown !== null) {
            $this->countdown -= $h;
            if ($this->countdown <= 0) {
                $this->startWave();
            }
        }
        $this->spawn($h);
        $this->prepare();
        $this->moveEnemies($h);
        $this->fightSoldiers($h);
        $this->actHero($h);
        $this->powerTimer = max(0.0, $this->powerTimer - $h);
        $this->roadObjects($h);
        $this->fireTowers($h);
        $this->collectIncome($h);
        $this->moveProjectiles($h);
        $this->enemies = array_values(array_filter($this->enemies, static fn (Enemy $e) => !$e->dead && !$e->escaped));
        $this->checkEnd();
    }

    /** Runs $seconds of the game at the fixed step. */
    public function run(float $seconds): void
    {
        for ($t = 0.0; $t < $seconds && !$this->isOver(); $t += self::STEP) {
            $this->step();
        }
    }

    private function startWave(): void
    {
        $i = $this->waveIndex;
        $wave = $this->waves[$i];
        $this->waveIndex++;
        $this->countdown = null;
        $this->pending[$i] = 0;
        $this->sent[$i] = false;
        foreach ($wave['groups'] as $group) {
            $this->spawners[] = [
                'wave' => $i,
                'enemy' => (string)$group['enemy'],
                'left' => (int)$group['count'],
                'every' => (float)($group['every'] ?? 1.0),
                'timer' => (float)($group['delay'] ?? 0.0),
                'path' => (int)($group['path'] ?? -1),
                'hp' => (float)($group['hp'] ?? 1.0) * ($this->hard ? $this->data->hard['hp'] : 1.0),
                'count' => 0,
            ];
            $this->pending[$i] += (int)$group['count'];
        }
        $this->events[] = ['type' => 'wave', 'wave' => $this->waveIndex, 'boss' => $this->hasBoss($wave)];
    }

    private function hasBoss(array $wave): bool
    {
        foreach ($wave['groups'] as $group) {
            if (!empty($this->data->enemy($group['enemy'])['boss'])) {
                return true;
            }
        }
        return false;
    }

    private function spawn(float $h): void
    {
        foreach ($this->spawners as $k => &$spawner) {
            $spawner['timer'] -= $h;
            while ($spawner['left'] > 0 && $spawner['timer'] <= 0) {
                // without a given path, groups take the paths in turn
                $this->spawnEnemy($spawner['enemy'], $spawner['path'] >= 0 ? $spawner['path'] : $spawner['count'] % count($this->paths),
                    $spawner['wave'], $spawner['hp']);
                $spawner['left']--;
                $spawner['count']++;
                $spawner['timer'] += $spawner['every'];
            }
            if ($spawner['left'] <= 0) {
                unset($this->spawners[$k]);
            }
        }
        unset($spawner);
        // the wave arrived entirely: the next one gets its countdown
        $current = $this->waveIndex - 1;
        if ($current >= 0 && !($this->sent[$current] ?? true) && !$this->hasSpawner($current)) {
            $this->sent[$current] = true;
            if ($this->waveIndex < $this->waveCount()) {
                $this->countdown = (float)($this->waves[$current]['next'] ?? 15);
            }
        }
    }

    private function hasSpawner(int $wave): bool
    {
        foreach ($this->spawners as $spawner) {
            if ($spawner['wave'] === $wave) {
                return true;
            }
        }
        return false;
    }

    /** The auras on each tower, and the hidden enemies that a detecting tower sees. */
    private function prepare(): void
    {
        $towers = $this->towers();
        foreach ($towers as $tower) {
            $tower->buffDamage = 1.0;
            $tower->buffRate = 1.0;
        }
        foreach ($towers as $source) {
            if (!isset($source->stats['aura'])) {
                continue;
            }
            $range2 = $source->range() ** 2;
            foreach ($towers as $tower) {
                if ($tower !== $source && ($tower->x - $source->x) ** 2 + ($tower->y - $source->y) ** 2 <= $range2) {
                    $tower->buffDamage *= (float)($source->stats['aura']['damage'] ?? 1.0);
                    $tower->buffRate *= (float)($source->stats['aura']['rate'] ?? 1.0);
                }
            }
        }
        foreach ($this->enemies as $enemy) {
            if (!$enemy->isStealthy()) {
                continue;
            }
            $enemy->revealed = false;
            foreach ($towers as $tower) {
                if (!empty($tower->stats['detect']) && ($enemy->x - $tower->x) ** 2 + ($enemy->y - $tower->y) ** 2 <= $tower->range() ** 2) {
                    $enemy->revealed = true;
                    break;
                }
            }
        }
    }

    /** Where the first standing wall ahead of $enemy is on its road (INF: none). */
    private function wallAhead(Enemy $enemy): float
    {
        if ($enemy->isFlying() || $enemy->isUnderground()) {
            return INF;
        }
        $stop = INF;
        foreach ($this->towers() as $tower) {
            $road = $tower->road;
            if ($tower->kind() === 'barrier' && $road !== null && $road['hp'] > 0 && $this->paths[$road['path']] === $enemy->path
                && $road['distance'] >= $enemy->distance - 0.01) {
                $stop = min($stop, $road['distance']);
            }
        }
        return $stop;
    }

    private function moveEnemies(float $h): void
    {
        foreach ($this->enemies as $enemy) {
            $this->healAround($enemy, $h);
            $burn = $enemy->step($h, $this->wallAhead($enemy));
            if ($burn > 0) {
                $this->damage($enemy, $burn, 'fire', null);
            }
            if ($enemy->escaped && !$enemy->dead) {
                $lost = (int)($enemy->def['lives'] ?? 1);
                $this->lives = max(0, $this->lives - $lost);
                $this->leaked++;
                $this->events[] = ['type' => 'leak', 'enemy' => $enemy->type, 'lives' => $lost];
                if ($enemy->blocker !== null) {
                    $this->release($enemy->blocker);
                }
                $this->gone($enemy);
            }
        }
    }

    /** Rune bearers and captains heal the enemies around them. */
    private function healAround(Enemy $healer, float $h): void
    {
        $heal = $healer->def['heal'] ?? null;
        if ($heal === null || $healer->isUnderground()) {
            return;
        }
        $healer->healTimer += $h;
        if ($healer->healTimer < (float)$heal['every']) {
            return;
        }
        $healer->healTimer = 0.0;
        $radius2 = (float)$heal['radius'] ** 2;
        foreach ($this->enemies as $enemy) {
            if (!$enemy->dead && $enemy->hp < $enemy->maxHp && ($enemy->x - $healer->x) ** 2 + ($enemy->y - $healer->y) ** 2 <= $radius2) {
                $enemy->hp = min($enemy->maxHp, $enemy->hp + (float)$heal['amount']);
            }
        }
        $this->events[] = ['type' => 'heal', 'x' => $healer->x, 'y' => $healer->y, 'radius' => (float)$heal['radius']];
    }

    /** The soldiers of the barracks: each stops one enemy on the ground and fights it. */
    private function fightSoldiers(float $h): void
    {
        foreach ($this->towers() as $tower) {
            if ($tower->soldiers === []) {
                continue;
            }
            $stats = $tower->stats['soldiers'];
            foreach ($tower->soldiers as $soldier) {
                if (!$soldier->isAlive()) {
                    $soldier->respawn -= $h;
                    if ($soldier->respawn <= 0) {
                        $soldier->hp = $soldier->maxHp;
                        $soldier->x = $soldier->homeX;
                        $soldier->y = $soldier->homeY;
                    }
                    continue;
                }
                $target = $soldier->target;
                if ($target !== null && ($target->dead || $target->escaped || $target->isUnderground()
                        || hypot($target->x - $soldier->homeX, $target->y - $soldier->homeY) > self::SOLDIER_REACH * 1.6)) {
                    $this->release($soldier);
                    $target = null;
                }
                if ($target === null) {
                    $target = $this->enemyFor($soldier);
                    if ($target !== null) {
                        $soldier->target = $target;
                        $target->blocker = $soldier;
                    }
                }
                if ($target === null) {
                    $soldier->walk($soldier->homeX, $soldier->homeY, $h);
                    continue;
                }
                // to the enemy's side, then blows are exchanged
                $soldier->walk($target->x + ($soldier->x < $target->x ? -6 : 6), $target->y, $h);
                if ($soldier->isFighting()) {
                    $soldier->age += $h;
                    $this->damage($target, $soldier->damage * $tower->buffDamage * $h, 'physical', null);
                    $soldier->hp -= $target->attack() * $h;
                    if ($soldier->hp <= 0) {
                        $soldier->hp = 0.0;
                        $soldier->respawn = (float)$stats['respawn'];
                        $this->release($soldier);
                        $this->events[] = ['type' => 'soldier_down', 'x' => $soldier->x, 'y' => $soldier->y];
                    }
                }
            }
        }
    }

    /** The hero: walks where it was sent, fights there, shoots, and fires its skill when enemies crowd around. */
    private function actHero(float $h): void
    {
        $hero = $this->hero;
        if ($hero === null) {
            return;
        }
        $def = $hero->def;
        $hero->skillTimer = max(0.0, $hero->skillTimer - $h);
        $hero->sinceSkill += $h;
        if (!$hero->isAlive()) {
            $hero->respawn -= $h;
            if ($hero->respawn <= 0) {
                $hero->hp = $hero->maxHp;
                $hero->x = $hero->destX = $hero->homeX;
                $hero->y = $hero->destY = $hero->homeY;
            }
            return;
        }
        $target = $hero->target;
        if ($target !== null && ($target->dead || $target->escaped || $target->isUnderground() || $hero->isWalking())) {
            $this->release($hero);
            $target = null;
        }
        if ($hero->isWalking()) {
            $hero->walk($hero->destX, $hero->destY, $h);
        } else {
            if ($target === null) {
                $target = $this->enemyFor($hero);
                if ($target !== null) {
                    $hero->target = $target;
                    $target->blocker = $hero;
                }
            }
            if ($target !== null) {
                $hero->walk($target->x + ($hero->x < $target->x ? -7 : 7), $target->y, $h);
                $hero->destX = $hero->x;
                $hero->destY = $hero->y;
                if ($hero->isFighting()) {
                    $this->damage($target, (float)$def['damage'] * $h, 'physical', null);
                    $hero->hp -= $target->attack() * $h;
                }
            } else {
                // back to the post when the fight is over
                $hero->walk($hero->homeX, $hero->homeY, $h);
                $hero->destX = $hero->x;
                $hero->destY = $hero->y;
            }
        }
        if ($hero->hp <= 0) {
            $hero->hp = 0.0;
            $hero->respawn = (float)$def['respawn'];
            $this->release($hero);
            $this->events[] = ['type' => 'hero_down', 'x' => $hero->x, 'y' => $hero->y];
            return;
        }
        $this->heroShoots($hero, $h);
        $this->heroSkill($hero);
    }

    private function heroShoots(Hero $hero, float $h): void
    {
        $ranged = $hero->def['ranged'] ?? null;
        $hero->cooldown -= $h;
        if ($ranged === null || $hero->cooldown > 0 || $hero->isFighting()) {
            return;
        }
        $best = null;
        $range2 = (float)$ranged['range'] ** 2;
        foreach ($this->enemies as $enemy) {
            if ($enemy->isTargetable() && ($ranged['targets'] === 'both' || $enemy->isFlying() === ($ranged['targets'] === 'air'))
                && ($enemy->x - $hero->x) ** 2 + ($enemy->y - $hero->y) ** 2 <= $range2 && ($best === null || $enemy->remaining() < $best->remaining())) {
                $best = $enemy;
            }
        }
        if ($best === null) {
            $hero->cooldown = 0.0;
            return;
        }
        $hero->cooldown = (float)$ranged['rate'];
        $stats = self::attackStats(['damage' => $ranged['damage'], 'damage_type' => $ranged['damage_type'], 'targets' => $ranged['targets'],
            'projectile' => $ranged['projectile'] ?? 'arrow', 'speed' => 240.0]);
        $this->projectiles[] = new Projectile($hero->x, $hero->y - 8, $best, $best->x, $best->y, $stats, false, 'hero');
        $this->events[] = ['type' => 'shot', 'tower' => 'hero', 'projectile' => $stats['projectile']];
    }

    /** Its skill fires by itself: enough enemies around it, or a boss. */
    private function heroSkill(Hero $hero): void
    {
        $skill = $hero->def['skill'];
        if (!$hero->skillReady()) {
            return;
        }
        $radius2 = (float)$skill['radius'] ** 2;
        $near = 0;
        $boss = false;
        foreach ($this->enemies as $enemy) {
            if (!$enemy->dead && !$enemy->isUnderground() && !$enemy->isFlying() && ($enemy->x - $hero->x) ** 2 + ($enemy->y - $hero->y) ** 2 <= $radius2) {
                $near++;
                $boss = $boss || !empty($enemy->def['boss']);
            }
        }
        if ($near < (int)($skill['crowd'] ?? 3) && !$boss) {
            return;
        }
        $stats = self::attackStats(['damage' => $skill['damage'], 'splash' => $skill['radius'], 'damage_type' => $skill['damage_type'],
            'targets' => 'ground', 'effects' => $skill['effects'] ?? [], 'pulse' => true]);
        $this->hit(new Projectile($hero->x, $hero->y, null, $hero->x, $hero->y, $stats, false, 'hero'));
        $hero->skillTimer = (float)$skill['cooldown'];
        $hero->sinceSkill = 0.0;
        $this->events[] = ['type' => 'hero_skill', 'x' => $hero->x, 'y' => $hero->y, 'radius' => (float)$skill['radius']];
    }

    /** The nearest enemy on the ground, free, near the soldier's post (the hero: near where it stands). */
    private function enemyFor(Soldier|Hero $soldier): ?Enemy
    {
        $best = null;
        $bestDistance = self::SOLDIER_REACH;
        [$px, $py] = $soldier instanceof Hero ? [$soldier->x, $soldier->y] : [$soldier->homeX, $soldier->homeY];
        foreach ($this->enemies as $enemy) {
            if ($enemy->dead || $enemy->escaped || $enemy->isFlying() || $enemy->isUnderground() || $enemy->blocker !== null) {
                continue;
            }
            $d = hypot($enemy->x - $px, $enemy->y - $py);
            if ($d < $bestDistance) {
                $bestDistance = $d;
                $best = $enemy;
            }
        }
        return $best;
    }

    /** Walls are struck by the enemies they stop; traps snap on the enemies that walk on them. */
    private function roadObjects(float $h): void
    {
        foreach ($this->towers() as $tower) {
            $road = &$tower->road;
            if ($road === null) {
                unset($road);
                continue;
            }
            if ($tower->kind() === 'barrier') {
                if ($road['hp'] <= 0) {
                    $road['timer'] -= $h;
                    if ($road['timer'] <= 0) {
                        $road['hp'] = $road['maxHp'];
                    }
                } else {
                    $thorns = (float)($tower->stats['barrier']['thorns'] ?? 0);
                    foreach ($this->enemies as $enemy) {
                        if ($enemy->atBarrier && !$enemy->dead && $this->paths[$road['path']] === $enemy->path
                            && abs($enemy->distance - $road['distance']) < 1) {
                            $road['hp'] -= $enemy->attack() * $h;
                            if ($thorns > 0) {
                                $this->damage($enemy, $thorns * $h, 'physical', null);
                            }
                        }
                    }
                    if ($road['hp'] <= 0) {
                        $road['hp'] = 0.0;
                        $road['timer'] = (float)$tower->stats['barrier']['respawn'];
                        $this->events[] = ['type' => 'wall_down', 'x' => $road['x'], 'y' => $road['y']];
                    }
                }
            } elseif ($tower->kind() === 'trap') {
                $road['timer'] -= $h;
                if ($road['timer'] <= 0 && $this->somethingCrosses($road)) {
                    $stats = $tower->stats;
                    $stats['damage'] = (float)$stats['damage'] * $tower->buffDamage;
                    $this->hit(new Projectile($road['x'], $road['y'], null, $road['x'], $road['y'], $stats, false, $tower->type));
                    $road['timer'] = (float)$stats['rate'] * $tower->buffRate;
                    $this->events[] = ['type' => 'trap', 'x' => $road['x'], 'y' => $road['y']];
                }
            }
            unset($road);
        }
    }

    /** An enemy on the ground walked over this point of the road during the step. */
    private function somethingCrosses(array $road): bool
    {
        foreach ($this->enemies as $enemy) {
            if (!$enemy->dead && !$enemy->isFlying() && !$enemy->isUnderground() && $this->paths[$road['path']] === $enemy->path
                && $enemy->lastDistance <= $road['distance'] + 2 && $enemy->distance >= $road['distance'] - 2) {
                return true;
            }
        }
        return false;
    }

    private function fireTowers(float $h): void
    {
        foreach ($this->towers() as $tower) {
            $tower->sinceShot += $h;
            if (!$tower->attacks()) {
                continue;
            }
            $tower->cooldown -= $h;
            if ($tower->cooldown > 0) {
                continue;
            }
            $targets = $tower->pickTargets($this->enemies);
            if ($targets === []) {
                $tower->cooldown = 0.0;
                continue;
            }
            $stats = $tower->stats;
            $stats['damage'] = (float)$stats['damage'] * $tower->buffDamage;
            $tower->cooldown += (float)$stats['rate'] * $tower->buffRate;
            $tower->sinceShot = 0.0;
            $tower->aimX = $targets[0]->x;
            $tower->aimY = $targets[0]->y;
            $this->events[] = ['type' => 'shot', 'tower' => $tower->type, 'projectile' => $stats['projectile'] ?? 'arrow'];
            if (!empty($stats['pulse'])) {
                // a blast around the tower: every enemy in range
                $stats['splash'] = $tower->range();
                $this->hit(new Projectile($tower->x, $tower->y, null, $tower->x, $tower->y, $stats, false, $tower->type));
                continue;
            }
            foreach ($targets as $target) {
                $first = empty($tower->hitIds[$target->id]);
                $tower->hitIds[$target->id] = true;
                $crit = (!empty($stats['ambush']) && $first) || $this->roll() < (float)$stats['crit'];
                if ((float)$stats['speed'] <= 0) {
                    // instant (roots coming out of the ground, blasts): hits where the target stands
                    $this->hit(new Projectile($target->x, $target->y, $target, $target->x, $target->y, $stats, $crit, $tower->type));
                    continue;
                }
                $spread = (float)$stats['spread'];
                if ($spread > 0) {
                    // imprecise: lands near where the target was, without following it
                    $a = $this->roll() * 2 * M_PI;
                    $r = $this->roll() * $spread;
                    $this->projectiles[] = new Projectile($tower->x, $tower->y - 10, null, $target->x + cos($a) * $r, $target->y + sin($a) * $r,
                        $stats, $crit, $tower->type);
                    continue;
                }
                $this->projectiles[] = new Projectile($tower->x, $tower->y - 10, $target, $target->x, $target->y, $stats, $crit, $tower->type);
            }
        }
    }

    /** Mines and inns: gold every few seconds. */
    private function collectIncome(float $h): void
    {
        foreach ($this->towers() as $tower) {
            $income = $tower->stats['income'] ?? null;
            if ($income === null) {
                continue;
            }
            $tower->incomeTimer += $h;
            if ($tower->incomeTimer >= (float)$income['every']) {
                $tower->incomeTimer -= (float)$income['every'];
                $this->gold += (int)$income['gold'];
                $this->events[] = ['type' => 'income', 'x' => $tower->x, 'y' => $tower->y, 'gold' => (int)$income['gold']];
            }
        }
    }

    private function moveProjectiles(float $h): void
    {
        foreach ($this->projectiles as $projectile) {
            if ($projectile->done) {
                continue;
            }
            if ($projectile->step($h)) {
                $this->hit($projectile);
                $projectile->done = true;
            }
        }
        $this->projectiles = array_values(array_filter($this->projectiles, static fn (Projectile $p) => !$p->done));
    }

    private function hit(Projectile $p): void
    {
        $stats = $p->stats;
        $damage = (float)$stats['damage'] * ($p->crit ? (float)$stats['crit_mult'] : 1.0);
        $type = (string)$stats['damage_type'];
        $splash = (float)$stats['splash'];
        $victims = [];
        if ($splash > 0) {
            foreach ($this->enemies as $enemy) {
                if (!$enemy->dead && !$enemy->escaped && !$enemy->isUnderground()
                    && ($stats['targets'] === 'both' || $enemy->isFlying() === ($stats['targets'] === 'air'))
                    && ($enemy->x - $p->x) ** 2 + ($enemy->y - $p->y) ** 2 <= $splash * $splash) {
                    $victims[] = $enemy;
                }
            }
        } elseif ($p->target !== null && !$p->target->dead && !$p->target->isUnderground()) {
            if ($this->roll() < $p->target->dodge()) {
                $this->events[] = ['type' => 'dodge', 'x' => $p->target->x, 'y' => $p->target->y];
                return;
            }
            $victims[] = $p->target;
        }
        $this->events[] = ['type' => 'hit', 'x' => $p->x, 'y' => $p->y, 'kind' => $p->kind(), 'splash' => $splash, 'crit' => $p->crit,
            'pulse' => !empty($stats['pulse'])];
        foreach ($victims as $enemy) {
            $enemy->applyEffects((array)$stats['effects'], fn () => $this->roll());
            $this->damage($enemy, $damage, $type, $p);
        }
    }

    /** Damage after armor (a shred effect removes part of the resistance). */
    public function damageFactor(Enemy $enemy, string $type): float
    {
        $factor = $this->data->armorFactor($enemy->armor(), $type);
        if ($factor < 1.0 && $enemy->shredAmount > 0) {
            $factor += (1.0 - $factor) * $enemy->shredAmount;
        }
        return $factor;
    }

    private function damage(Enemy $enemy, float $amount, string $type, ?Projectile $p): void
    {
        if ($enemy->dead || $enemy->escaped) {
            return;
        }
        $enemy->hp -= $amount * $this->damageFactor($enemy, $type);
        if ($enemy->hp <= 0) {
            $enemy->dead = true;
            if ($enemy->blocker !== null) {
                $this->release($enemy->blocker);
            }
            $gold = (int)($enemy->def['gold'] ?? 0);
            $this->gold += $gold;
            $this->kills++;
            $this->events[] = ['type' => 'kill', 'enemy' => $enemy->type, 'x' => $enemy->x, 'y' => $enemy->y, 'gold' => $gold,
                'flying' => $enemy->isFlying()];
            $this->gone($enemy);
        }
    }

    /** An enemy is out of the game: its wave may be over (bonus). */
    private function gone(Enemy $enemy): void
    {
        $wave = $enemy->wave;
        if ($wave < 0) {
            return;   // not sent by a wave
        }
        $this->pending[$wave] = ($this->pending[$wave] ?? 1) - 1;
        if ($this->pending[$wave] === 0 && $this->lives > 0) {
            $bonus = (int)($this->waves[$wave]['bonus'] ?? 0);
            $this->gold += $bonus;
            $this->events[] = ['type' => 'wave_cleared', 'wave' => $wave + 1, 'gold' => $bonus];
        }
    }

    private function checkEnd(): void
    {
        if ($this->lives <= 0) {
            $this->state = 'lost';
            $this->events[] = ['type' => 'lost'];
            return;
        }
        if ($this->waveIndex >= $this->waveCount() && $this->spawners === [] && $this->enemies === []) {
            $this->state = 'won';
            $this->events[] = ['type' => 'won', 'stars' => $this->stars()];
        }
    }

    /** A random number in [0, 1), the same for the same seed (tests, replays). 31-bit: also right in the browser. */
    public function roll(): float
    {
        // Park-Miller: seed * 16807 mod (2^31 - 1), split so that no step goes over 2^31 (32-bit PHP)
        $hi = intdiv($this->seed, 127773);
        $lo = $this->seed % 127773;
        $next = 16807 * $lo - 2836 * $hi;
        $this->seed = $next > 0 ? $next : $next + 2147483647;
        return ($this->seed - 1) / 2147483646;
    }
}
