<?php

namespace App\Td;

use Symfony\Component\Yaml\Yaml;

/**
 * The game's data (assets/data/*.yaml): towers, enemies, waves, maps, sides, damage table.
 * Balancing the game = changing these files, not the code.
 */
final class GameData
{
    public const TILE = 16;
    public const COLS = 30;
    public const ROWS = 15;

    /** @var array<string, array> */
    public array $towers;
    /** @var array<string, array> */
    public array $enemies;
    /** @var array<string, array> */
    public array $maps;
    /** @var array<string, array> */
    public array $factions;
    /** @var array<string, array<string, array>> map => side => its waves, or how to make them from another map's */
    public array $waves;
    public float $earlyBonus;
    /** @var array{hp: float, start_gold: float} */
    public array $hard;
    /** @var array<string, array<string, float>> */
    public array $armor;
    /** @var array<string, array> side => its hero */
    public array $heroes;

    public function __construct(string $dir)
    {
        $read = static fn (string $name): array => Yaml::parseFile(rtrim($dir, '/') . '/' . $name) ?? [];
        $this->towers = $read('towers.yaml')['towers'] ?? [];
        $this->enemies = $read('enemies.yaml')['enemies'] ?? [];
        $this->maps = $read('maps.yaml')['maps'] ?? [];
        $this->factions = $read('factions.yaml')['factions'] ?? [];
        $this->heroes = $read('heroes.yaml')['heroes'] ?? [];
        $waves = $read('waves.yaml');
        $this->waves = $waves['waves'] ?? [];
        $this->earlyBonus = (float)($waves['early_bonus'] ?? 1.0);
        $this->hard = ['hp' => (float)($waves['hard']['hp'] ?? 1.5), 'start_gold' => (float)($waves['hard']['start_gold'] ?? 1.0)];
        $this->armor = $read('damage.yaml')['armor'] ?? [];
    }

    /** @return list<string> the maps, in the order of the campaign */
    public function mapIds(): array
    {
        return array_keys($this->maps);
    }

    public function hero(string $faction): ?array
    {
        return $this->heroes[$faction] ?? null;
    }

    private static ?self $default = null;

    /** The data of the game in $root (cached: read once). */
    public static function load(string $root): self
    {
        return self::$default ??= new self(rtrim($root, '/') . '/assets/data');
    }

    public function tower(string $id): array
    {
        return $this->towers[$id] ?? throw new \InvalidArgumentException("Unknown tower $id");
    }

    public function enemy(string $id): array
    {
        return $this->enemies[$id] ?? throw new \InvalidArgumentException("Unknown enemy $id");
    }

    public function map(string $id): array
    {
        return $this->maps[$id] ?? throw new \InvalidArgumentException("Unknown map $id");
    }

    /** @return list<string> the sides, in the order of the side screen */
    public function factionIds(): array
    {
        return array_keys($this->factions);
    }

    public function faction(string $id): array
    {
        return $this->factions[$id] ?? throw new \InvalidArgumentException("Unknown side $id");
    }

    /**
     * The waves of a side on a map: its own list, or another map's with counts and health scaled and groups added
     * (`like`). A group's `hp` multiplies its enemies' health.
     * @return list<array>
     */
    public function wavesFor(string $map, string $side): array
    {
        $spec = $this->waves[$map][$side] ?? throw new \InvalidArgumentException("No waves of $side on $map");
        if (!isset($spec['like'])) {
            return $spec;
        }
        $waves = $this->wavesFor((string)$spec['like'], $side);
        $count = (float)($spec['count'] ?? 1.0);
        $hp = (float)($spec['hp'] ?? 1.0);
        foreach ($waves as &$wave) {
            foreach ($wave['groups'] as &$group) {
                $group['count'] = max(1, (int)round((int)$group['count'] * $count));
                $group['hp'] = (float)($group['hp'] ?? 1.0) * $hp;
            }
            unset($group);
        }
        unset($wave);
        foreach ($spec['add'] ?? [] as $group) {
            $i = (int)$group['wave'] - 1;
            if (isset($waves[$i])) {
                $waves[$i]['groups'][] = array_diff_key($group, ['wave' => 1]) + ['hp' => $hp];
            }
        }
        return $waves;
    }

    /**
     * The stats of a tower at a level (1..3), or with a specialization: each level changes the one before.
     * @return array<string, mixed>
     */
    public function towerStats(string $id, int $level, ?string $spec = null): array
    {
        $def = $this->tower($id);
        $stats = ['kind' => 'attack', 'damage' => 0.0, 'rate' => 1.0, 'range' => 60.0, 'damage_type' => 'physical', 'targets' => 'both',
            'crit' => 0.0, 'crit_mult' => 2.0, 'splash' => 0.0, 'multishot' => 1, 'spread' => 0.0, 'pulse' => false,
            'ambush' => false, 'detect' => false, 'effects' => [], 'speed' => 0.0];
        foreach (array_slice($def['levels'], 0, $level) as $changes) {
            $stats = self::merge($stats, $changes);
        }
        if ($spec !== null) {
            $changes = $def['specs'][$spec] ?? throw new \InvalidArgumentException("Unknown specialization $spec of $id");
            $stats = self::merge($stats, array_diff_key($changes, ['name' => 1, 'desc' => 1]));
        }
        return $stats;
    }

    /** Gold to reach $level (2..3), or the specialization's price. */
    public function upgradeCost(string $id, int $level, ?string $spec = null): int
    {
        $def = $this->tower($id);
        if ($spec !== null) {
            return (int)$def['specs'][$spec]['cost'];
        }
        return (int)($def['levels'][$level - 1]['cost'] ?? 0);
    }

    /** Damage factor of a damage type on an armor class. */
    public function armorFactor(string $armor, string $damageType): float
    {
        return (float)($this->armor[$armor][$damageType] ?? 1.0);
    }

    /** Every text of the data that the game shows (names, descriptions): they are translation keys. @return list<string> */
    public function textKeys(): array
    {
        $keys = [];
        foreach ($this->towers as $tower) {
            $keys[] = $tower['name'];
            $keys[] = $tower['desc'];
            foreach ($tower['specs'] ?? [] as $spec) {
                $keys[] = $spec['name'];
                $keys[] = $spec['desc'];
            }
        }
        foreach ([$this->enemies, $this->maps, $this->factions] as $list) {
            foreach ($list as $item) {
                $keys[] = $item['name'];
            }
        }
        foreach ($this->factions as $faction) {
            $keys[] = $faction['motto'];
            $keys[] = $faction['power']['name'];
            $keys[] = $faction['power']['desc'];
        }
        foreach ($this->heroes as $hero) {
            $keys[] = $hero['name'];
            $keys[] = $hero['skill']['name'];
            $keys[] = $hero['skill']['desc'];
        }
        return array_values(array_unique($keys));
    }

    private static function merge(array $stats, array $changes): array
    {
        foreach ($changes as $key => $value) {
            // effects replace effect by effect, the rest replaces the value
            $stats[$key] = $key === 'effects' ? array_replace($stats['effects'] ?? [], (array)$value) : $value;
        }
        return $stats;
    }
}
