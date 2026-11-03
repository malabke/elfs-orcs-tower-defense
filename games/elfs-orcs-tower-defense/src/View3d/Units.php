<?php

namespace App\View3d;

/**
 * The creatures of the game as 3D models (enemies, soldiers, heroes), built in code from boxes, cylinders and
 * balls. One model per kind and walking frame (0 / 1: legs and arms swap), facing +x, feet on y = 0, about 14 units
 * tall for a man. The scene turns them towards where they walk.
 */
final class Units
{
    /**
     * k: human | beast (a mount, with or without a rider) | wings | ram.
     * Human: skin, cloth, legs, hair, helm [type, colour] (cap helm horns hood hat crown), weapon (club axe sword twin
     * spear bow dagger pick staff stick), staff glow, shield colour, eye, beard, cape, ears, bulk (width), size.
     */
    public const KINDS = [
        'grunt' => ['k' => 'human', 'skin' => '#5e9e3a', 'cloth' => '#7a4a2a', 'legs' => '#4e2e18', 'weapon' => ['club', '#6a4a2a'], 'eye' => '#ff3030', 'bulk' => 1.15, 'size' => 1.0],
        'iron_orc' => ['k' => 'human', 'skin' => '#4f8a32', 'cloth' => '#7d828c', 'legs' => '#4c5058', 'helm' => ['helm', '#8a8f99'], 'weapon' => ['axe', '#b8b8c0'], 'shield' => '#5a3a20', 'eye' => '#ff5020', 'bulk' => 1.35, 'size' => 1.1],
        'goblin' => ['k' => 'human', 'skin' => '#a8b840', 'cloth' => '#6a4a8a', 'legs' => '#4a3a60', 'weapon' => ['dagger', '#c8c8d0'], 'eye' => '#ffe020', 'ears' => true, 'bulk' => 0.85, 'size' => 0.8],
        'warg' => ['k' => 'beast', 'body' => '#6b5a4a', 'mane' => '#4a3c30', 'snout' => 'wolf', 'rider' => ['skin' => '#5e9e3a', 'cloth' => '#7a4a2a', 'legs' => '#4e2e18', 'weapon' => ['club', '#6a4a2a'], 'eye' => '#ff3030', 'size' => 0.85], 'size' => 1.0],
        'bat' => ['k' => 'wings', 'body' => '#3a2a40', 'wing' => '#5a3a6a', 'eye' => '#ff4040', 'size' => 1.0],
        'ram' => ['k' => 'ram', 'size' => 1.0],
        'troll' => ['k' => 'human', 'skin' => '#7a8aa0', 'cloth' => '#6a5030', 'legs' => '#4a3420', 'weapon' => ['club', '#5a4028'], 'eye' => '#ffd030', 'bulk' => 1.7, 'size' => 1.9],
        'warlord' => ['k' => 'human', 'skin' => '#4a7a2a', 'cloth' => '#7a1a14', 'legs' => '#3a0c08', 'helm' => ['horns', '#3a3a40'], 'weapon' => ['axe', '#c8c8c8'], 'eye' => '#ff3010', 'cape' => '#3a0c08', 'bulk' => 1.5, 'size' => 1.35],
        'elf' => ['k' => 'human', 'skin' => '#f0d0b0', 'cloth' => '#3f8a50', 'legs' => '#1d5434', 'hair' => '#f0d870', 'weapon' => ['bow', '#8a6a3a'], 'eye' => '#2a5a2a', 'bulk' => 0.85, 'size' => 1.0],
        'blade' => ['k' => 'human', 'skin' => '#f0d0b0', 'cloth' => '#7a5ab0', 'legs' => '#3a2a6a', 'hair' => '#e8e8f0', 'weapon' => ['twin', '#e0e8f0'], 'eye' => '#3a2a6a', 'bulk' => 0.9, 'size' => 1.0],
        'warden' => ['k' => 'human', 'skin' => '#f8e0c8', 'cloth' => '#f0f0e0', 'legs' => '#c8a040', 'hair' => '#fff0a0', 'weapon' => ['staff', '#a0e0ff'], 'cape' => '#c8a040', 'eye' => '#2a6aa0', 'bulk' => 1.0, 'size' => 1.1],
        'eagle' => ['k' => 'wings', 'body' => '#5a3a1a', 'wing' => '#8a5a2a', 'eye' => '#ffd020', 'beak' => true, 'size' => 1.3],
        'dwarf' => ['k' => 'human', 'skin' => '#e8b890', 'cloth' => '#6a7280', 'legs' => '#3a4048', 'helm' => ['helm', '#9aa0aa'], 'beard' => '#c86a2a', 'shield' => '#8a6a3a', 'weapon' => ['sword', '#c8c8d0'], 'eye' => '#2a2a2a', 'bulk' => 1.5, 'size' => 0.85],
        'miner' => ['k' => 'human', 'skin' => '#e8b890', 'cloth' => '#8a5a2a', 'legs' => '#3a2a20', 'helm' => ['cap', '#e8c040'], 'beard' => '#8a5a2a', 'weapon' => ['pick', '#a0a0a8'], 'eye' => '#2a2a2a', 'bulk' => 1.4, 'size' => 0.85],
        'runebearer' => ['k' => 'human', 'skin' => '#e8b890', 'cloth' => '#3a5a9a', 'legs' => '#1e3050', 'helm' => ['hood', '#3a5a9a'], 'beard' => '#e8e8e8', 'weapon' => ['staff', '#80e0ff'], 'eye' => '#2a2a2a', 'bulk' => 1.3, 'size' => 0.9],
        'thane' => ['k' => 'human', 'skin' => '#e8b890', 'cloth' => '#a02a20', 'legs' => '#5a1810', 'helm' => ['helm', '#e8c040'], 'beard' => '#c86a2a', 'shield' => '#e8c040', 'weapon' => ['axe', '#e8e8f0'], 'eye' => '#2a2a2a', 'bulk' => 1.7, 'size' => 1.05],
        'rider' => ['k' => 'beast', 'body' => '#a0703a', 'mane' => '#6a4a26', 'snout' => 'horse', 'rider' => ['skin' => '#f0c8a0', 'cloth' => '#c08a30', 'legs' => '#5a3a10', 'weapon' => ['spear', '#8a6a3a'], 'helm' => ['cap', '#c08a30'], 'eye' => '#3a2a1a', 'size' => 0.85], 'size' => 1.0],
        'horsearcher' => ['k' => 'beast', 'body' => '#d8d0c0', 'mane' => '#9a9080', 'snout' => 'horse', 'rider' => ['skin' => '#f0c8a0', 'cloth' => '#3f7a40', 'legs' => '#245024', 'weapon' => ['bow', '#8a6a3a'], 'helm' => ['hood', '#3f7a40'], 'eye' => '#3a2a1a', 'size' => 0.85], 'size' => 1.0],
        'horselord' => ['k' => 'beast', 'body' => '#f0f0f0', 'mane' => '#b0b0b0', 'snout' => 'horse', 'rider' => ['skin' => '#f0c8a0', 'cloth' => '#e8c040', 'legs' => '#8a6a20', 'weapon' => ['spear', '#e8c040'], 'helm' => ['crown', '#e8c040'], 'cape' => '#a02a20', 'eye' => '#3a2a1a', 'size' => 0.9], 'size' => 1.15],
        'scout' => ['k' => 'human', 'skin' => '#e0b890', 'cloth' => '#3a3a44', 'legs' => '#2a2a34', 'helm' => ['hood', '#3a3a44'], 'weapon' => ['dagger', '#c8c8d0'], 'eye' => '#2a2a2a', 'bulk' => 0.85, 'size' => 0.9],
        'littleman' => ['k' => 'human', 'skin' => '#e8c098', 'cloth' => '#5a8a3a', 'legs' => '#6a4a2a', 'weapon' => ['stick', '#8a6a3a'], 'eye' => '#2a2a2a', 'bulk' => 0.9, 'size' => 0.66],
        'captain' => ['k' => 'human', 'skin' => '#e8c098', 'cloth' => '#c84a3a', 'legs' => '#6a4a2a', 'helm' => ['hat', '#e8c040'], 'weapon' => ['sword', '#e8c040'], 'eye' => '#2a2a2a', 'bulk' => 1.0, 'size' => 0.85],
    ];

