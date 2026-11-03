<?php

namespace App\View3d;

/**
 * The towers as 3D models, standing on y = 0 (the platform's top) around x = z = 0, about 24 to 50 units tall.
 * The level (1..3) makes them taller, gives them a flag of the side from level 2 and gold from level 3; a
 * specialization (0 / 1) adds a glowing gem, gold or red, and changes a detail of the tower.
 */
final class Towers
{
    /** Sprite names of the towers (towers.yaml `sprite`). */
    public const SPRITES = ['archer', 'sanctuary', 'sentinel', 'songmage', 'crossbow', 'slingshot', 'forge', 'mine', 'stables', 'javelin',
        'horn', 'palisade', 'slingers', 'traps', 'inn', 'garden', 'barracks', 'catapult', 'pitch', 'drums'];

    public static function build(string $sprite, int $level, ?int $spec, string $flag = '#3fa860'): Mesh
    {
        $lv = max(1, min(3, $level));
        $m = new Mesh();
        $H = 1.0 + 0.17 * ($lv - 1) + ($spec !== null ? 0.08 : 0.0);
        $f = Mesh::rgb($flag);
        $top = match ($sprite) {
            'archer' => self::archer($m, $H, $spec, $lv, $f),
            'sanctuary' => self::sanctuary($m, $H, $spec, $lv),
            'sentinel' => self::sentinel($m, $H, $spec, $lv),
            'songmage' => self::songmage($m, $H, $spec, $lv),
            'crossbow' => self::crossbow($m, $H, $spec, $lv, $f),
            'slingshot' => self::slingshot($m, $H, $spec, $lv),
            'forge' => self::forge($m, $H, $spec, $lv),
            'mine' => self::mine($m, $H, $spec, $lv),
            'stables' => self::stables($m, $H, $spec, $lv),
            'javelin' => self::javelin($m, $H, $spec, $lv),
            'horn' => self::horn($m, $H, $spec, $lv),
            'palisade' => self::palisade($m, $H, $spec, $lv),
            'slingers' => self::slingers($m, $H, $spec, $lv),
            'traps' => self::traps($m, $H, $spec, $lv),
            'inn' => self::inn($m, $H, $spec, $lv),
            'garden' => self::garden($m, $H, $spec, $lv),
            'barracks' => self::barracks($m, $H, $spec, $lv),
            'catapult' => self::catapult($m, $H, $spec, $lv),
            'pitch' => self::pitch($m, $H, $spec, $lv),
            'drums' => self::drums($m, $H, $spec, $lv),
            default => self::archer($m, $H, $spec, $lv, $f),
        };
        if ($lv >= 2 && $sprite !== 'archer' && $sprite !== 'crossbow') {
            self::flag($m, 9.0, $top - 3.0, 0.0, $f);
        }
        if ($spec !== null) {
            // the gem of the specialization: gold or red, a little light
            $m->ball([0, $top + 2.4, 0], [1.9, 2.4, 1.9], Mesh::rgb($spec === 0 ? '#ffe070' : '#ff5a40', 1.15), 6, 4);
        }
        return $m;
    }

    /** About how tall a tower is, for its bar and its marks. */
    public static function height(int $level): float
    {
        return 26.0 + 5.0 * max(1, min(3, $level));
    }

    private static function flag(Mesh $m, float $x, float $y, float $z, array $col): void
    {
        $m->cyl([$x, $y, $z], 0.45, 0.45, 9.0, Mesh::rgb('#3a2a1a'), 4);
        $m->box([$x + 2.8, $y + 7.4, $z], [5.4, 3.4, 0.5], $col);
    }

