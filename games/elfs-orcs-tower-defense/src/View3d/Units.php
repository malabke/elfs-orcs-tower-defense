<?php

namespace App\View3d;

/**
 * The creatures of the game as 3D models (enemies, soldiers, heroes), round and soft like the characters of a
 * picture book: a big head, a small body, smooth limbs. One model per kind and walking frame (FRAMES: the legs and
 * arms swing, the body bobs), facing +x, feet on y = 0, about 15 units tall for a man. The scene turns them
 * towards where they walk.
 */
final class Units
{
    /** Walking frames of each creature (Units::build($sprite, 0 .. FRAMES - 1)). */
    public const FRAMES = 4;

    /**
     * k: human | beast (a mount, with or without a rider) | wings | ram.
     * Human: skin, cloth, legs, hair, helm [type, colour] (cap helm horns hood hat crown), weapon (club axe sword twin
     * spear bow dagger pick staff stick), shield colour, eye, beard, cape, ears (goblin), elf (pointed ears), tusks,
     * curls (curly hair), barefoot, bulk (width), size.
     */
    public const KINDS = [
        'grunt' => ['k' => 'human', 'skin' => '#6aa044', 'cloth' => '#7a4a2a', 'legs' => '#4e2e18', 'weapon' => ['club', '#6a4a2a'], 'eye' => '#ff4030', 'tusks' => true, 'bulk' => 1.15, 'size' => 1.0],
        'iron_orc' => ['k' => 'human', 'skin' => '#5a9036', 'cloth' => '#7d828c', 'legs' => '#4c5058', 'helm' => ['helm', '#8a8f99'], 'weapon' => ['axe', '#c0c4cc'], 'shield' => '#5a3a20', 'eye' => '#ff6020', 'tusks' => true, 'bulk' => 1.35, 'size' => 1.1],
        'goblin' => ['k' => 'human', 'skin' => '#a8bc44', 'cloth' => '#6a4a8a', 'legs' => '#4a3a60', 'weapon' => ['dagger', '#c8c8d0'], 'eye' => '#ffe030', 'ears' => true, 'bulk' => 0.85, 'size' => 0.8],
        'warg' => ['k' => 'beast', 'body' => '#6e5e4e', 'mane' => '#4a3c30', 'snout' => 'wolf', 'rider' => ['skin' => '#6aa044', 'cloth' => '#7a4a2a', 'legs' => '#4e2e18', 'weapon' => ['club', '#6a4a2a'], 'eye' => '#ff4030', 'tusks' => true, 'size' => 0.8], 'size' => 1.0],
        'bat' => ['k' => 'wings', 'body' => '#4a3650', 'wing' => '#6a4a7a', 'eye' => '#ff5040', 'size' => 1.0],
        'ram' => ['k' => 'ram', 'size' => 1.0],
        'troll' => ['k' => 'human', 'skin' => '#8494a8', 'cloth' => '#6a5030', 'legs' => '#4a3420', 'weapon' => ['club', '#5a4028'], 'eye' => '#ffd030', 'tusks' => true, 'bulk' => 1.7, 'size' => 1.9],
        'warlord' => ['k' => 'human', 'skin' => '#5a8a34', 'cloth' => '#8a2018', 'legs' => '#3a0c08', 'helm' => ['horns', '#3a3a40'], 'weapon' => ['axe', '#d0d0d0'], 'eye' => '#ff3010', 'cape' => '#4a0c08', 'tusks' => true, 'bulk' => 1.5, 'size' => 1.35],
        'elf' => ['k' => 'human', 'skin' => '#f4d8bc', 'cloth' => '#3f8a50', 'legs' => '#1d5434', 'hair' => '#f2d870', 'weapon' => ['bow', '#8a6a3a'], 'eye' => '#2a4a2a', 'elf' => true, 'cape' => '#2a6a3c', 'bulk' => 0.85, 'size' => 1.0],
        'blade' => ['k' => 'human', 'skin' => '#f4d8bc', 'cloth' => '#7a5ab0', 'legs' => '#3a2a6a', 'hair' => '#eceaf4', 'weapon' => ['twin', '#e4ecf4'], 'eye' => '#3a2a6a', 'elf' => true, 'bulk' => 0.9, 'size' => 1.0],
        'warden' => ['k' => 'human', 'skin' => '#f8e4cc', 'cloth' => '#f2f0e2', 'legs' => '#c8a040', 'hair' => '#fff0a8', 'weapon' => ['staff', '#a8e8ff'], 'cape' => '#c8a040', 'eye' => '#2a6aa0', 'elf' => true, 'bulk' => 1.0, 'size' => 1.1],
        'eagle' => ['k' => 'wings', 'body' => '#6a4420', 'wing' => '#8e5c2c', 'eye' => '#ffd020', 'beak' => true, 'size' => 1.3],
        'dwarf' => ['k' => 'human', 'skin' => '#ecbc94', 'cloth' => '#6a7280', 'legs' => '#3a4048', 'helm' => ['helm', '#a0a6b0'], 'beard' => '#cc6e2c', 'shield' => '#8a6a3a', 'weapon' => ['sword', '#d0d0d8'], 'eye' => '#2a2a2a', 'bulk' => 1.5, 'size' => 0.85],
        'miner' => ['k' => 'human', 'skin' => '#ecbc94', 'cloth' => '#8a5a2a', 'legs' => '#3a2a20', 'helm' => ['cap', '#e8c040'], 'beard' => '#8a5a2a', 'weapon' => ['pick', '#a8a8b0'], 'eye' => '#2a2a2a', 'bulk' => 1.4, 'size' => 0.85],
        'runebearer' => ['k' => 'human', 'skin' => '#ecbc94', 'cloth' => '#3a5a9a', 'legs' => '#1e3050', 'helm' => ['hood', '#3a5a9a'], 'beard' => '#ececec', 'weapon' => ['staff', '#80e0ff'], 'eye' => '#2a2a2a', 'bulk' => 1.3, 'size' => 0.9],
        'thane' => ['k' => 'human', 'skin' => '#ecbc94', 'cloth' => '#a8302a', 'legs' => '#5a1810', 'helm' => ['helm', '#ecc444'], 'beard' => '#cc6e2c', 'shield' => '#ecc444', 'weapon' => ['axe', '#ececf4'], 'eye' => '#2a2a2a', 'cape' => '#6a1810', 'bulk' => 1.7, 'size' => 1.05],
        'rider' => ['k' => 'beast', 'body' => '#a8743c', 'mane' => '#6a4a26', 'snout' => 'horse', 'rider' => ['skin' => '#f2cca4', 'cloth' => '#c48c30', 'legs' => '#5a3a10', 'weapon' => ['spear', '#8a6a3a'], 'helm' => ['cap', '#c48c30'], 'eye' => '#3a2a1a', 'size' => 0.82], 'size' => 1.0],
        'horsearcher' => ['k' => 'beast', 'body' => '#dcd4c4', 'mane' => '#9a9080', 'snout' => 'horse', 'rider' => ['skin' => '#f2cca4', 'cloth' => '#3f7a40', 'legs' => '#245024', 'weapon' => ['bow', '#8a6a3a'], 'helm' => ['hood', '#3f7a40'], 'eye' => '#3a2a1a', 'size' => 0.82], 'size' => 1.0],
        'horselord' => ['k' => 'beast', 'body' => '#f4f2ee', 'mane' => '#c4c0b8', 'snout' => 'horse', 'rider' => ['skin' => '#f2cca4', 'cloth' => '#ecc444', 'legs' => '#8a6a20', 'weapon' => ['spear', '#ecc444'], 'helm' => ['crown', '#ecc444'], 'cape' => '#a8302a', 'eye' => '#3a2a1a', 'size' => 0.86], 'size' => 1.15],
        'scout' => ['k' => 'human', 'skin' => '#e4bc94', 'cloth' => '#3a3a44', 'legs' => '#2a2a34', 'helm' => ['hood', '#3a3a44'], 'weapon' => ['dagger', '#c8c8d0'], 'eye' => '#e8e0a0', 'cape' => '#2a2a34', 'bulk' => 0.85, 'size' => 0.9],
        'littleman' => ['k' => 'human', 'skin' => '#ecc49c', 'cloth' => '#5a8a3a', 'legs' => '#7a5a32', 'hair' => '#8a5a2a', 'curls' => true, 'barefoot' => true, 'weapon' => ['stick', '#8a6a3a'], 'eye' => '#2a2a2a', 'bulk' => 1.0, 'size' => 0.66],
        'captain' => ['k' => 'human', 'skin' => '#ecc49c', 'cloth' => '#c84a3a', 'legs' => '#6a4a2a', 'hair' => '#c88a3a', 'curls' => true, 'helm' => ['hat', '#e8c040'], 'weapon' => ['sword', '#e8c040'], 'eye' => '#2a2a2a', 'bulk' => 1.0, 'size' => 0.85],
    ];