    /** A model of a creature (frame 0 / 1). */
    public static function build(string $sprite, int $frame): Mesh
    {
        $d = self::KINDS[$sprite] ?? self::KINDS['grunt'];
        $m = match ($d['k']) {
            'beast' => self::beast($d, $frame),
            'wings' => self::wings($d, $frame),
            'ram' => self::ram($frame),
            default => self::human($d, $frame),
        };
        return $m->scaleAll((float)$d['size']);
    }

    /** About how tall (units) a creature stands, to place its bar and its marks. */
    public static function height(string $sprite): float
    {
        $d = self::KINDS[$sprite] ?? self::KINDS['grunt'];
        $base = match ($d['k']) {
            'beast' => 17.0,
            'wings' => 7.0,
            'ram' => 12.0,
            default => 15.5,
        };
        return $base * (float)$d['size'];
    }

    private static function human(array $d, int $frame): Mesh
    {
        $m = new Mesh();
        $c = static fn (string $h, float $k = 1.0): array => Mesh::rgb($h, $k);
        $skin = $c($d['skin']);
        $cloth = $c($d['cloth']);
        $legs = $c($d['legs']);
        $b = (float)($d['bulk'] ?? 1.0);
        $s = $frame === 0 ? 1.7 : -1.7;
        // legs and boots
        foreach ([-1, 1] as $side) {
            $off = $side * $s;
            $m->slab([$off, 1.0, $side * 1.5 * $b], [2.4 * $b, 4.2, 2.3 * $b], $legs);
            $m->slab([$off + 0.5, 0.0, $side * 1.5 * $b], [3.4 * $b, 1.2, 2.6 * $b], $c($d['legs'], 0.65));
        }
        // body, belt, arms
        $m->slab([0, 5.0, 0], [3.8 * $b, 5.4, 5.4 * $b], $cloth);
        $m->slab([0, 5.0, 0], [4.0 * $b, 0.9, 5.6 * $b], $c($d['legs'], 0.7));
        foreach ([-1, 1] as $side) {
            $m->slab([-$side * $s * 0.7, 5.6, $side * (3.4 * $b + 0.3)], [1.8, 4.6, 1.8], $skin);
            $m->slab([-$side * $s * 0.7, 8.2, $side * (3.4 * $b + 0.3)], [2.0, 2.2, 2.0], isset($d['shield']) || isset($d['helm']) ? $cloth : $skin);
        }
        if (isset($d['cape'])) {
            $m->box([-2.4, 7.0, 0], [0.8, 7.5, 5.6 * $b], $c($d['cape']), [0, 0, 0.08]);
        }
        // head
        $m->ball([0.2, 12.4, 0], [2.7, 2.9, 2.7], $skin, 7, 4);
        $eye = $c($d['eye'] ?? '#202020');
        foreach ([-1, 1] as $side) {
            $m->box([2.5, 12.8, $side * 1.1], [0.7, 0.9, 0.9], $eye);
        }
        if (!empty($d['ears'])) {
            foreach ([-1, 1] as $side) {
                $m->cyl([0, 12.8, $side * 2.4], 1.2, 0.0, 3.4, $skin, 4, [$side * 1.2, 0, 0]);
            }
        }
        if (isset($d['hair'])) {
            $m->ball([-0.7, 13.2, 0], [2.9, 2.3, 2.9], $c($d['hair']), 7, 3);
            $m->box([-2.3, 11.0, 0], [1.2, 3.4, 3.4], $c($d['hair']));
        }
        if (isset($d['beard'])) {
            $m->box([2.0, 10.0, 0], [1.6, 3.2, 3.4], $c($d['beard']));
        }
        if (isset($d['helm'])) {
            self::helm($m, $d['helm'][0], $c($d['helm'][1]));
        }
        if (isset($d['shield'])) {
            $m->box([2.4, 7.0, -3.9 * $b - 0.6], [0.8, 5.6, 4.4], $c($d['shield']));
            $m->box([2.9, 7.0, -3.9 * $b - 0.6], [0.5, 1.6, 1.6], $c('#e8c040'));
        }
        if (isset($d['weapon'])) {
            self::weapon($m, $d['weapon'][0], $c($d['weapon'][1]), $b, -$s * 0.7);
        }
        return $m;
    }