    private static function archer(Mesh $m, float $H, ?int $spec, int $lv, array $flag): float
    {
        $stone = Mesh::rgb('#d8d4c4');
        $shade = Mesh::rgb('#a09c8c');
        $roof = Mesh::rgb($spec === 0 ? '#3a8ad0' : '#3fa860');
        $h = 19.0 * $H;
        $m->cyl([0, 0, 0], 9.0, 7.4, $h, $stone, 10);
        $m->cyl([0, $h, 0], 10.4, 10.0, 3.0, $shade, 10);
        for ($i = 0; $i < 6; $i++) {
            $m->slab([cos($i * 1.047) * 9.7, $h + 3.0, sin($i * 1.047) * 9.7], [2.8, 2.4, 2.8], $shade);
        }
        $m->cyl([0, $h + 2.6, 0], 11.2, 0.0, 12.0, $roof, 10);
        $m->slab([0, 0, 8.2], [3.6, 5.0, 1.2], Mesh::rgb('#3a2614'));
        $m->slab([0, $h * 0.55, 7.2], [1.6, 3.2, 1.2], Mesh::rgb('#2a2018'));
        if ($lv >= 3) {
            $m->cyl([0, $h * 0.42, 0], 8.7, 8.7, 1.0, Mesh::rgb('#e8c040'), 10);
        }
        if ($spec === 1) {
            foreach ([-1, 1] as $side) {
                $m->cyl([0, 0, $side * 9.5], 3.4, 3.0, $h * 0.62, $stone, 7);
                $m->cyl([0, $h * 0.62, $side * 9.5], 4.0, 0.0, 4.6, $roof, 7);
            }
        }
        self::flag($m, 0.0, $h + 13.0, 0.0, $flag);
        return $h + 14.0;
    }

    private static function sanctuary(Mesh $m, float $H, ?int $spec, int $lv): float
    {
        $white = Mesh::rgb('#f0f0e8');
        $shade = Mesh::rgb('#b0b8c8');
        $glow = Mesh::rgb($spec === 0 ? '#a0f0ff' : ($spec === 1 ? '#ffe070' : '#9ab8ff'), 1.2);
        $h = 12.0 * $H;
        $m->cyl([0, 0, 0], 11.0, 10.0, 1.6, $shade, 12);
        for ($i = 0; $i < 6; $i++) {
            $m->cyl([cos($i * 1.047) * 8.2, 1.6, sin($i * 1.047) * 8.2], 1.5, 1.3, $h, $white, 6);
        }
        $m->cyl([0, $h + 1.6, 0], 10.0, 9.0, 1.4, $shade, 12);
        $m->ball([0, $h + 3.0, 0], [8.6, 5.6 + $lv * 0.6, 8.6], $white, 12, 5);
        $m->ball([0, 5.5, 0], [2.6, 2.6, 2.6], $glow, 8, 4);
        $m->ball([0, $h + 10.4, 0], [2.4, 2.4, 0.9], Mesh::rgb('#f4f0b0', 1.1), 7, 4);
        return $h + 12.0;
    }

    private static function sentinel(Mesh $m, float $H, ?int $spec, int $lv): float
    {
        $bark = Mesh::rgb($spec === 1 ? '#5a3a1c' : '#7a5230');
        $leaf = Mesh::rgb($spec === 0 ? '#6a3a8a' : ($spec === 1 ? '#2e6a2a' : '#4caf50'));
        $leaf2 = Mesh::rgb($spec === 0 ? '#3a1a5a' : '#2e7d32');
        $h = 14.0 * $H;
        $m->cyl([0, 0, 0], 5.0, 3.2, $h, $bark, 8);
        foreach ([[-1, 0.9], [0.8, -0.6], [0.1, 1.2]] as $i => [$dx, $dz]) {
            $m->cyl([$dx * 3.0, 0, $dz * 3.0], 2.4, 0.6, 5.0, $bark, 5, [$dz * 0.5, 0, -$dx * 0.5]);
        }
        $r = 8.0 + $lv * 1.4;
        $m->ball([0, $h + 4.0, 0], [$r, $r * 0.85, $r], $leaf, 8, 5);
        $m->ball([-$r * 0.5, $h + 2.0, $r * 0.35], [$r * 0.6, $r * 0.5, $r * 0.6], $leaf2, 7, 4);
        $m->ball([$r * 0.45, $h + 7.0, -$r * 0.3], [$r * 0.55, $r * 0.45, $r * 0.55], Mesh::rgb($spec === 0 ? '#a070c0' : '#8ad07a'), 7, 4);
        foreach ([-1, 1] as $side) {
            $m->box([4.6, $h * 0.55, $side * 1.5], [0.8, 1.2, 1.2], Mesh::rgb('#ffe060', 1.2));
        }
        if ($spec === 0) {
            foreach ([[-5, 3], [4, 6], [6, -3], [-4, -6]] as [$dx, $dz]) {
                $m->cyl([$dx, $h + 9, $dz], 0.5, 0.0, 2.6, Mesh::rgb('#e0d0f0'), 4);
            }
        }
        return $h + $r * 1.4;
    }