    /** A model of a creature (walking frame 0 .. FRAMES - 1). */
    public static function build(string $sprite, int $frame): Mesh
    {
        $d = self::KINDS[$sprite] ?? self::KINDS['grunt'];
        $frame = (($frame % self::FRAMES) + self::FRAMES) % self::FRAMES;
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
            'beast' => 19.0,
            'wings' => 7.0,
            'ram' => 13.0,
            default => 16.0,
        };
        return $base * (float)$d['size'];
    }

    /** A rod from $a to $b (a cylinder turned along it). */
    private static function rod(Mesh $m, array $a, array $b, float $r0, float $r1, array $col, int $seg = 8, ?array $top = null): void
    {
        $d = [$b[0] - $a[0], $b[1] - $a[1], $b[2] - $a[2]];
        $len = sqrt($d[0] ** 2 + $d[1] ** 2 + $d[2] ** 2) ?: 0.001;
        $m->cyl($a, $r0, $r1, $len, $col, $seg, [acos(max(-1.0, min(1.0, $d[1] / $len))), atan2($d[0], $d[2]), 0], true, $top);
    }

    /** How far the legs swing (-1..1) and how high the body bobs, at a walking frame. @return array{0: float, 1: float} */
    private static function gait(int $frame): array
    {
        $phase = $frame * 2 * M_PI / self::FRAMES;
        return [sin($phase), 0.4 * abs(cos($phase))];
    }

    /** A person, round and soft, its weapon in the right hand. $seated: on a mount (the legs hang along its flanks). */
    private static function person(array $d, int $frame, bool $seated = false): Mesh
    {
        $m = new Mesh();
        $c = static fn (string $h, float $k = 1.0): array => Mesh::rgb($h, $k);
        $skin = $c($d['skin']);
        $cloth = $c($d['cloth']);
        $legs = $c($d['legs']);
        $b = (float)($d['bulk'] ?? 1.0);
        [$swing, $bob] = $seated ? [0.0, 0.0] : self::gait($frame);

        // legs and boots (bare feet for the little folk)
        $boot = !empty($d['barefoot']) ? $skin : $c($d['legs'], 0.62);
        foreach ([-1, 1] as $side) {
            $s = $swing * $side;
            $hip = [0.0, 4.4 + $bob, $side * 1.25 * $b];
            $foot = $seated ? [1.4, 1.2, $side * 3.8 * $b] : [$s * 1.9, 0.95, $side * 1.35 * $b];
            self::rod($m, $hip, $foot, 1.15 * $b, 0.92 * $b, $legs, 8, Mesh::times($legs, 1.1));
            $m->ball([$foot[0] + 0.55, $foot[1] - 0.2, $foot[2]], [1.55 * $b, 0.9, 1.15 * $b], $boot, 8, 4, [0, 0, 0], Mesh::times($boot, 1.15));
        }
        // the body: a tunic flaring at the bottom, a belt with a buckle, round shoulders
        $y0 = 3.6 + $bob;
        $m->cyl([0, $y0, 0], 2.95 * $b, 2.25 * $b, 2.5, Mesh::times($cloth, 0.82), 12, [0, 0, 0], false, $cloth);
        $m->cyl([0, $y0 + 2.3, 0], 2.25 * $b, 2.05 * $b, 3.5, $cloth, 12, [0, 0, 0], false, Mesh::times($cloth, 1.1));
        $m->ball([0, $y0 + 5.8, 0], [2.05 * $b, 1.15, 2.3 * $b], Mesh::times($cloth, 1.1), 12, 5);
        $belt = $c($d['legs'], 0.7);
        $m->cyl([0, $y0 + 2.0, 0], 2.4 * $b, 2.35 * $b, 0.65, $belt, 12, [0, 0, 0], true, Mesh::times($belt, 1.2), true);
        $m->box([2.38 * $b, $y0 + 2.32, 0], [0.3, 0.6, 0.8], $c('#f2c94c'));
        if (isset($d['cape'])) {
            $cape = $c($d['cape']);
            $lo = Mesh::times($cape, 0.8);
            $m->quad([[-1.6 * $b, $y0 + 6.3, -2.4 * $b], [-1.6 * $b, $y0 + 6.3, 2.4 * $b], [-3.2 * $b, $y0 + 0.4, 3.0 * $b], [-3.2 * $b, $y0 + 0.4, -3.0 * $b]],
                [-0.8, 0.2, 0.0], [$cape, $cape, $lo, $lo]);
        }
        // arms swinging against the legs, round hands; the right one (z > 0) holds the weapon
        $hand = [0.0, 0.0, 0.0];
        foreach ([-1, 1] as $side) {
            $s = -$swing * $side;
            $shoulder = [0.0, $y0 + 5.5, $side * 2.55 * $b];
            $h = $seated ? [1.8, $y0 + 3.0, $side * 2.4 * $b] : [$s * 1.7 + 0.6, $y0 + 2.3, $side * 3.1 * $b];
            self::rod($m, $shoulder, $h, 0.9, 0.72, Mesh::times($cloth, 1.05), 8);
            $m->ball($h, [0.85, 0.85, 0.85], $skin, 6, 4);
            if ($side === 1) {
                $hand = $h;
            }
        }
        // the head, its eyes, ears, tusks
        $hy = $y0 + 9.0;
        $m->ball([0.2, $hy, 0], [3.2, 3.05, 3.1], Mesh::times($skin, 0.92), 12, 8, [0, 0, 0], Mesh::times($skin, 1.05));
        $eye = $c($d['eye'] ?? '#202020', isset($d['tusks']) || isset($d['ears']) ? 1.3 : 1.0);
        foreach ([-1, 1] as $side) {
            $m->ball([3.05, $hy + 0.25, $side * 1.15], [0.32, 0.55, 0.42], $eye, 6, 4);
        }
        $m->ball([3.3, $hy - 0.5, 0], [0.45, 0.4, 0.45], Mesh::times($skin, 0.95), 5, 3);
        if (!empty($d['tusks'])) {
            foreach ([-1, 1] as $side) {
                self::rod($m, [2.7, $hy - 1.5, $side * 0.95], [3.15, $hy - 0.5, $side * 1.25], 0.34, 0.0, $c('#f4ecd4'), 5);
                $m->box([2.75, $hy + 1.0, $side * 1.2], [0.5, 0.35, 1.4], Mesh::times($skin, 0.6), [$side * 0.3, 0, 0]);
            }
        }
        if (!empty($d['ears'])) {
            foreach ([-1, 1] as $side) {
                self::rod($m, [0.2, $hy + 0.4, $side * 2.6], [-0.4, $hy + 1.6, $side * 5.4], 1.0, 0.0, $skin, 6);
            }
        }
        if (!empty($d['elf'])) {
            foreach ([-1, 1] as $side) {
                self::rod($m, [0.0, $hy + 0.2, $side * 2.7], [-1.0, $hy + 2.0, $side * 3.7], 0.55, 0.0, $skin, 5);
            }
        }
        // hair (long, or curly), beard
        if (isset($d['hair'])) {
            $hair = $c($d['hair']);
            if (!empty($d['curls'])) {
                $m->ball([-0.3, $hy + 0.9, 0], [3.2, 2.7, 3.3], Mesh::times($hair, 0.9), 10, 6, [0, 0, 0], Mesh::times($hair, 1.15), 0.12, 3);
            } else {
                $m->ball([-0.5, $hy + 0.8, 0], [3.25, 2.8, 3.3], Mesh::times($hair, 0.92), 12, 6, [0, 0, 0], Mesh::times($hair, 1.1));
                $m->ball([-2.0, $hy - 1.6, 0], [1.6, 3.2, 2.8], Mesh::times($hair, 0.9), 8, 5);
            }
        }
        if (isset($d['beard'])) {
            $beard = $c($d['beard']);
            $m->ball([2.2, $hy - 2.0, 0], [1.7, 2.6, 2.7], Mesh::times($beard, 0.92), 10, 6, [0, 0, 0], Mesh::times($beard, 1.1), 0.1, 2);
            $m->ball([2.9, $hy - 0.6, 0], [0.8, 0.5, 1.6], $beard, 6, 3);
        }
        if (isset($d['helm'])) {
            self::helm($m, $d['helm'][0], $c($d['helm'][1]), $hy);
        }
        if (isset($d['shield'])) {
            $sh = $c($d['shield']);
            $m->cyl([1.0, $y0 + 2.6, -3.6 * $b], 2.5, 2.5, 0.6, $sh, 14, [-M_PI / 2, 0, 0], true, Mesh::times($sh, 1.15), true);
            $m->ball([1.0, $y0 + 2.6, -4.3 * $b], [0.7, 0.7, 0.5], $c('#f2c94c'), 6, 4);
        }
        if (isset($d['weapon'])) {
            self::weapon($m, $d['weapon'][0], $c($d['weapon'][1]), $hand);
        }
        return $m;
    }

    private static function human(array $d, int $frame): Mesh
    {
        return self::person($d, $frame);
    }

    private static function helm(Mesh $m, string $type, array $col, float $hy): void
    {
        $hi = Mesh::times($col, 1.18);
        switch ($type) {
            case 'cap':
                $m->ball([-0.1, $hy + 1.0, 0], [3.35, 2.4, 3.35], $col, 12, 6, [0, 0, 0], $hi);
                $m->cyl([1.4, $hy + 0.6, 0], 2.6, 2.6, 0.3, Mesh::times($col, 0.9), 10, [0, 0, -0.25]);
                break;
            case 'helm':
                $m->ball([0.0, $hy + 0.9, 0], [3.45, 2.7, 3.45], $col, 12, 6, [0, 0, 0], $hi);
                $m->cyl([0.0, $hy - 0.1, 0], 3.5, 3.5, 0.6, Mesh::times($col, 0.85), 12);
                $m->box([3.3, $hy - 0.2, 0], [0.4, 2.2, 0.7], $col);
                $m->ball([-0.4, $hy + 3.4, 0], [1.8, 0.7, 0.5], $hi, 8, 4);
                break;
            case 'horns':
                $m->ball([0.0, $hy + 0.9, 0], [3.45, 2.7, 3.45], $col, 12, 6, [0, 0, 0], $hi);
                foreach ([-1, 1] as $side) {
                    self::rod($m, [0.0, $hy + 1.6, $side * 2.8], [0.6, $hy + 3.2, $side * 4.6], 0.9, 0.55, Mesh::rgb('#ece4cc'), 6);
                    self::rod($m, [0.6, $hy + 3.2, $side * 4.6], [1.6, $hy + 5.6, $side * 4.4], 0.55, 0.0, Mesh::rgb('#f4eedc'), 6);
                }
                break;
            case 'hood':
                $m->ball([-0.4, $hy + 0.6, 0], [3.6, 3.4, 3.5], Mesh::times($col, 0.9), 12, 6, [0, 0, 0], $hi);
                self::rod($m, [-1.4, $hy + 2.0, 0], [-3.6, $hy + 3.4, 0], 1.6, 0.0, $col, 8);
                $m->ball([2.4, $hy, 0], [0.9, 2.6, 2.5], Mesh::rgb('#1c1820'), 8, 4);
                break;
            case 'hat':
                $m->cyl([0, $hy + 2.0, 0], 4.6, 4.4, 0.45, $col, 14, [0, 0, 0], true, $hi, true);
                $m->cyl([0, $hy + 2.4, 0], 2.6, 2.1, 2.6, $col, 12, [0, 0, 0], true, $hi);
                self::rod($m, [-1.0, $hy + 3.4, 1.8], [-4.0, $hy + 6.0, 2.4], 0.5, 0.1, Mesh::rgb('#f4f0e8'), 5);
                break;
            case 'crown':
                $m->cyl([0, $hy + 2.0, 0], 2.9, 3.2, 1.6, $col, 12, [0, 0, 0], false, $hi);
                foreach ([0, 1, 2, 3, 4, 5] as $k) {
                    $a = $k * M_PI / 3;
                    $m->cyl([cos($a) * 3.0, $hy + 3.5, sin($a) * 3.0], 0.6, 0.0, 1.6, $hi, 4);
                }
                $m->ball([3.1, $hy + 2.8, 0], [0.4, 0.5, 0.5], Mesh::rgb('#e84a4a', 1.2), 5, 3);
                break;
        }
    }

    /** The weapon in the right hand, at $h. */
    private static function weapon(Mesh $m, string $type, array $col, array $h): void
    {
        [$x, $y, $z] = $h;
        $wood = Mesh::rgb('#7a5434');
        $hi = Mesh::times($col, 1.2);
        switch ($type) {
            case 'club':
                self::rod($m, [$x - 0.4, $y - 1.6, $z], [$x + 1.6, $y + 6.2, $z], 0.5, 1.5, $col, 8, $hi);
                foreach ([[0.8, 4.0], [1.4, 5.4]] as [$dx, $dy]) {
                    $m->ball([$x + $dx, $y + $dy, $z + 0.9], [0.5, 0.5, 0.5], Mesh::rgb('#d8d4c8'), 4, 2);
                }
                break;
            case 'axe':
                self::rod($m, [$x - 0.4, $y - 2.0, $z], [$x + 1.4, $y + 7.4, $z], 0.4, 0.4, $wood, 6);
                $m->tri([$x + 1.2, $y + 6.8, $z], [$x + 4.2, $y + 8.6, $z], [$x + 4.0, $y + 4.6, $z], [0, 0, 1], $hi, $hi, $col);
                $m->tri([$x + 1.2, $y + 6.8, $z], [$x + 4.0, $y + 4.6, $z], [$x + 0.9, $y + 5.6, $z], [0, 0, 1], $hi, $col, $col);
                break;
            case 'sword':
                self::rod($m, [$x, $y - 0.6, $z], [$x + 0.9, $y + 1.2, $z], 0.35, 0.35, Mesh::rgb('#5a3a22'), 5);
                $m->box([$x + 0.9, $y + 1.3, $z], [0.6, 0.6, 2.8], Mesh::rgb('#f2c94c'), [0, 0, -0.45]);
                self::rod($m, [$x + 0.9, $y + 1.3, $z], [$x + 3.6, $y + 7.6, $z], 0.55, 0.15, $hi, 4, Mesh::times($hi, 1.1));
                break;
            case 'twin':
                foreach ([-1, 1] as $side) {
                    $zz = $side > 0 ? $z : -$z;
                    self::rod($m, [$x + 0.4, $y + 0.4, $zz], [$x + 3.6, $y + 5.2, $zz + $side * 0.6], 0.45, 0.1, $hi, 4);
                }
                break;
            case 'spear':
                self::rod($m, [$x - 1.6, $y - 4.0, $z], [$x + 2.6, $y + 12.0, $z], 0.32, 0.32, $col, 6);
                self::rod($m, [$x + 2.6, $y + 12.0, $z], [$x + 3.3, $y + 14.6, $z], 0.75, 0.0, Mesh::rgb('#e0e4ec'), 6);
                break;
            case 'bow':
                $pts = [[$x + 0.4, $y - 4.4], [$x + 1.6, $y - 1.6], [$x + 1.9, $y + 1.6], [$x + 0.6, $y + 4.6]];
                for ($i = 0; $i < 3; $i++) {
                    self::rod($m, [$pts[$i][0], $pts[$i][1], $z + 0.4], [$pts[$i + 1][0], $pts[$i + 1][1], $z + 0.4], 0.32, 0.32, $col, 5);
                }
                self::rod($m, [$x + 0.4, $y - 4.4, $z + 0.4], [$x + 0.6, $y + 4.6, $z + 0.4], 0.08, 0.08, Mesh::rgb('#f0e8d0'), 3);
                break;
            case 'dagger':
                self::rod($m, [$x + 0.2, $y, $z], [$x + 2.6, $y + 2.4, $z], 0.45, 0.05, $hi, 4);
                break;
            case 'pick':
                self::rod($m, [$x - 0.4, $y - 2.0, $z], [$x + 1.2, $y + 6.6, $z], 0.4, 0.4, $wood, 6);
                self::rod($m, [$x - 1.4, $y + 6.0, $z], [$x + 3.6, $y + 7.0, $z], 0.5, 0.15, $hi, 5);
                break;
            case 'staff':
                self::rod($m, [$x + 0.4, 0.2, $z + 0.4], [$x + 0.8, $y + 10.0, $z + 0.4], 0.45, 0.4, $wood, 6);
                $m->ball([$x + 0.8, $y + 11.0, $z + 0.4], [1.4, 1.4, 1.4], Mesh::times($col, 1.3), 8, 5);
                break;
            case 'stick':
                self::rod($m, [$x - 0.4, $y - 1.0, $z], [$x + 1.6, $y + 5.0, $z], 0.32, 0.28, $col, 5);
                break;
        }
    }

    /** A mount (horse, wolf) on its four legs, with or without a rider. */
    private static function beast(array $d, int $frame): Mesh
    {
        $m = new Mesh();
        $body = Mesh::rgb($d['body']);
        $top = Mesh::times($body, 1.12);
        $mane = Mesh::rgb($d['mane']);
        $dark = Mesh::times($body, 0.72);
        $wolf = ($d['snout'] ?? 'horse') === 'wolf';
        [$swing, $bob] = self::gait($frame);
        $y = ($wolf ? 6.0 : 7.4) + $bob * 0.6;
        // four legs: the diagonals move together
        foreach ([[3.6, -1, 1], [3.6, 1, -1], [-3.8, -1, -1], [-3.8, 1, 1]] as [$x, $side, $dir]) {
            $s = $swing * $dir * 1.8;
            self::rod($m, [$x, $y - 0.4, $side * 1.5], [$x + $s, 0.9, $side * 1.6], 1.0, 0.7, $dark, 8, $body);
            $m->ball([$x + $s + 0.2, 0.6, $side * 1.6], [0.9, 0.6, 0.8], Mesh::rgb($wolf ? '#3a2e26' : '#2e2622'), 6, 3);
        }
        // the body, the chest, the neck and head
        $m->ball([0, $y + 1.4, 0], [6.2, 3.0, 2.8], $dark, 12, 7, [0, 0, 0], $top);
        $m->ball([4.0, $y + 1.8, 0], [3.0, 2.9, 2.6], $dark, 10, 6, [0, 0, 0], $top);
        $nx = $wolf ? 6.2 : 6.6;
        $ny = $wolf ? $y + 3.6 : $y + 6.0;
        self::rod($m, [4.4, $y + 2.6, 0], [$nx, $ny, 0], 1.9, 1.3, $body, 10, $top);
        $m->ball([$nx + 0.4, $ny + 0.4, 0], [2.3, 1.9, 1.7], $body, 10, 6, [0, 0, 0], $top);
        if ($wolf) {
            self::rod($m, [$nx + 1.6, $ny, 0], [$nx + 4.2, $ny - 0.6, 0], 1.0, 0.45, $body, 8, $top);
            $m->ball([$nx + 4.2, $ny - 0.6, 0], [0.45, 0.45, 0.45], Mesh::rgb('#1a1414'), 5, 3);
            foreach ([-1, 1] as $side) {
                self::rod($m, [$nx + 1.4, $ny - 1.0, $side * 0.5], [$nx + 2.8, $ny - 1.3, $side * 0.7], 0.25, 0.0, Mesh::rgb('#f4ecd4'), 4);
            }
        } else {
            $m->ball([$nx + 2.4, $ny - 0.4, 0], [1.9, 1.2, 1.25], Mesh::times($body, 0.95), 10, 5);
            $m->ball([$nx + 3.9, $ny - 0.6, 0], [0.5, 0.6, 0.9], Mesh::rgb('#2e2622'), 6, 3);
        }
        foreach ([-1, 1] as $side) {
            self::rod($m, [$nx - 0.2, $ny + 1.4, $side * 0.9], [$nx - 0.6, $ny + 3.4, $side * 1.1], 0.55, 0.0, $mane, 5);
            $m->ball([$nx + 1.6, $ny + 0.7, $side * 1.25], [0.35, 0.45, 0.35], Mesh::rgb($wolf ? '#ff4030' : '#1a1414', $wolf ? 1.4 : 1.0), 5, 3);
        }
        // the mane down the neck, a tail
        for ($k = 0; $k < 4; $k++) {
            $t = $k / 3;
            $m->ball([4.6 + ($nx - 4.8) * $t - 0.8, $y + 3.2 + ($ny - $y - 2.6) * $t + 0.6, 0], [1.1, 1.2, 0.8], $mane, 6, 4, [0, 0, 0.4], Mesh::times($mane, 1.2));
        }
        $tail = [-8.6, $y - 1.6 + $swing * 0.5, 0];
        self::rod($m, [-5.8, $y + 2.2, 0], $tail, 0.9, 0.4, $mane, 6);
        $m->ball($tail, [1.2, 1.6, 0.9], $mane, 6, 4, [0, 0, 0.5]);
        if (isset($d['rider'])) {
            $r = $d['rider'];
            $rider = self::person(['bulk' => 1.0] + $r, $frame, true);
            $m->add($rider->scaleAll((float)($r['size'] ?? 0.82)), [-0.6, $y + 2.6, 0]);
            // a saddle
            $m->ball([-0.6, $y + 3.8, 0], [2.6, 0.8, 3.0], Mesh::rgb('#6a3a20'), 10, 4, [0, 0, 0], Mesh::rgb('#8a5230'));
        }
        return $m;
    }

    /** A flying creature: a round body, flapping wings (a bat's skin, an eagle's feathers). */
    private static function wings(array $d, int $frame): Mesh
    {
        $m = new Mesh();
        $body = Mesh::rgb($d['body']);
        $wing = Mesh::rgb($d['wing']);
        $beak = !empty($d['beak']);
        $flap = [0.75, 0.2, -0.45, 0.2][$frame % 4];
        $m->ball([0, 3.0, 0], [3.4, 2.4, 2.2], Mesh::times($body, 0.85), 10, 6, [0, 0, 0], Mesh::times($body, 1.15));
        $head = $beak ? Mesh::rgb('#f4f0e4') : $body;
        $m->ball([3.6, 3.8, 0], [1.9, 1.8, 1.8], Mesh::times($head, 0.92), 10, 6, [0, 0, 0], Mesh::times($head, 1.08));
        foreach ([-1, 1] as $side) {
            $m->ball([5.2, 4.2, $side * 0.75], [0.3, 0.45, 0.35], Mesh::rgb($d['eye'], 1.35), 5, 3);
            if (!$beak) {
                self::rod($m, [3.2, 5.0, $side * 1.0], [3.0, 7.2, $side * 1.6], 0.7, 0.0, $body, 5);
            }
        }
        if ($beak) {
            self::rod($m, [5.1, 3.6, 0], [6.8, 3.0, 0], 0.7, 0.0, Mesh::rgb('#f2c040'), 6);
            // a fan of tail feathers
            $m->tri([-2.8, 3.0, -0.4], [-6.6, 2.6, -2.2], [-6.6, 2.6, 2.2], [0, 1, 0], $wing, Mesh::times($wing, 1.2), Mesh::times($wing, 1.2));
        }
        // the wings: an inner part and the tip, raised by the flap; their edge lighter
        foreach ([-1, 1] as $side) {
            $r1 = [0.2, 3.4 + $flap * 3.4, $side * 6.0];
            $r2 = [-1.6, 3.2 + $flap * 6.4, $side * 10.8];
            $root = [0.8, 3.6, $side * 1.6];
            $back = [-2.2, 3.2, $side * 1.6];
            $edge = Mesh::times($wing, $beak ? 1.35 : 1.15);
            $m->tri($root, $r1, $back, [0, 1, 0], $wing, $wing, $wing);
            $m->tri($back, $r1, [-2.4, 2.8 + $flap * 3.0, $side * 6.4], [0, 1, 0], $wing, $wing, $edge);
            $m->tri($r1, $r2, [-2.4, 2.8 + $flap * 3.0, $side * 6.4], [0, 1, 0], $wing, $edge, $edge);
            if (!$beak) {
                // the bones of a bat's wing
                self::rod($m, $root, $r1, 0.25, 0.2, Mesh::times($body, 0.8), 4);
                self::rod($m, $r1, $r2, 0.2, 0.1, Mesh::times($body, 0.8), 4);
            }
        }
        foreach ([-1, 1] as $side) {
            self::rod($m, [-0.6, 1.2, $side * 0.8], [-1.0, 0.0, $side * 0.9], 0.3, 0.2, Mesh::rgb($beak ? '#f2c040' : '#2a2026'), 4);
        }
        return $m;
    }

    /** The siege ram of the horde: a roofed cart on wheels with a log headed with iron. */
    private static function ram(int $frame): Mesh
    {
        $m = new Mesh();
        $wood = Mesh::rgb('#8a6a3a');
        $dark = Mesh::rgb('#4e3420');
        $red = Mesh::rgb('#8e3a2a');
        $iron = Mesh::rgb('#7a7e88');
        $turn = $frame * M_PI / 4;
        $m->slab([0, 2.0, 0], [14.0, 1.8, 8.4], $dark, [0, 0, 0], $wood);
        foreach ([[-4.8, -1], [-4.8, 1], [4.8, -1], [4.8, 1]] as [$x, $side]) {
            $m->cyl([$x, 2.9, $side * 4.8], 2.8, 2.8, 1.1, $dark, 12, [M_PI / 2, $turn, 0], true, $wood, true);
            $m->cyl([$x, 2.9, $side * 5.4], 0.7, 0.7, 0.4, $iron, 8, [M_PI / 2, 0, 0]);
        }
        foreach ([-1, 1] as $side) {
            $m->slab([0, 3.8, $side * 3.9], [12.4, 6.0, 0.8], Mesh::times($red, 0.85), [0, 0, 0], $red);
            foreach ([-5.6, 0.0, 5.6] as $x) {
                $m->slab([$x, 3.8, $side * 4.3], [0.8, 6.4, 0.4], $dark);
            }
        }
        foreach ([-1, 1] as $side) {
            $m->box([-0.8, 12.4, $side * 2.6], [14.0, 0.9, 6.4], $wood, [$side * 0.6, 0, 0], Mesh::times($wood, 1.2));
        }
        self::rod($m, [-9.0, 6.4, 0], [10.4, 6.4, 0], 1.7, 1.7, Mesh::rgb('#a07a46'), 10, Mesh::rgb('#b88a54'));
        $m->cyl([10.4, 6.4, 0], 2.2, 1.6, 2.6, $iron, 10, [0, 0, -M_PI / 2], true, Mesh::times($iron, 1.25));
        foreach ([-1, 1] as $side) {
            $m->ball([12.6, 7.2, $side * 0.9], [0.4, 0.5, 0.4], Mesh::rgb('#ff5030', 1.3), 4, 2);
        }
        return $m;
    }
}