    private static function helm(Mesh $m, string $type, array $col): void
    {
        switch ($type) {
            case 'cap':
                $m->ball([0, 13.6, 0], [3.0, 1.9, 3.0], $col, 7, 3);
                break;
            case 'helm':
                $m->cyl([0, 12.4, 0], 3.1, 2.8, 2.8, $col, 8);
                $m->ball([0, 15.0, 0], [2.8, 1.6, 2.8], $col, 7, 3);
                $m->box([2.9, 12.4, 0], [0.6, 2.6, 0.9], $col);
                break;
            case 'horns':
                $m->cyl([0, 12.4, 0], 3.1, 2.8, 2.8, $col, 8);
                foreach ([-1, 1] as $side) {
                    $m->cyl([0, 14.4, $side * 2.6], 1.0, 0.0, 4.6, Mesh::rgb('#e8e4d0'), 5, [$side * -0.7, 0, 0]);
                }
                break;
            case 'hood':
                $m->cyl([-0.2, 12.2, 0], 3.3, 0.4, 5.6, $col, 7);
                break;
            case 'hat':
                $m->cyl([0, 14.0, 0], 4.2, 4.2, 0.5, $col, 8);
                $m->cyl([0, 14.4, 0], 2.3, 1.8, 2.8, $col, 8);
                break;
            case 'crown':
                $m->cyl([0, 14.4, 0], 2.9, 3.2, 1.8, $col, 8);
                foreach ([0, 1, 2, 3, 4] as $k) {
                    $m->cyl([cos($k * 1.2566) * 2.8, 16.0, sin($k * 1.2566) * 2.8], 0.7, 0.0, 1.8, $col, 4);
                }
                break;
        }
    }