    private static function songmage(Mesh $m, float $H, ?int $spec, int $lv): float
    {
        $robe = Mesh::rgb($spec === 1 ? '#5a2a3a' : '#7a4ab8');
        $gem = Mesh::rgb($spec === 0 ? '#fff070' : ($spec === 1 ? '#ff4040' : '#90f0ff'), 1.2);
        $h = 16.0 * $H;
        $m->cyl([0, 0, 0], 7.4, 2.2, $h, $robe, 9);
        $m->cyl([0, $h * 0.4, 0], 6.0, 5.0, 1.2, Mesh::rgb('#e8c040'), 9);
        $m->ball([0, $h + 2.0, 0], [3.2, 3.4, 3.2], Mesh::rgb('#f0d0b0'), 7, 4);
        $m->cyl([0, $h + 3.2, 0], 4.4, 0.0, 8.0, $robe, 8);
        $m->cyl([6.0, 0, 3.0], 0.5, 0.5, $h + 6.0, Mesh::rgb('#6a4a2a'), 5);
        $m->ball([6.0, $h + 7.4, 3.0], [1.9, 2.3, 1.9], $gem, 6, 4);
        for ($i = 0; $i < $lv; $i++) {
            $a = $i * 2.1 + 0.4;
            $m->ball([cos($a) * 10.0, $h * 0.5 + $i * 3.0, sin($a) * 10.0], [0.9, 0.9, 0.9], Mesh::rgb('#e8c040', 1.2), 4, 3);
        }
        return $h + 11.0;
    }

    private static function crossbow(Mesh $m, float $H, ?int $spec, int $lv, array $flag): float
    {
        $stone = Mesh::rgb('#8a9ab0');
        $dark = Mesh::rgb('#56657a');
        $wood = Mesh::rgb('#7a5230');
        $h = 17.0 * $H;
        $m->slab([0, 0, 0], [15.0, $h, 15.0], $stone);
        $m->slab([5.8, 0, 0], [3.4, $h, 15.0], $dark);
        for ($i = 0; $i < 4; $i++) {
            $m->slab([-6.0 + $i * 4.0, $h, 6.4], [2.6, 2.6, 2.6], $stone);
            $m->slab([-6.0 + $i * 4.0, $h, -6.4], [2.6, 2.6, 2.6], $stone);
        }
        $m->slab([0, 0, 7.6], [3.6, 5.0, 1.0], Mesh::rgb('#2a1a0a'));
        // the crossbow on the roof
        $m->box([0, $h + 3.6, 0], [10.0, 1.6, 2.0], $wood);
        $m->box([3.0, $h + 3.6, 0], [1.8, 1.2, 12.0], $dark, [0, 0, 0]);
        $m->box([5.0, $h + 5.2, 5.4], [0.5, 4.0, 0.5], Mesh::rgb('#d0d0d0'), [0.6, 0, 0]);
        $m->box([5.0, $h + 5.2, -5.4], [0.5, 4.0, 0.5], Mesh::rgb('#d0d0d0'), [-0.6, 0, 0]);
        self::flag($m, -5.0, $h, -5.0, Mesh::rgb('#4a78a8'));
        return $h + 7.0;
    }

    private static function slingshot(Mesh $m, float $H, ?int $spec, int $lv): float
    {
        $wood = Mesh::rgb('#8a6a3a');
        $dark = Mesh::rgb('#5a4020');
        $h = 15.0 * $H;
        $m->slab([0, 0, 0], [16.0, 2.0, 11.0], $dark);
        foreach ([-1, 1] as $side) {
            $m->box([0, $h * 0.55 + 1.0, $side * 4.4], [2.0, $h * 1.15, 1.8], $wood, [$side * 0.18, 0, 0]);
        }
        $m->box([-1.0, $h * 0.5, 0], [2.0, 1.4, 11.0], $dark);
        $m->box([3.4, $h * 0.78, 0], [12.0, 1.6, 1.6], $wood, [0, 0, 0.6]);
        $m->ball([7.6, $h * 0.78 + 4.6, 0], [3.0, 3.0, 3.0], Mesh::rgb($spec === 1 ? '#7a3a20' : '#9a9a90'), 6, 4);
        return $h + 8.0;
    }

    private static function forge(Mesh $m, float $H, ?int $spec, int $lv): float
    {
        $stone = Mesh::rgb('#7a7a86');
        $dark = Mesh::rgb('#4a4a56');
        $h = 11.0 * $H;
        $m->slab([0, 0, 0], [17.0, $h, 14.0], $stone);
        $m->slab([6.4, 0, 0], [3.6, $h, 14.0], $dark);
        $m->slab([-4.6, 0, 0], [3.2, $h + 8.0, 3.2], $dark);
        $m->cyl([-4.6, $h + 8.0, 0], 2.6, 2.0, 2.4, Mesh::rgb('#c8c8c8'), 7);
        $m->slab([0, 0, 7.2], [7.0, 6.0, 0.8], Mesh::rgb('#140c08'));
        $m->slab([0, 0, 7.4], [5.0, 4.0, 0.6], Mesh::rgb('#ff8a20', 1.25));
        $m->slab([-7.0, 0, 7.0], [4.6, 2.6, 3.4], Mesh::rgb('#2a2a30'));
        $m->slab([-7.0, 2.6, 7.0], [5.6, 1.0, 4.0], Mesh::rgb('#4a4a50'));
        return $h + 11.0;
    }

    private static function mine(Mesh $m, float $H, ?int $spec, int $lv): float
    {
        $rock = Mesh::rgb('#7a6a5a');
        $m->ball([0, 1.0, 0], [11.6, 8.0 * $H, 11.0], $rock, 9, 4);
        $m->ball([6.0, 0.5, -4.0], [5.0, 3.6, 5.0], Mesh::rgb('#4a3e32'), 6, 3);
        $m->slab([6.8, 0, 0], [3.0, 7.2, 8.4], Mesh::rgb('#8a6a3a'));
        $m->slab([8.2, 0, 0], [1.2, 6.0, 6.6], Mesh::rgb('#140c08'));
        $m->ball([4.6, 1.4, 6.6], [2.8, 2.0, 2.8], Mesh::rgb('#f0c030', 1.2), 6, 3);
        $m->slab([-3.0, 6.4 * $H, -2.0], [2.6, 5.0, 2.6], Mesh::rgb('#6a4a2a'));
        return 9.0 * $H + 7.0;
    }

    private static function stables(Mesh $m, float $H, ?int $spec, int $lv): float
    {
        $wood = Mesh::rgb('#9a6a3a');
        $dark = Mesh::rgb('#6a4220');
        $roof = Mesh::rgb('#a83a2a');
        $h = 9.0 * $H;
        $m->slab([0, 0, 0], [16.0, $h, 20.0], $wood);
        foreach ([-6, -2, 2, 6] as $z) {
            $m->slab([8.1, 0, $z], [0.5, $h, 0.9], $dark);
        }
        foreach ([-1, 1] as $side) {
            $m->box([0, $h + 3.4, $side * 5.6], [20.0, 1.0, 11.6], $roof, [-$side * 0.62, 0, 0]);
        }
        $m->slab([8.2, 0, 0], [0.6, 6.0, 7.0], Mesh::rgb('#2a1a0a'));
        $m->box([8.4, $h + 2.4, 0], [0.8, 3.6, 2.2], Mesh::rgb('#f0dca0'), [0, 0, -0.3]);
        return $h + 8.0;
    }

    private static function javelin(Mesh $m, float $H, ?int $spec, int $lv): float
    {
        $wood = Mesh::rgb('#9a7040');
        $dark = Mesh::rgb('#6a4a26');
        $h = 17.0 * $H;
        foreach ([[-1, -1], [-1, 1], [1, -1], [1, 1]] as [$x, $z]) {
            $m->cyl([$x * 6.0, 0, $z * 6.0], 1.1, 0.9, $h, $wood, 5, [$z * -0.05, 0, $x * 0.05]);
        }
        $m->slab([0, $h * 0.5, 0], [11.0, 0.8, 11.0], $dark);
        $m->slab([0, $h, 0], [14.0, 1.2, 14.0], $dark);
        $m->cyl([0, $h + 1.2, 0], 9.6, 0.0, 8.0, Mesh::rgb('#c08a30'), 4, [0, M_PI / 4, 0]);
        foreach ([-1, 0, 1] as $k) {
            $m->cyl([$k * 2.2, $h + 1.2, 5.6], 0.4, 0.4, 8.0, Mesh::rgb('#d8c8a0'), 4, [0, 0, 0.1]);
        }
        return $h + 10.0;
    }