    /** The weapon in the right hand (z > 0), $swing: how far that arm is forward. */
    private static function weapon(Mesh $m, string $type, array $col, float $b, float $swing): void
    {
        $z = 3.4 * $b + 0.3;
        $wood = Mesh::rgb('#6a4a2a');
        switch ($type) {
            case 'club':
                $m->cyl([$swing + 0.5, 7.2, $z], 0.9, 1.9, 8.0, $col, 6, [0, 0, -0.4]);
                break;
            case 'axe':
                $m->cyl([$swing + 0.5, 6.0, $z], 0.5, 0.5, 9.0, $wood, 5, [0, 0, -0.25]);
                $m->box([$swing + 2.6, 14.0, $z], [3.2, 3.4, 0.8], $col, [0, 0, -0.25]);
                break;
            case 'sword':
                $m->box([$swing + 1.6, 12.0, $z], [0.7, 8.0, 1.2], $col, [0, 0, -0.35]);
                $m->box([$swing + 0.8, 8.4, $z], [0.8, 0.8, 3.2], Mesh::rgb('#e8c040'));
                break;
            case 'twin':
                foreach ([-1, 1] as $side) {
                    $m->box([1.4 + ($side > 0 ? $swing : -$swing), 11.4, $side * ($z + 0.4)], [0.5, 7.0, 1.0], $col, [0, 0, -0.5]);
                }
                break;
            case 'spear':
                $m->cyl([$swing + 0.6, 2.0, $z + 0.4], 0.45, 0.45, 15.0, $col, 5, [0, 0, -0.18]);
                $m->cyl([$swing + 3.2, 16.4, $z + 0.4], 1.0, 0.0, 3.4, Mesh::rgb('#d0d0d8'), 5, [0, 0, -0.18]);
                break;
            case 'bow':
                $m->box([$swing + 2.0, 8.0, $z + 0.6], [0.6, 9.0, 0.9], $col, [0, 0, 0.0]);
                $m->box([$swing + 1.4, 8.0, $z + 0.6], [0.2, 9.0, 0.2], Mesh::rgb('#e8e0c8'));
                break;
            case 'dagger':
                $m->box([$swing + 1.8, 7.6, $z], [0.5, 3.8, 0.9], $col, [0, 0, -0.6]);
                break;
            case 'pick':
                $m->cyl([$swing + 0.5, 5.6, $z], 0.5, 0.5, 8.0, $wood, 5, [0, 0, -0.3]);
                $m->box([$swing + 2.2, 13.0, $z], [0.7, 0.9, 4.6], $col, [0, 0, -0.3]);
                break;
            case 'staff':
                $m->cyl([$swing + 0.6, 0.0, $z + 0.4], 0.5, 0.5, 17.0, $wood, 5);
                $m->ball([$swing + 0.6, 18.0, $z + 0.4], [1.5, 1.5, 1.5], $col, 6, 4);
                break;
            case 'stick':
                $m->cyl([$swing + 0.6, 4.0, $z], 0.4, 0.4, 7.0, $col, 4, [0, 0, -0.3]);
                break;
        }
    }