    private static function horn(Mesh $m, float $H, ?int $spec, int $lv): float
    {
        $stone = Mesh::rgb('#c8b88a');
        $gold = Mesh::rgb('#e8c040', 1.1);
        $h = 13.0 * $H;
        $m->cyl([0, 0, 0], 6.2, 5.2, 2.0, Mesh::rgb('#8a7a5a'), 8);
        $m->cyl([0, 2.0, 0], 3.8, 3.0, $h, $stone, 8);
        $m->cyl([-4.0, $h + 3.0, 0], 1.0, 4.4, 9.0, $gold, 8, [0, 0, -M_PI / 2 - 0.15]);
        $m->ball([-4.0, $h + 3.0, 0], [1.4, 1.4, 1.4], $gold, 5, 3);
        return $h + 10.0;
    }

    private static function palisade(Mesh $m, float $H, ?int $spec, int $lv): float
    {
        $wood = Mesh::rgb('#9a7040');
        $dark = Mesh::rgb('#6a4a26');
        $h = 15.0 * $H;
        for ($i = 0; $i < 9; $i++) {
            $a = -1.4 + $i * 0.35;
            $hh = $h - ($i % 2) * 2.4;
            $x = cos($a) * 8.5;
            $z = sin($a) * 8.5;
            $m->cyl([-$x + 4.0, 0, $z], 1.5, 1.4, $hh, $i % 2 ? $dark : $wood, 5);
            $m->cyl([-$x + 4.0, $hh, $z], 1.5, 0.0, 3.4, $wood, 5);
        }
        $m->box([-0.5, $h * 0.4, 0], [1.0, 1.2, 17.0], $dark, [0, 0.0, 0.0]);
        return $h + 3.0;
    }

    private static function slingers(Mesh $m, float $H, ?int $spec, int $lv): float
    {
        $m->ball([0, 1.0, 0], [11.4, 7.6 * $H, 11.4], Mesh::rgb('#5a9a3a'), 9, 4);
        $m->ball([-3.0, 4.6, 3.0], [5.0, 3.0, 5.0], Mesh::rgb('#3a6a26'), 6, 3);
        $m->ball([7.6, 3.2, 0], [1.6, 1.6, 1.6], Mesh::rgb('#3a7ac8', 1.2), 6, 3);
        $m->slab([8.6, 0, 0], [1.6, 5.0, 4.4], Mesh::rgb('#4a3018'));
        $m->ball([-6.0, 0.6, 7.0], [1.6, 1.0, 1.6], Mesh::rgb('#ffe8a0'), 5, 3);
        $m->ball([-3.0, 0.6, 9.0], [1.4, 1.0, 1.4], Mesh::rgb('#ffe8a0'), 5, 3);
        return 8.0 * $H + 4.0;
    }

    private static function traps(Mesh $m, float $H, ?int $spec, int $lv): float
    {
        $wood = Mesh::rgb('#9a7040');
        $h = 7.0 * $H;
        $m->slab([0, 0, 0], [12.0, $h, 11.0], $wood);
        foreach ([-1, 1] as $side) {
            $m->box([0, $h + 2.4, $side * 3.2], [14.0, 0.9, 6.4], Mesh::rgb('#6a8a3a'), [-$side * 0.7, 0, 0]);
        }
        $m->slab([6.2, 0, 0], [0.6, 4.6, 3.6], Mesh::rgb('#2a1a0a'));
        $m->cyl([4.0, 0, 8.0], 3.4, 3.4, 1.6, Mesh::rgb('#c8a870'), 8);
        $m->cyl([4.0, 1.6, 8.0], 1.8, 1.8, 0.4, Mesh::rgb('#8a6a3a'), 8);
        $m->slab([-5.0, 0, 7.4], [4.6, 0.8, 3.0], Mesh::rgb('#a0a0a8'));
        for ($i = 0; $i < 4; $i++) {
            $m->cyl([-6.6 + $i * 1.1, 0.8, 7.4], 0.35, 0.0, 1.6, Mesh::rgb('#d0d0d8'), 4);
        }
        return $h + 5.0;
    }

    private static function inn(Mesh $m, float $H, ?int $spec, int $lv): float
    {
        $h = 10.0 * $H;
        $m->slab([0, 0, 0], [16.0, $h, 14.0], Mesh::rgb('#f0e0c0'));
        $m->slab([0, $h * 0.5, 0], [16.4, 0.8, 14.4], Mesh::rgb('#6a4220'));
        foreach ([-1, 1] as $side) {
            $m->box([0, $h + 3.4, $side * 4.4], [18.0, 1.0, 9.4], Mesh::rgb('#b04a2a'), [-$side * 0.7, 0, 0]);
        }
        $m->slab([-4.0, $h + 4.0, 0], [3.0, 6.0, 3.0], Mesh::rgb('#5a4a40'));
        $m->slab([8.2, 0, -2.0], [0.6, 6.0, 3.4], Mesh::rgb('#6a4220'));
        $m->slab([8.2, 3.6, 3.8], [0.6, 2.6, 2.6], Mesh::rgb('#ffd060', 1.2));
        $m->slab([9.4, $h * 0.55, 6.8], [0.5, 3.0, 3.2], Mesh::rgb('#e8c040'));
        $m->slab([9.4, $h * 0.55 + 3.0, 6.8], [1.6, 0.4, 1.6], Mesh::rgb('#6a4220'));
        return $h + 10.0;
    }

    private static function garden(Mesh $m, float $H, ?int $spec, int $lv): float
    {
        $leaf = Mesh::rgb('#3a8a3a');
        $dark = Mesh::rgb('#245a24');
        $r = 6.0 + $lv * 1.0;
        $m->ball([-4.6, 3.6, 2.0], [5.4, 4.6, 5.4], $dark, 7, 4);
        $m->ball([5.0, 3.6, 3.0], [5.4, 5.0, 5.4], $leaf, 7, 4);
        $m->ball([0, 5.0 * $H, -2.0], [$r, 4.6 * $H + 2.0, $r], $leaf, 8, 4);
        foreach ([[-5.0, 8.0, 5.0], [5.0, 7.6, 7.0], [1.0, 11.0, 1.0], [-3.0, 10.0, -4.0]] as $i => [$x, $y, $z]) {
            $m->ball([$x, $y * $H, $z], [1.0, 1.0, 1.0], Mesh::rgb(['#f080a0', '#f0e060', '#ffffff', '#a0a0ff'][$i], 1.1), 4, 3);
        }
        foreach ([-1, 1] as $side) {
            $m->box([4.0 + 0.3, 5.6 * $H, $side * 1.5], [0.6, 0.8, 1.0], Mesh::rgb('#ffffff', 1.1));
        }
        return 11.0 * $H + 3.0;
    }

    private static function barracks(Mesh $m, float $H, ?int $spec, int $lv): float
    {
        $h = 13.0 * $H;
        $m->cyl([0, 0, 0], 11.0, 1.4, $h, Mesh::rgb('#7a2a1a'), 7);
        $m->cyl([0, 0, 0], 11.4, 11.4, 1.2, Mesh::rgb('#3a2414'), 7);
        $m->slab([9.0, 0, 0], [1.6, 7.0, 5.0], Mesh::rgb('#140808'), [0, 0, 0.0]);
        $m->ball([8.6, 10.0, 0], [1.2, 1.6, 1.6], Mesh::rgb('#e8e8e0'), 5, 3);
        foreach ([-1, 1] as $side) {
            $m->cyl([8.0, 0, $side * 6.4], 0.45, 0.45, 12.0, Mesh::rgb('#d8d0c0'), 4);
            $m->cyl([8.0, 12.0, $side * 6.4], 0.9, 0.0, 2.4, Mesh::rgb('#d0d0d8'), 4);
        }
        return $h + 3.0;
    }