    /** A mount (horse, wolf), with or without a rider. */
    private static function beast(array $d, int $frame): Mesh
    {
        $m = new Mesh();
        $body = Mesh::rgb($d['body']);
        $mane = Mesh::rgb($d['mane']);
        $dark = Mesh::rgb($d['body'], 0.7);
        $wolf = ($d['snout'] ?? 'horse') === 'wolf';
        $s = $frame === 0 ? 2.0 : -2.0;
        foreach ([[-3.6, -1], [-3.6, 1], [3.6, -1], [3.6, 1]] as $i => [$x, $side]) {
            $off = (($i % 2 === 0) ? $s : -$s);
            $m->slab([$x + $off, 0.0, $side * 1.7], [1.5, 5.0, 1.5], $dark);
        }
        $m->box([0, 6.6, 0], [11.0, 4.6, 4.6], $body);
        $m->ball([5.4, 7.0, 0], [3.0, 2.8, 2.6], $body, 6, 4);
        $m->box([6.6, 9.2, 0], [2.6, 4.4, 2.4], $body, [0, 0, -0.5]);
        $m->box([8.6, 10.2, 0], [$wolf ? 4.2 : 5.0, 2.4, 2.2], $body);
        $m->box([9.6, 9.4, 0], [2.0, 1.2, 1.8], $dark);
        foreach ([-1, 1] as $side) {
            $m->cyl([7.6, 11.2, $side * 0.9], 0.8, 0.0, 2.2, $mane, 4);
            $m->box([9.0, 10.8, $side * 1.15], [0.5, 0.6, 0.5], Mesh::rgb($wolf ? '#ff3030' : '#202020'));
        }
        $m->box([5.4, 9.8, 0], [1.2, 3.6, 0.9], $mane, [0, 0, -0.5]);
        $m->box([-6.4, 7.4, 0], [3.6, 1.2, 1.4], $mane, [0, 0, 0.6 + ($frame ? 0.1 : -0.1)]);
        if (isset($d['rider'])) {
            $r = $d['rider'];
            $rider = self::human(['k' => 'human', 'bulk' => 1.0, 'size' => 1.0] + $r, 0);
            // seated: the legs of the rider hang along the mount
            $m->add($rider->scaleAll((float)($r['size'] ?? 0.85)), [0.4, 6.6, 0]);
        }
        return $m;
    }

    private static function wings(array $d, int $frame): Mesh
    {
        $m = new Mesh();
        $body = Mesh::rgb($d['body']);
        $wing = Mesh::rgb($d['wing']);
        $flap = $frame === 0 ? 0.55 : -0.45;
        $m->ball([0, 3.0, 0], [4.2, 2.4, 2.4], $body, 6, 4);
        $m->ball([4.0, 3.6, 0], [2.0, 1.8, 1.8], $body, 6, 3);
        foreach ([-1, 1] as $side) {
            $m->box([0.5, 3.6 + $flap * 3.0, $side * 5.6], [4.0, 0.5, 8.4], $wing, [$side * $flap, 0, 0]);
            $m->box([-0.4, 3.4 + $flap * 5.0, $side * 9.6], [2.6, 0.4, 4.0], $wing, [$side * $flap * 1.4, 0, 0]);
            $m->box([5.2, 4.1, $side * 0.8], [0.5, 0.6, 0.5], Mesh::rgb($d['eye']));
        }
        $m->box([-4.6, 3.0, 0], [3.4, 0.5, 2.6], $wing, [0, 0, 0.1]);
        if (!empty($d['beak'])) {
            $m->cyl([5.8, 3.4, 0], 0.9, 0.0, 2.6, Mesh::rgb('#e8c040'), 4, [0, 0, -M_PI / 2]);
        }
        return $m;
    }

    private static function ram(int $frame): Mesh
    {
        $m = new Mesh();
        $wood = Mesh::rgb('#8a6a3a');
        $dark = Mesh::rgb('#4a3018');
        $red = Mesh::rgb('#8a3a2a');
        $iron = Mesh::rgb('#a0a0aa');
        $m->slab([0, 2.2, 0], [14.0, 2.0, 8.0], $dark);
        foreach ([[-4.6, -1], [-4.6, 1], [4.6, -1], [4.6, 1]] as [$x, $side]) {
            $m->cyl([$x, 2.8, $side * 4.6], 2.8, 2.8, 1.2, $dark, 8, [M_PI / 2, 0, 0.0 + ($frame ? 0.4 : 0.0)]);
        }
        $m->box([0, 7.0, -3.8], [12.0, 5.6, 0.8], $red);
        $m->box([0, 7.0, 3.8], [12.0, 5.6, 0.8], $red);
        $m->box([-2.0, 11.2, 0], [13.0, 1.0, 9.4], $red, [0, 0, 0.0]);
        $m->box([-2.4, 12.2, -2.4], [12.0, 1.0, 5.0], $wood, [0.6, 0, 0.0]);
        $m->box([-2.4, 12.2, 2.4], [12.0, 1.0, 5.0], $wood, [-0.6, 0, 0.0]);
        $m->cyl([-9.0, 5.4, 0], 1.7, 1.7, 20.0, $wood, 7, [0, 0, -M_PI / 2]);
        $m->cyl([11.0, 5.4, 0], 2.2, 1.8, 3.0, $iron, 7, [0, 0, -M_PI / 2]);
        return $m;
    }
}