    private static function catapult(Mesh $m, float $H, ?int $spec, int $lv): float
    {
        $wood = Mesh::rgb('#6a4a2a');
        $dark = Mesh::rgb('#3a2414');
        $m->slab([0, 1.4, 0], [16.0, 1.8, 9.0], $wood);
        foreach ([[-5.4, -1], [-5.4, 1], [5.4, -1], [5.4, 1]] as [$x, $side]) {
            $m->cyl([$x, 2.4, $side * 5.0], 2.6, 2.6, 1.0, $dark, 8, [M_PI / 2, 0, 0]);
        }
        foreach ([-1, 1] as $side) {
            $m->box([0, 7.0, $side * 3.4], [1.6, 11.0 * $H, 1.4], $wood, [0, 0, 0.0]);
        }
        $m->box([-3.0, 7.8 * $H + 2.0, 0], [14.0, 1.4, 1.8], $wood, [0, 0, -0.62]);
        $m->ball([-8.6, 7.8 * $H + 7.6, 0], [3.0, 2.4, 3.0], Mesh::rgb('#8a8a80'), 6, 3);
        return 12.0 * $H + 8.0;
    }

    private static function pitch(Mesh $m, float $H, ?int $spec, int $lv): float
    {
        $pot = Mesh::rgb('#2a2a30');
        $m->cyl([0, 2.0, 0], 6.4, 9.4, 8.4 * $H, $pot, 10);
        $m->cyl([0, 2.0 + 8.4 * $H, 0], 9.6, 9.6, 1.0, Mesh::rgb('#4a4a52'), 10);
        $m->cyl([0, 2.0 + 8.4 * $H + 1.0, 0], 8.0, 8.0, 0.3, Mesh::rgb('#140c14'), 10);
        $m->ball([-2.0, 2.0 + 8.4 * $H + 1.6, 1.0], [1.8, 1.0, 1.8], Mesh::rgb('#3a2a3a'), 5, 3);
        foreach ([0, 1, 2] as $k) {
            $a = $k * 2.09;
            $m->cyl([cos($a) * 4.2, 0, sin($a) * 4.2], 2.2, 0.0, 6.0, Mesh::rgb('#ff7a20', 1.3), 5);
            $m->cyl([cos($a) * 4.2, 0, sin($a) * 4.2], 1.1, 0.0, 4.0, Mesh::rgb('#ffd040', 1.3), 5);
        }
        foreach ([-1, 1] as $side) {
            $m->cyl([0, 1.0, $side * 7.4], 0.8, 0.8, 2.0, $pot, 4);
        }
        return 11.0 * $H + 4.0;
    }

    private static function drums(Mesh $m, float $H, ?int $spec, int $lv): float
    {
        $body = Mesh::rgb('#8a2a1a');
        $skin = Mesh::rgb('#e8d8b0');
        $gold = Mesh::rgb('#e8c040');
        $h = 8.0 * $H;
        $m->cyl([0, 1.0, 0], 7.6, 8.6, $h, $body, 10);
        $m->cyl([0, 1.0 + $h, 0], 8.4, 8.4, 0.6, $skin, 10);
        $m->cyl([0, 1.0 + $h * 0.5, 0], 8.1, 8.1, 0.8, $gold, 10);
        $m->cyl([-9.0, 0.0, 5.0], 3.4, 3.8, 4.4, $body, 8);
        $m->cyl([-9.0, 4.4, 5.0], 3.6, 3.6, 0.4, $skin, 8);
        $m->cyl([8.4, 0.0, 6.0], 3.0, 3.4, 4.0, $body, 8);
        $m->cyl([8.4, 4.0, 6.0], 3.2, 3.2, 0.4, $skin, 8);
        foreach ([-1, 1] as $side) {
            $m->cyl([2.0, 1.0 + $h + 0.6, $side * 2.4], 0.4, 0.4, 5.0, Mesh::rgb('#6a4a2a'), 4, [0, 0, -1.0]);
        }
        $m->slab([0, 1.0 + $h + 6.0, 0], [0.7, 3.2, 0.7], Mesh::rgb('#e8e4d0'));
        $m->ball([0, 1.0 + $h + 10.0, 0], [1.6, 1.8, 1.6], Mesh::rgb('#e8e8e0'), 6, 4);
        return $h + 14.0;
    }
}
