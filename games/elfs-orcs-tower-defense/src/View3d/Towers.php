<?php

namespace App\View3d;

/**
 * The towers as 3D models, painted like a storybook: standing on y = 0 (the platform's top) around x = z = 0, the
 * front (door, sign) towards +z (the camera), about 24 to 55 units tall, no wider than 11 from the middle. The level
 * (1..3) makes them taller and richer (a pennant of the side from level 2, gold and ivy at level 3); a
 * specialization (0 / 1) changes a part of the tower and adds a glowing crystal over it, gold or red.
 */
final class Towers
{
    /** Sprite names of the towers (towers.yaml `sprite`). */
    public const SPRITES = ['archer', 'sanctuary', 'sentinel', 'songmage', 'crossbow', 'slingshot', 'forge', 'mine', 'stables', 'javelin',
        'horn', 'palisade', 'slingers', 'traps', 'inn', 'garden', 'barracks', 'catapult', 'pitch', 'drums'];

    private const FRONT = M_PI / 2;

    public static function build(string $sprite, int $level, ?int $spec, string $flag = '#3fa860'): Mesh
    {
        $lv = max(1, min(3, $level));
        $m = new Mesh();
        $H = 1.0 + 0.15 * ($lv - 1) + ($spec !== null ? 0.08 : 0.0);
        $f = Mesh::rgb($flag);
        $top = match ($sprite) {
            'sanctuary' => self::sanctuary($m, $H, $spec, $lv, $f),
            'sentinel' => self::sentinel($m, $H, $spec, $lv, $f),
            'songmage' => self::songmage($m, $H, $spec, $lv, $f),
            'crossbow' => self::crossbow($m, $H, $spec, $lv, $f),
            'slingshot' => self::slingshot($m, $H, $spec, $lv, $f),
            'forge' => self::forge($m, $H, $spec, $lv, $f),
            'mine' => self::mine($m, $H, $spec, $lv, $f),
            'stables' => self::stables($m, $H, $spec, $lv, $f),
            'javelin' => self::javelin($m, $H, $spec, $lv, $f),
            'horn' => self::horn($m, $H, $spec, $lv, $f),
            'palisade' => self::palisade($m, $H, $spec, $lv, $f),
            'slingers' => self::slingers($m, $H, $spec, $lv, $f),
            'traps' => self::traps($m, $H, $spec, $lv, $f),
            'inn' => self::inn($m, $H, $spec, $lv, $f),
            'garden' => self::garden($m, $H, $spec, $lv, $f),
            'barracks' => self::barracks($m, $H, $spec, $lv, $f),
            'catapult' => self::catapult($m, $H, $spec, $lv, $f),
            'pitch' => self::pitch($m, $H, $spec, $lv, $f),
            'drums' => self::drums($m, $H, $spec, $lv, $f),
            default => self::archer($m, $H, $spec, $lv, $f),
        };
        if ($spec !== null) {
            // the crystal of the specialization: gold or red, glowing, floating over the tower
            $m->ball([0, $top + 3.4, 0], [1.6, 2.7, 1.6], Mesh::rgb($spec === 0 ? '#ffe27a' : '#ff6a50', 1.35), 4, 2, [0, 0.4, 0]);
        }
        return $m;
    }

    /** About how tall a tower is, for its bar, its shadow and its marks. */
    public static function height(int $level): float
    {
        return 26.0 + 5.0 * max(1, min(3, $level));
    }

    // ---- helpers ----------------------------------------------------------------------------------------------

    private static function c(string $hex, float $k = 1.0): array
    {
        return Mesh::rgb($hex, $k);
    }

    private static function gold(): array
    {
        return Mesh::rgb('#f2c94c', 1.05);
    }

    /** The warm light of a window. */
    private static function glow(): array
    {
        return [1.55, 1.12, 0.58];
    }

    /** A round body: a plinth, then a wall from $r0 to $r1, darker at its foot. @return float its top */
    private static function body(Mesh $m, float $r0, float $r1, float $h, array $col, float $y = 0.0, int $seg = 16): float
    {
        $low = Mesh::times($col, 0.8);
        $m->cyl([0, $y, 0], $r0 + 1.0, $r0 + 0.6, 1.8, Mesh::times($col, 0.68), $seg, [0, 0, 0], false, $low);
        $m->cyl([0, $y + 1.8, 0], $r0, $r1, $h - 1.8, $low, $seg, [0, 0, 0], true, $col);
        return $y + $h;
    }

    /** A ring around a round body. */
    private static function band(Mesh $m, float $y, float $r, float $h, array $col, int $seg = 16): void
    {
        $m->cyl([0, $y, 0], $r, $r, $h, $col, $seg, [0, 0, 0], true, Mesh::times($col, 1.08), true);
    }

    /** A pointed roof with a flared eave (two slopes) and a gold finial. @return float its top */
    private static function roof(Mesh $m, float $y, float $r, float $h, array $col, array $tip, int $seg = 16, bool $finial = true, float $x = 0.0, float $z = 0.0): float
    {
        $mid = Mesh::mix($col, $tip, 0.4);
        $m->cyl([$x, $y - 0.7, $z], $r * 0.82, $r, 0.7, Mesh::times($col, 0.55), $seg, [0, 0, 0], false);
        $m->cyl([$x, $y, $z], $r, $r * 0.5, $h * 0.32, Mesh::times($col, 0.92), $seg, [0, 0, 0], false, $mid);
        $m->cyl([$x, $y + $h * 0.32, $z], $r * 0.5, 0.0, $h * 0.68, $mid, $seg, [0, 0, 0], false, $tip);
        if (!$finial) {
            return $y + $h;
        }
        $m->cyl([$x, $y + $h - 0.8, $z], 0.32, 0.16, 3.6, self::gold(), 6);
        $m->ball([$x, $y + $h + 2.9, $z], [0.7, 0.7, 0.7], self::gold(), 6, 4);
        return $y + $h + 3.6;
    }

    /** Windows in a round wall of radius $r at height $y: angle in hundredths of a radian (157: the front) => lit. */
    private static function windows(Mesh $m, float $y, float $r, array $angles, array $frame, float $w = 1.3, float $h = 2.4): void
    {
        foreach ($angles as $a => $lit) {
            $a = (float)$a / 100;
            $m->box([cos($a) * $r, $y - 0.1, sin($a) * $r], [0.8, $h + 0.7, $w + 0.7], $frame, [0, -$a, 0]);
            $m->box([cos($a) * ($r + 0.25), $y, sin($a) * ($r + 0.25)], [0.6, $h, $w], $lit ? self::glow() : self::c('#2a2230'), [0, -$a, 0]);
            $m->ball([cos($a) * ($r + 0.25), $y + $h / 2, sin($a) * ($r + 0.25)], [0.3, $w / 2, $w / 2], $lit ? self::glow() : self::c('#2a2230'), 6, 3, [0, -$a, 0]);
        }
    }

    /** An arched door in a wall at radius $r, facing $a. */
    private static function door(Mesh $m, float $r, float $w, float $h, array $wood, float $a = self::FRONT, float $x0 = 0.0, float $z0 = 0.0): void
    {
        [$c, $s] = [cos($a), sin($a)];
        $m->box([$x0 + $c * $r, $h / 2 - 0.2, $z0 + $s * $r], [1.2, $h, $w + 1.0], self::c('#8f8572'), [0, -$a, 0]);
        $m->ball([$x0 + $c * $r, $h - 0.2, $z0 + $s * $r], [0.6, ($w + 1.0) / 2, ($w + 1.0) / 2], self::c('#8f8572'), 8, 4, [0, -$a, 0]);
        $m->box([$x0 + $c * ($r + 0.35), $h / 2 - 0.3, $z0 + $s * ($r + 0.35)], [0.8, $h - 0.2, $w], $wood, [0, -$a, 0], Mesh::times($wood, 1.15));
        $m->ball([$x0 + $c * ($r + 0.35), $h - 0.4, $z0 + $s * ($r + 0.35)], [0.42, $w / 2, $w / 2], $wood, 8, 4, [0, -$a, 0]);
        $m->ball([$x0 + $c * ($r + 0.85), $h * 0.45, $z0 + $s * ($r + 0.85) + 0.0], [0.3, 0.3, 0.3], self::gold(), 4, 2);
    }

    /** Battlements around a circle. */
    private static function crenels(Mesh $m, float $y, float $r, int $n, array $col, float $w = 2.0, float $h = 2.0): void
    {
        for ($i = 0; $i < $n; $i++) {
            $a = ($i + 0.5) * 2 * M_PI / $n;
            $m->slab([cos($a) * $r, $y, sin($a) * $r], [$w * 0.8, $h, $w], $col, [0, -$a, 0], Mesh::times($col, 1.06));
        }
    }

    /** Ivy climbing a wall of radius $r up to $h, around the angle $a (it sways a little). */
    private static function ivy(Mesh $m, float $r, float $h, float $a, int $seed): void
    {
        $m->material(Mesh::LEAVES * 0.25);
        for ($k = 0; $k < 5; $k++) {
            $aa = $a + sin($k * 1.7 + $seed) * 0.3;
            $y = 1.4 + $k * $h / 5.5;
            $m->ball([cos($aa) * ($r + 0.2), $y, sin($aa) * ($r + 0.2)], [1.0, 1.9 - $k * 0.15, 1.6], self::c('#2f6a2c'), 5, 3, [0, -$aa, 0], self::c('#8cc256'), 0.2, $seed + $k);
        }
        $m->material(Mesh::SOLID);
    }

    /** A pole with a swallow-tailed pennant of the side, at the foot point given. */
    private static function pennant(Mesh $m, float $x, float $y, float $z, array $col, float $pole = 7.5, float $len = 6.0): void
    {
        $m->cyl([$x, $y, $z], 0.32, 0.22, $pole, self::c('#5a4630'), 5);
        $m->ball([$x, $y + $pole + 0.2, $z], [0.5, 0.5, 0.5], self::gold(), 5, 3);
        $t = $y + $pole - 0.4;
        $light = Mesh::times($col, 1.15);
        $m->quad([[$x, $t, $z], [$x + $len * 0.55, $t - 0.3, $z + 0.5], [$x + $len * 0.55, $t - 2.6, $z + 0.5], [$x, $t - 2.8, $z]], [0, 0, 1], [$col, $light, $light, $col]);
        $m->tri([$x + $len * 0.55, $t - 0.3, $z + 0.5], [$x + $len, $t - 0.6, $z], [$x + $len * 0.55, $t - 1.45, $z + 0.5], [0, 0, 1], $light, $col, $light);
        $m->tri([$x + $len * 0.55, $t - 1.45, $z + 0.5], [$x + $len, $t - 2.7, $z], [$x + $len * 0.55, $t - 2.6, $z + 0.5], [0, 0, 1], $light, $col, $light);
    }

    /** A rod from $a to $b (a cylinder turned along it). */
    private static function rod(Mesh $m, array $a, array $b, float $r0, float $r1, array $col, int $seg = 6, ?array $top = null): void
    {
        $d = [$b[0] - $a[0], $b[1] - $a[1], $b[2] - $a[2]];
        $len = sqrt($d[0] ** 2 + $d[1] ** 2 + $d[2] ** 2) ?: 0.001;
        $m->cyl($a, $r0, $r1, $len, $col, $seg, [acos(max(-1.0, min(1.0, $d[1] / $len))), atan2($d[0], $d[2]), 0], true, $top);
    }

    /** A gable roof along x (its ridge), $w wide (z), $l long (x), its eaves at $y. */
    private static function gable(Mesh $m, float $x, float $y, float $z, float $l, float $w, float $pitch, array $col, array $top, float $thick = 0.9): void
    {
        $half = $w / 2 / cos($pitch);
        foreach ([-1, 1] as $side) {
            // turned about x so that each half falls away from the ridge
            $m->box([$x, $y + sin($pitch) * $half / 2, $z + $side * $w / 4], [$l, $thick, $half + 0.6], $col, [$side * $pitch, 0, 0], $top);
        }
    }

    /** Puffs of smoke over a chimney (they waver). */
    private static function smoke(Mesh $m, float $x, float $y, float $z): void
    {
        $m->material(Mesh::GRASS * 0.12);
        foreach ([[0.0, 1.4], [0.8, 1.9], [1.9, 2.3]] as $i => [$dx, $r]) {
            $m->ball([$x + $dx, $y + 1.6 + $i * 2.4, $z - $dx * 0.3], [$r, $r * 0.85, $r], self::c('#c8c4c0', 1.0 + $i * 0.06), 6, 4, [0, $i, 0], self::c('#f4f2f0'), 0.12, $i);
        }
        $m->material(Mesh::SOLID);
    }

    // ---- the elves: white stone, green roofs, gold, crystals ---------------------------------------------------

    private static function archer(Mesh $m, float $H, ?int $spec, int $lv, array $flag): float
    {
        $stone = self::c('#efe7d3');
        $low = self::c('#c9bda2');
        $roof = self::c($spec === 0 ? '#3a74c0' : '#3a9a6a');
        $tip = self::c($spec === 0 ? '#a0ccf4' : '#b0e4a0');
        $trim = $lv >= 3 ? self::gold() : self::c('#c2b69c');
        $h = 18.0 * $H;
        self::body($m, 6.6, 5.5, $h, $stone);
        self::band($m, $h * 0.44, 6.2, 0.8, $trim);
        // the balcony on its corbels, with battlements
        $m->cyl([0, $h - 2.2, 0], 5.5, 8.0, 2.2, $low, 16, [0, 0, 0], false, $stone);
        $m->cyl([0, $h, 0], 8.0, 8.0, 1.2, $stone, 16, [0, 0, 0], true, Mesh::times($stone, 1.04));
        self::crenels($m, $h + 1.2, 7.4, 10, $stone, 2.0, 1.9);
        $m->cyl([0, $h + 1.2, 0], 4.4, 4.2, 4.2, $stone, 16, [0, 0, 0], false, Mesh::times($stone, 1.03));
        $top = self::roof($m, $h + 5.2, 7.2, 12.5 + $lv, $roof, $tip);
        self::windows($m, $h * 0.68, 5.85, [157 => true, 285 => false], $low);
        self::windows($m, $h + 3.2, 4.3, [100 => false, 200 => true], $low, 1.0, 1.8);
        self::door($m, 6.5, 2.8, 4.6, self::c('#7a5232'));
        if ($lv >= 3) {
            self::ivy($m, 6.2, $h * 0.55, M_PI * 0.15, 3);
        }
        if ($spec === 1) {
            // two turrets on its sides
            foreach ([-1, 1] as $side) {
                $m->cyl([$side * 7.6, 0, -1.2], 2.8, 2.4, $h * 0.6, $low, 10, [0, 0, 0], true, $stone);
                self::crenels($m, $h * 0.6, 2.4, 5, $stone, 1.1, 1.0);
                self::roof($m, $h * 0.6 + 1.0, 3.4, 5.5, $roof, $tip, 10, false, $side * 7.6, -1.2);
            }
        }
        if ($lv >= 2) {
            self::pennant($m, 0.0, $top - 0.6, 0.0, $flag, 3.0, 5.0);
            $top += 3.0;
        }
        return $top;
    }

    private static function sanctuary(Mesh $m, float $H, ?int $spec, int $lv, array $flag): float
    {
        $white = self::c('#f3efe6');
        $shade = self::c('#c4c6cc');
        $glow = self::c($spec === 0 ? '#a8ecff' : ($spec === 1 ? '#ffe07a' : '#b8ccff'), 1.4);
        $h = 11.0 * $H;
        $m->cyl([0, 0, 0], 10.4, 10.0, 1.0, $shade, 20, [0, 0, 0], true, $white);
        $m->cyl([0, 1.0, 0], 9.4, 9.1, 0.9, $white, 20, [0, 0, 0], true, Mesh::times($white, 1.03));
        for ($i = 0; $i < 8; $i++) {
            $a = $i * M_PI / 4 + M_PI / 8;
            [$x, $z] = [cos($a) * 7.4, sin($a) * 7.4];
            $m->cyl([$x, 1.9, $z], 1.1, 1.0, 0.7, $shade, 8);
            $m->cyl([$x, 2.6, $z], 0.72, 0.62, $h - 1.3, $white, 8, [0, 0, 0], false);
            $m->cyl([$x, $h + 1.3, $z], 0.8, 1.2, 0.8, $shade, 8);
        }
        $m->cyl([0, $h + 2.1, 0], 8.8, 8.8, 1.5, $white, 20, [0, 0, 0], true, Mesh::times($white, 1.04), true);
        $m->cyl([0, $h + 3.6, 0], 8.0, 7.6, 0.8, $spec === 1 || $lv >= 3 ? self::gold() : $shade, 20);
        $domeH = 5.4 + $lv * 0.7;
        $m->ball([0, $h + 4.2, 0], [7.4, $domeH, 7.4], Mesh::times($white, 0.92), 16, 8, [0, 0, 0], Mesh::times($white, 1.05));
        if ($spec === 1) {
            for ($i = 0; $i < 6; $i++) {
                $a = $i * M_PI / 3;
                self::rod($m, [cos($a) * 7.0, $h + 4.6, sin($a) * 7.0], [cos($a) * 1.4, $h + 4.2 + $domeH * 0.97, sin($a) * 1.4], 0.25, 0.2, self::gold(), 4);
            }
        }
        // the light inside, on its pedestal
        $m->cyl([0, 1.9, 0], 1.9, 1.5, 2.2, $shade, 10);
        $m->ball([0, 6.0, 0], [1.8, 2.8, 1.8], $glow, 4, 2, [0, 0.4, 0]);
        // the moon on the dome
        $top = $h + 4.2 + $domeH;
        $m->cyl([0, $top - 0.4, 0], 0.4, 0.3, 2.4, self::gold(), 6);
        $m->ball([0, $top + 3.2, 0], [1.6, 1.6, 0.7], self::c('#f6f0c4', 1.25), 8, 5);
        $m->ball([0.7, $top + 3.5, 0.2], [1.3, 1.3, 0.8], Mesh::times($white, 0.95), 8, 5);
        if ($spec === 0) {
            foreach ([[-6.5, 4.0], [5.8, 5.2], [0.0, -7.6]] as [$x, $z]) {
                $m->ball([$x, 3.4, $z], [0.9, 2.2, 0.9], self::c('#c8f4ff', 1.2), 4, 2, [0.2, 0, 0.2]);
            }
        }
        if ($lv >= 2) {
            self::pennant($m, 8.6, 1.9, 3.0, $flag, 6.5 + $lv, 4.4);
        }
        return $top + 4.0;
    }

    private static function sentinel(Mesh $m, float $H, ?int $spec, int $lv, array $flag): float
    {
        $bark = self::c($spec === 1 ? '#5e3e24' : '#7a5434');
        $barkTop = Mesh::times($bark, 1.25);
        $leafLow = self::c($spec === 0 ? '#4e2660' : ($spec === 1 ? '#24502a' : '#2f6a30'));
        $leafTop = self::c($spec === 0 ? '#c890e0' : ($spec === 1 ? '#86b24e' : '#a8d85c'));
        $wood = self::c('#9a6c40');
        $h = 14.0 * $H;
        $thick = $spec === 1 ? 1.35 : 1.0;
        // roots and a twisted trunk
        for ($i = 0; $i < 5; $i++) {
            $a = $i * 1.257 + 0.3;
            self::rod($m, [cos($a) * 6.0, 0.0, sin($a) * 6.0], [cos($a) * 1.2, 4.0, sin($a) * 1.2], 0.9 * $thick, 1.6 * $thick, $bark, 6, $barkTop);
        }
        $m->cyl([0, 0, 0], 3.6 * $thick, 2.4 * $thick, $h, $bark, 10, [0, 0, 0], true, $barkTop);
        self::rod($m, [0.6, $h * 0.5, 0.4], [-0.8, $h + 2.0, -0.4], 1.6 * $thick, 1.0, $bark, 8, $barkTop);
        // its eyes: it is a living tree
        foreach ([-1, 1] as $side) {
            $m->ball([$side * 1.1, $h * 0.42, 3.3 * $thick], [0.45, 0.6, 0.3], self::c('#ffe68a', 1.4), 5, 3);
        }
        // a wooden lookout in its branches, two lanterns
        $deck = $h * 0.72;
        $m->cyl([0, $deck, 0], 6.4, 6.4, 0.8, $wood, 14, [0, 0, 0], true, Mesh::times($wood, 1.15), true);
        for ($i = 0; $i < 7; $i++) {
            $a = $i * 2 * M_PI / 7;
            $m->cyl([cos($a) * 6.0, $deck + 0.8, sin($a) * 6.0], 0.25, 0.25, 2.0, Mesh::times($wood, 0.8), 4);
        }
        $m->cyl([0, $deck + 2.6, 0], 6.1, 6.1, 0.35, Mesh::times($wood, 0.85), 14, [0, 0, 0], false);
        foreach ([[4.8, 2.6], [-4.4, 3.4]] as [$x, $z]) {
            $m->cyl([$x, $deck - 2.4, $z], 0.08, 0.08, 2.4, self::c('#3a2a1a'), 3);
            $m->ball([$x, $deck - 2.8, $z], [0.7, 0.9, 0.7], self::glow(), 6, 4);
        }
        // the crown of leaves
        $m->material(Mesh::LEAVES);
        $r = 7.8 + $lv * 1.2 + ($spec === 1 ? 1.5 : 0.0);
        $cy = $h + $r * 0.45;
        $m->ball([0, $cy, 0], [$r, $r * 0.78, $r], $leafLow, 10, 7, [0, 0.4, 0], $leafTop, 0.1, 2);
        foreach ([[0.8, 0.0, -0.2], [2.9, 0.25, 0.1], [5.0, -0.1, 0.25]] as $i => [$a, $dy, $dz]) {
            $s = $r * 0.58;
            $m->ball([cos($a) * $r * 0.7, $cy + $dy * $r, sin($a) * $r * 0.7 + $dz], [$s, $s * 0.8, $s], $leafLow, 8, 5, [0, $a, 0], $leafTop, 0.12, 5 + $i);
        }
        $m->ball([0.4, $cy + $r * 0.6, -0.4], [$r * 0.5, $r * 0.42, $r * 0.5], Mesh::mix($leafLow, $leafTop, 0.4), 8, 5, [0, 0, 0], $leafTop, 0.1, 9);
        $m->material(Mesh::SOLID);
        if ($spec === 0) {
            foreach ([[-0.8, 0.1], [0.6, 0.4], [2.2, -0.2], [3.6, 0.3], [5.1, 0.0]] as [$a, $dy]) {
                $p = [cos($a) * $r * 0.95, $cy + $dy * $r, sin($a) * $r * 0.95];
                self::rod($m, $p, [$p[0] * 1.22, $p[1] + 1.2, $p[2] * 1.22], 0.55, 0.0, self::c('#e8d8f0'), 4);
            }
        }
        if ($lv >= 2) {
            self::pennant($m, 6.0, $deck + 0.8, 0.0, $flag, 5.0, 4.4);
        }
        return $cy + $r * 0.95;
    }

    private static function songmage(Mesh $m, float $H, ?int $spec, int $lv, array $flag): float
    {
        $stone = self::c('#eee4f2');
        $low = self::c('#b9a8c8');
        $roof = self::c($spec === 1 ? '#4a2858' : ($spec === 0 ? '#3a5ab0' : '#7656be'));
        $tip = self::c($spec === 1 ? '#a05a9a' : ($spec === 0 ? '#a8c8ff' : '#cdb4f4'));
        $crystal = self::c($spec === 0 ? '#c8f0ff' : ($spec === 1 ? '#ff5a7a' : '#a8f0ff'), 1.4);
        $h = 16.0 * $H;
        self::body($m, 5.8, 4.8, $h, $stone);
        self::band($m, $h * 0.36, 5.5, 0.7, $lv >= 3 ? self::gold() : $low);
        self::band($m, $h - 0.6, 5.2, 0.8, $low);
        self::windows($m, $h * 0.62, 5.15, [157 => true, 30 => false], $low, 1.1, 2.2);
        self::door($m, 5.7, 2.6, 4.2, self::c('#6a4a7a'));
        // the open belfry with its golden bell
        $m->cyl([0, $h + 0.2, 0], 6.2, 6.2, 0.8, $stone, 16, [0, 0, 0], true, Mesh::times($stone, 1.03), true);
        for ($i = 0; $i < 4; $i++) {
            $a = $i * M_PI / 2 + M_PI / 4;
            $m->cyl([cos($a) * 4.8, $h + 1.0, sin($a) * 4.8], 0.6, 0.5, 5.2, $stone, 8);
        }
        $m->cyl([0, $h + 2.4, 0], 2.1, 0.9, 2.8, self::gold(), 12, [0, 0, 0], true, Mesh::times(self::gold(), 1.15));
        $m->ball([0, $h + 5.4, 0], [1.0, 0.7, 1.0], self::gold(), 6, 4);
        $m->cyl([0, $h + 6.2, 0], 6.0, 6.0, 0.9, $low, 16, [0, 0, 0], true, $stone, true);
        // an onion dome and its spire
        $m->ball([0, $h + 9.6, 0], [5.4, 4.6, 5.4], Mesh::times($roof, 0.9), 16, 8, [0, 0, 0], $tip);
        $m->cyl([0, $h + 13.4, 0], 1.6, 0.0, 6.4, Mesh::mix($roof, $tip, 0.5), 10, [0, 0, 0], false, $tip);
        $top = $h + 19.6;
        // the singing crystal and the notes around it
        $m->ball([0, $top + 2.0, 0], [1.3, 2.3, 1.3], $crystal, 4, 2, [0, 0.4, 0]);
        for ($i = 0; $i < $lv + 1; $i++) {
            $a = $i * 2.4 + 0.5;
            $m->ball([cos($a) * 8.4, $h * 0.55 + $i * 3.2, sin($a) * 8.4], [0.8, 0.8, 0.8], self::c('#ffe27a', 1.35), 6, 4);
            $m->box([cos($a) * 8.4 + 0.65, $h * 0.55 + $i * 3.2 + 1.3, sin($a) * 8.4], [0.25, 2.6, 0.25], self::c('#ffe27a', 1.35));
        }
        if ($spec === 0) {
            foreach ([-1, 1] as $side) {
                self::rod($m, [$side * 5.0, $h + 6.8, 0], [$side * 6.4, $h + 13.0, 0], 0.25, 0.1, self::c('#c8d8f0'), 4);
            }
        }
        if ($lv >= 2) {
            self::pennant($m, 0.0, $top + 4.0, 0.0, $flag, 3.0, 4.4);
        }
        return $top + 4.5;
    }

    // ---- the dwarves: grey stone, slate, iron, blue runes ------------------------------------------------------

    private static function crossbow(Mesh $m, float $H, ?int $spec, int $lv, array $flag): float
    {
        $stone = self::c('#a8a398');
        $low = self::c('#77736b');
        $wood = self::c('#8a5a34');
        $iron = self::c('#6a6e78');
        $rune = self::c('#7ad4ff', 1.35);
        $h = 14.0 * $H;
        $m->slab([0, 0, 0], [16.6, 2.0, 16.6], Mesh::times($low, 0.85), [0, 0, 0], $low);
        $m->slab([0, 2.0, 0], [14.6, $h - 2.0, 14.6], $low, [0, 0, 0], $stone);
        foreach ([[-1, -1], [-1, 1], [1, -1], [1, 1]] as [$x, $z]) {
            $m->slab([$x * 7.0, 0, $z * 7.0], [3.4, $h + 0.6, 3.4], Mesh::times($low, 0.9), [0, 0, 0], $stone);
        }
        $m->slab([0, $h * 0.5, 0], [15.2, 0.7, 15.2], $lv >= 3 ? self::gold() : $low);
        // the door and its runes, towards the camera
        $m->slab([0, 0, 7.35], [4.4, 6.0, 0.6], self::c('#2a2420'));
        $m->slab([0, 0, 7.6], [3.6, 5.4, 0.4], $wood, [0, 0, 0], Mesh::times($wood, 1.2));
        foreach ([-2.0, 0.0, 2.0] as $i => $x) {
            $m->slab([$x * 1.6, $h * 0.62, 7.4], [0.7, 1.8 - abs($x) * 0.2, 0.3], $rune);
        }
        // the roof: a walk with battlements, the crossbow on its turntable
        $m->slab([0, $h, 0], [17.0, 1.2, 17.0], $stone, [0, 0, 0], Mesh::times($stone, 1.05));
        foreach ([-6.4, -2.1, 2.1, 6.4] as $p) {
            foreach ([[$p, 7.6], [$p, -7.6], [7.6, $p], [-7.6, $p]] as [$x, $z]) {
                $m->slab([$x, $h + 1.2, $z], [2.0, 2.0, 2.0], $stone, [0, 0, 0], Mesh::times($stone, 1.06));
            }
        }
        $y = $h + 1.2;
        $m->cyl([0, $y, 0], 3.6, 3.4, 1.2, $iron, 14, [0, 0, 0], true, Mesh::times($iron, 1.2));
        $m->slab([0, $y + 1.2, 0], [2.4, 2.0, 2.4], $wood);
        $big = $spec === 1 ? 1.3 : 1.0;
        $m->box([0.6, $y + 3.8, 0], [10.0 * $big, 1.3, 1.6], $wood, [0, 0, 0.12], Mesh::times($wood, 1.2));
        foreach ($spec === 0 ? [3.4, 5.2] : [4.2] as $k => $by) {
            foreach ([-1, 1] as $side) {
                $m->box([4.4 * $big, $y + $by, $side * 2.9 * $big], [0.7, 0.7, 6.0 * $big], self::c('#c8ccd4'), [0, $side * 0.45, 0.12]);
            }
            $m->box([2.6 * $big, $y + $by, 0], [0.2, 0.2, 9.6 * $big], self::c('#e8e0c8'), [0, 0, 0.12]);
        }
        self::rod($m, [-3.6, $y + 4.6, 0], [6.4 * $big, $y + 5.8, 0], 0.32 * $big, 0.32 * $big, self::c('#a07a4a'), 5);
        self::rod($m, [6.4 * $big, $y + 5.8, 0], [8.0 * $big, $y + 6.0, 0], 0.7 * $big, 0.0, self::c('#d8dce4'), 5);
        if ($spec === 1) {
            foreach ([-1, 1] as $side) {
                $m->slab([$side * 7.6, 2.0, 0], [0.5, $h * 0.7, 6.0], $iron, [0, 0, 0], Mesh::times($iron, 1.2));
            }
        }
        if ($lv >= 2) {
            self::pennant($m, -7.0, $h + 1.2, -7.0, $flag, 7.0 + $lv, 5.0);
        }
        return $y + 7.5;
    }

    private static function slingshot(Mesh $m, float $H, ?int $spec, int $lv, array $flag): float
    {
        $stone = self::c('#a8a398');
        $wood = self::c('#94643a');
        $iron = self::c('#6a6e78');
        $h = 15.0 * $H;
        $m->cyl([0, 0, 0], 9.6, 9.2, 2.2, Mesh::times($stone, 0.75), 18, [0, 0, 0], true, $stone);
        for ($i = 0; $i < 8; $i++) {
            $a = $i * M_PI / 4;
            $m->ball([cos($a) * 8.6, 2.2, sin($a) * 8.6], [0.5, 0.35, 0.5], $iron, 5, 3);
        }
        // the fork: two thick posts bound with iron, and the strap
        foreach ([-1, 1] as $side) {
            self::rod($m, [-1.0, 2.0, $side * 1.6], [-0.4, $h * 0.55, $side * 2.4], 1.3, 1.2, $wood, 8, Mesh::times($wood, 1.15));
            self::rod($m, [-0.4, $h * 0.55, $side * 2.4], [0.2, $h, $side * 5.0], 1.2, 0.9, $wood, 8, Mesh::times($wood, 1.2));
            $m->cyl([-0.4, $h * 0.5, $side * 2.4], 1.45, 1.45, 0.8, $iron, 8);
            $m->cyl([0.15, $h - 1.2, $side * 4.8], 1.1, 1.1, 0.7, $iron, 8);
        }
        $m->box([-3.4, $h - 1.0, 0], [6.4, 0.9, 1.0], self::c('#5a3a22'), [0, 0.0, -0.4]);
        $m->ball([-6.0, $h - 2.4, 0], [1.6, 1.0, 2.2], self::c('#6a4428'), 6, 4);
        if ($spec === 1) {
            // a keg of fire in the strap
            $m->cyl([-6.0, $h - 3.6, 0], 2.0, 2.0, 3.4, self::c('#8a5a30'), 12, [0, 0, 0], true, self::c('#a87040'), true);
            foreach ([0.6, 2.6] as $y) {
                $m->cyl([-6.0, $h - 3.6 + $y, 0], 2.1, 2.1, 0.35, $iron, 12);
            }
            $m->ball([-6.0, $h + 0.4, 0], [0.5, 0.9, 0.5], self::c('#ffb040', 1.5), 5, 3);
        } else {
            $s = $spec === 0 ? 1.4 : 1.0;
            $m->ball([-6.0, $h - 1.6, 0], [2.4 * $s, 2.1 * $s, 2.4 * $s], self::c('#8e8a80'), 8, 5, [0, 0, 0], self::c('#b8b4aa'), 0.12, 4);
        }
        // a pile of stones ready, and a counterweight
        foreach ([[5.0, 5.0], [6.4, 3.0], [4.2, 6.6]] as $i => [$x, $z]) {
            $m->ball([$x, 3.0, $z], [1.3, 1.0, 1.3], self::c('#8e8a80'), 6, 4, [0, $i, 0], self::c('#b8b4aa'), 0.15, $i);
        }
        $m->slab([5.6, 2.2, -4.6], [3.6, 3.0, 3.2], self::c('#7a5030'), [0, 0.3, 0], self::c('#9a6a40'));
        if ($lv >= 2) {
            self::pennant($m, 7.4, 2.2, -2.0, $flag, 7.0 + $lv, 4.6);
        }
        return $h + 2.0;
    }

    private static function forge(Mesh $m, float $H, ?int $spec, int $lv, array $flag): float
    {
        $stone = self::c('#a29e94');
        $low = self::c('#706c66');
        $slate = self::c('#4a5a78');
        $slateTop = self::c('#7488aa');
        $rune = self::c($spec === 0 ? '#ff6a40' : '#7ad4ff', 1.4);
        $fire = self::c('#ffa040', 1.5);
        $h = 11.0 * $H;
        $m->slab([0, 0, -1.0], [17.0, 1.6, 13.6], Mesh::times($low, 0.85), [0, 0, 0], $low);
        $m->slab([0, 1.6, -1.0], [15.6, $h - 1.6, 12.4], $low, [0, 0, 0], $stone);
        self::gable($m, 0.0, $h, -1.0, 17.4, 14.2, 0.55, $slate, $slateTop);
        // the chimney, its smoke
        $m->slab([-5.0, 0, -4.6], [3.6, $h + 9.5, 3.6], $low, [0, 0, 0], $stone);
        $m->slab([-5.0, $h + 9.5, -4.6], [4.4, 1.0, 4.4], $low);
        self::smoke($m, -5.0, $h + 10.5, -4.6);
        // the mouth of the furnace towards the camera, and the anvil
        $m->slab([1.0, 1.6, 5.3], [6.0, 5.2, 0.6], self::c('#2a2220'));
        $m->ball([1.0, 6.8, 5.3], [3.0, 1.6, 0.4], self::c('#2a2220'), 10, 4);
        $m->slab([1.0, 1.6, 5.5], [4.6, 3.4, 0.5], $fire);
        $m->ball([1.0, 5.0, 5.6], [2.2, 1.0, 0.3], $fire, 8, 3);
        $anvil = self::c('#4a4e56');
        $m->slab([5.0, 0, 8.2], [1.6, 2.4, 1.6], self::c('#6a4a2a'));
        $m->slab([5.0, 2.4, 8.2], [3.8, 1.3, 1.8], $anvil, [0, 0, 0], self::c('#8a8e96'));
        self::rod($m, [6.8, 3.0, 8.2], [8.6, 3.2, 8.2], 0.6, 0.0, $anvil, 5);
        foreach ([-4.0, 0.0, 4.0] as $x) {
            $m->slab([$x, $h * 0.55, 5.25], [0.9, 1.6, 0.3], $rune);
        }
        if ($spec === 1 || $lv >= 3) {
            foreach ([[5.6, 5.6, 9.4], [4.0, 6.4, 8.0], [6.4, 7.0, 7.4]] as [$x, $y, $z]) {
                $m->ball([$x, $y, $z], [0.35, 0.35, 0.35], self::c('#fff0a0', 1.5), 4, 2);
            }
        }
        if ($lv >= 2) {
            self::pennant($m, 7.4, $h - 1.0, -6.0, $flag, 7.5, 4.6);
        }
        return $h + 8.0;
    }

    private static function mine(Mesh $m, float $H, ?int $spec, int $lv, array $flag): float
    {
        $rock = self::c('#8a7c6a');
        $moss = self::c('#7a9a4a');
        $wood = self::c('#8a5a34');
        $gold = self::c('#ffd24a', 1.35);
        $hill = 7.6 * $H;
        $m->ball([-1.0, 0.5, -1.4], [10.4, $hill, 9.4], Mesh::times($rock, 0.8), 14, 7, [0, 0.3, 0], $moss, 0.1, 3);
        $m->ball([5.0, 0.2, -5.0], [4.4, 3.4, 4.0], $rock, 8, 4, [0, 1, 0], Mesh::mix($rock, $moss, 0.5), 0.15, 5);
        // the timbered entrance towards the camera, its rails, a cart of gold
        $m->ball([0.0, 1.5, 6.8], [3.6, 4.6, 1.6], self::c('#1e1814'), 10, 5);
        foreach ([-1, 1] as $side) {
            $m->slab([$side * 3.2, 0, 7.4], [1.2, 6.4, 1.2], $wood, [0, 0, 0], Mesh::times($wood, 1.2));
        }
        $m->slab([0, 6.4, 7.4], [8.0, 1.3, 1.5], $wood, [0, 0, 0], Mesh::times($wood, 1.2));
        foreach ([-1, 1] as $side) {
            $m->slab([$side * 1.4, 0, 10.0], [0.35, 0.35, 6.0], self::c('#8a8e96'));
        }
        foreach ([8.0, 10.0, 12.0] as $z) {
            $m->slab([0, 0, $z], [3.8, 0.25, 0.8], Mesh::times($wood, 0.8));
        }
        $m->slab([0, 1.0, 10.6], [3.6, 2.2, 3.0], self::c('#6a4a2a'), [0, 0, 0], self::c('#8a6a3a'));
        foreach ([[-1.9, 9.6], [1.9, 9.6], [-1.9, 11.6], [1.9, 11.6]] as [$x, $z]) {
            $m->cyl([$x, 1.0, $z], 0.8, 0.8, 0.4, self::c('#3a3a40'), 8, [0, 0, M_PI / 2]);
        }
        foreach ([[-0.8, 10.2], [0.6, 11.0], [0.2, 10.0], [-0.4, 11.2]] as $i => [$x, $z]) {
            $m->ball([$x, 3.4, $z], [0.9, 0.7, 0.9], $gold, 5, 3, [0, $i, 0]);
        }
        // a lantern, a winch on the hill
        $m->cyl([4.6, 0, 7.6], 0.2, 0.2, 5.0, self::c('#3a2a1a'), 4);
        $m->ball([4.6, 5.3, 7.6], [0.7, 0.9, 0.7], self::glow(), 6, 4);
        $winchY = $hill * 0.82;
        foreach ([-1, 1] as $side) {
            self::rod($m, [-3.6, $winchY, $side * 2.4], [-2.6, $winchY + 6.0, 0], 0.4, 0.3, $wood, 5);
        }
        $m->cyl([-2.6, $winchY + 5.6, -1.2], 0.6, 0.6, 2.4, $wood, 8, [M_PI / 2, 0, 0]);
        $m->cyl([-1.9, $winchY + 1.6, 0], 0.06, 0.06, 4.0, self::c('#c8b890'), 3);
        if ($spec === 0 || $lv >= 3) {
            foreach ([[-6.0, 3.6, 3.0], [-3.0, 5.8, 4.6], [3.6, 4.8, 3.2], [-7.4, 2.4, -2.4]] as [$x, $y, $z]) {
                $m->ball([$x, $y, $z], [0.9, 0.5, 0.6], $gold, 4, 2, [0.4, $x, 0]);
            }
        }
        if ($spec === 1) {
            foreach ([[6.6, 4.6], [7.8, 2.4], [7.0, 0.2]] as [$x, $z]) {
                $m->cyl([$x, 0, $z], 1.1, 1.1, 2.6, self::c('#8a3a2a'), 10, [0, 0, 0], true, self::c('#a84a32'));
                $m->cyl([$x, 1.1, $z], 1.15, 1.15, 0.35, self::c('#2a2a2a'), 10);
            }
        }
        if ($lv >= 2) {
            self::pennant($m, 2.0, $hill * 0.8, -2.0, $flag, 7.0, 4.6);
        }
        return $hill + 7.0;
    }

    // ---- the horsefolk: warm wood, golden thatch, banners ------------------------------------------------------

    private static function stables(Mesh $m, float $H, ?int $spec, int $lv, array $flag): float
    {
        $wood = self::c('#a8743e');
        $dark = self::c('#6a4424');
        $thatch = self::c('#c89a48');
        $thatchTop = self::c('#ecc878');
        $h = 9.0 * $H;
        $m->slab([0, 0, 0], [13.0, 1.2, 19.0], self::c('#8a8070'), [0, 0, 0], self::c('#a49a88'));
        $m->slab([0, 1.2, 0], [11.6, $h - 1.2, 17.6], Mesh::times($wood, 0.8), [0, 0, 0], $wood);
        foreach ([-5.6, 5.6] as $x) {
            foreach ([-8.4, -2.8, 2.8, 8.4] as $z) {
                $m->slab([$x, 1.2, $z], [0.8, $h - 1.2, 0.8], $dark);
            }
        }
        // a thatched roof, its ridge along the barn (z)
        foreach ([-1, 1] as $side) {
            $m->box([$side * 3.6, $h + 2.6, 0], [8.6, 1.6, 20.6], $thatch, [0, 0, -$side * 0.62], $thatchTop);
        }
        $m->box([0, $h + 5.0, 0], [1.8, 1.2, 20.8], Mesh::times($thatch, 0.85));
        // the gable towards the camera: its triangle, big doors, the horse's head on top
        $m->tri([-5.8, $h, 8.8], [5.8, $h, 8.8], [0, $h + 4.8, 8.8], [0, 0, 1], $wood, $wood, Mesh::times($wood, 1.1));
        $m->slab([0, 1.2, 8.9], [6.4, 6.2, 0.4], self::c('#2a1c12'));
        foreach ([-1, 1] as $side) {
            $m->slab([$side * 1.55, 1.2, 9.2], [2.9, 5.8, 0.4], $wood, [0, 0, 0], Mesh::times($wood, 1.2));
            self::rod($m, [$side * 0.25, 1.6, 9.5], [$side * 2.85, 6.6, 9.5], 0.18, 0.18, $dark, 4);
        }
        $m->box([0, $h + 5.6, 9.6], [1.2, 2.6, 1.0], $dark, [0.3, 0, 0]);
        $m->box([0, $h + 6.6, 10.6], [1.0, 1.0, 2.2], $dark);
        foreach ([-1, 1] as $side) {
            $m->cyl([$side * 0.4, $h + 7.0, 9.8], 0.25, 0.0, 1.0, $dark, 4);
        }
        // hay, a fence
        $m->cyl([7.6, 0, 6.4], 2.0, 2.0, 2.6, $thatch, 12, [M_PI / 2, 0, 0], true, $thatchTop, true);
        foreach ([-6.0, -2.0, 2.0] as $z) {
            $m->cyl([-8.6, 0, $z], 0.35, 0.3, 3.4, $dark, 5);
        }
        foreach ([1.4, 2.8] as $y) {
            $m->slab([-8.6, $y, -2.0], [0.35, 0.35, 8.4], $wood);
        }
        if ($spec === 0 || $lv >= 3) {
            foreach ([-1, 1] as $side) {
                $m->slab([$side * 3.3, $h - 4.6, 9.15], [1.6, 4.0, 0.2], $flag, [0, 0, 0], Mesh::times($flag, 1.15));
                $m->slab([$side * 3.3, $h - 1.0, 9.2], [1.8, 0.3, 0.3], self::gold());
            }
        }
        if ($spec === 1) {
            foreach ([-7.0, -6.0, -5.0, -4.0] as $z) {
                self::rod($m, [8.4, 0.0, $z], [8.8, 11.0, $z + 0.4], 0.2, 0.2, self::c('#a07a4a'), 4);
                self::rod($m, [8.8, 11.0, $z + 0.4], [8.85, 12.6, $z + 0.45], 0.45, 0.0, self::c('#d0d4dc'), 4);
            }
        }
        if ($lv >= 2) {
            self::pennant($m, 0.0, $h + 5.6, -9.4, $flag, 6.0 + $lv, 5.0);
        }
        return $h + 9.0;
    }

    private static function javelin(Mesh $m, float $H, ?int $spec, int $lv, array $flag): float
    {
        $wood = self::c('#9a6a3a');
        $dark = self::c('#6a4626');
        $thatch = self::c('#c89a48');
        $thatchTop = self::c('#f0cc7c');
        $h = 15.0 * $H;
        foreach ([[-1, -1], [-1, 1], [1, -1], [1, 1]] as [$x, $z]) {
            self::rod($m, [$x * 6.4, 0, $z * 6.4], [$x * 5.2, $h, $z * 5.2], 0.95, 0.8, $wood, 7, Mesh::times($wood, 1.15));
        }
        foreach ([[-1, -1, 1, -1], [1, -1, 1, 1], [-1, 1, 1, 1], [-1, -1, -1, 1]] as [$ax, $az, $bx, $bz]) {
            self::rod($m, [$ax * 6.0, 1.5, $az * 6.0], [$bx * 5.5, $h * 0.62, $bz * 5.5], 0.3, 0.3, $dark, 4);
        }
        // the deck, its railing, a ladder towards the camera
        $m->slab([0, $h, 0], [13.6, 1.0, 13.6], $wood, [0, 0, 0], Mesh::times($wood, 1.2));
        foreach ([-1, 1] as $side) {
            foreach ([[6.4 * $side, 0.0, 0.0, 12.8], [0.0, 6.4 * $side, 12.8, 0.0]] as [$x, $z, $lx, $lz]) {
                $m->slab([$x, $h + 2.2, $z], [$lx ?: 0.4, 0.4, $lz ?: 0.4], $dark);
            }
        }
        foreach ([[-6.4, -6.4], [6.4, -6.4], [-6.4, 6.4], [6.4, 6.4]] as [$x, $z]) {
            $m->cyl([$x, $h + 1.0, $z], 0.45, 0.4, 7.2, $wood, 6);
        }
        foreach ([-1, 1] as $side) {
            self::rod($m, [$side * 1.3, 0, 9.6], [$side * 1.3, $h + 1.0, 6.8], 0.25, 0.25, $dark, 4);
        }
        for ($k = 1; $k < 6; $k++) {
            $t = $k / 6;
            $m->slab([0, $h * $t, 9.6 - 2.8 * $t], [2.6, 0.25, 0.3], $dark);
        }
        self::roof($m, $h + 8.2, 9.2, 7.5, $thatch, $thatchTop, 12, false);
        $m->cyl([0, $h + 15.0, 0], 0.9, 0.0, 1.8, Mesh::times($thatch, 0.8), 8);
        // a bundle of javelins on the deck
        foreach ($spec === 0 ? [[-3.0, 2.0], [2.8, 3.0]] : [[-3.0, 2.0]] as [$bx, $bz]) {
            foreach ([-0.6, 0.0, 0.6] as $k => $o) {
                self::rod($m, [$bx + $o, $h + 1.0, $bz], [$bx + $o * 1.6, $h + 8.6, $bz - 1.6], 0.16, 0.16, self::c('#c8a870'), 4);
                self::rod($m, [$bx + $o * 1.6, $h + 8.6, $bz - 1.6], [$bx + $o * 1.7, $h + 10.0, $bz - 1.9], 0.38, 0.0,
                    self::c($spec === 1 ? '#c84a3a' : '#d8dce4'), 4);
            }
        }
        if ($lv >= 2) {
            self::pennant($m, 6.4, $h + 8.2, 6.4, $flag, 4.0, 4.6);
        }
        return $h + 17.0;
    }

    private static function horn(Mesh $m, float $H, ?int $spec, int $lv, array $flag): float
    {
        $stone = self::c('#d8c8a0');
        $low = self::c('#a8986e');
        $horn = $spec === 0 ? self::c('#4a4044') : self::c($spec === 1 ? '#f4ecd8' : '#e8c048', 1.05);
        $hornTop = $spec === 0 ? self::c('#7a6a6e') : self::c('#fff4c0', 1.1);
        $cloth = $spec === 0 ? self::c('#8a1a18') : $flag;
        $wood = self::c('#7a5232');
        $h = 6.0 * $H;
        $m->cyl([0, 0, 0], 9.0, 8.6, 1.6, $low, 18, [0, 0, 0], true, $stone);
        $m->cyl([0, 1.6, 0], 7.4, 7.0, 1.6, $low, 18, [0, 0, 0], true, $stone);
        $m->cyl([0, 3.2, 0], 3.4, 2.8, $h, $low, 12, [0, 0, 0], true, $stone);
        // the stand, the great curved horn on it
        foreach ([-1, 1] as $side) {
            $m->slab([0.0, 3.2, $side * 3.4], [1.2, $h + 6.0, 1.2], $wood, [0, 0, 0], Mesh::times($wood, 1.2));
        }
        $m->slab([0.0, $h + 8.6, 0], [1.4, 1.2, 8.0], $wood);
        $pts = [[-7.4, $h + 7.8], [-4.0, $h + 9.2], [-0.4, $h + 10.0], [3.4, $h + 11.4], [6.2, $h + 14.0]];
        $radii = [0.55, 0.9, 1.4, 2.0, 3.4];
        for ($k = 0; $k < 4; $k++) {
            self::rod($m, [$pts[$k][0], $pts[$k][1], 0], [$pts[$k + 1][0], $pts[$k + 1][1], 0], $radii[$k], $radii[$k + 1], $horn, 12,
                Mesh::mix($horn, $hornTop, ($k + 1) / 4));
        }
        $m->cyl([6.2, $h + 14.0, 0], 3.6, 3.6, 0.6, self::gold(), 14, [0, 0, -0.75]);
        $m->ball([6.0, $h + 13.9, 0], [0.4, 2.6, 2.6], self::c('#2a1e18'), 10, 4, [0, 0, -0.75]);
        foreach ([1, 2] as $k) {
            $m->cyl([$pts[$k][0], $pts[$k][1] - $radii[$k] * 1.1, 0], $radii[$k] + 0.25, $radii[$k] + 0.25, 0.5, self::gold(), 10, [0, 0, -0.35]);
        }
        $m->ball([-1.0, $h + 8.4, 1.6], [0.5, 1.6, 0.3], $cloth, 5, 3);
        // banners on both sides
        foreach ([-1, 1] as $side) {
            $x = $side * 6.8;
            $m->cyl([$x, 1.6, 4.0], 0.3, 0.25, 10.0 + $lv, $wood, 5);
            $m->slab([$x + 0.0, 5.4 + $lv, 4.25], [2.6, 4.6, 0.25], $cloth, [0, 0, 0], Mesh::times($cloth, 1.15));
            $m->slab([$x, 10.2 + $lv, 4.25], [3.0, 0.35, 0.35], self::gold());
        }
        if ($lv >= 2) {
            self::pennant($m, -6.0, 1.6, -4.4, $flag, 11.0, 4.6);
        }
        return $h + 18.0;
    }

    private static function palisade(Mesh $m, float $H, ?int $spec, int $lv, array $flag): float
    {
        $wood = self::c('#9a6a3a');
        $dark = self::c('#6a4626');
        $stone = self::c('#aaa292');
        $h = 9.0 * $H;
        if ($spec === 1) {
            // a stone rampart, round, with battlements
            $m->cyl([0, 0, 0], 9.6, 9.0, $h, Mesh::times($stone, 0.8), 18, [0, 0, 0], false, $stone);
            $m->cyl([0, 0, 0], 7.6, 7.6, $h - 0.6, Mesh::times($stone, 0.7), 18, [0, 0, 0], true, Mesh::times($stone, 0.85));
            self::crenels($m, $h, 8.6, 14, $stone, 2.0, 2.0);
        } else {
            for ($i = 0; $i < 17; $i++) {
                $a = $i * 2 * M_PI / 18 + M_PI / 2 + M_PI / 9;
                $hh = $h + ($i % 3) * 0.8;
                [$x, $z] = [cos($a) * 8.6, sin($a) * 8.6];
                $m->cyl([$x, 0, $z], 1.05, 0.95, $hh, $i % 2 ? $dark : $wood, 6, [0, 0, 0], false, Mesh::times($wood, 1.15));
                $m->cyl([$x, $hh, $z], 0.95, 0.0, 2.4, Mesh::times($wood, 1.1), 6);
                if ($spec === 0) {
                    self::rod($m, [$x * 1.02, 2.0, $z * 1.02], [$x * 1.32, 3.4, $z * 1.32], 0.35, 0.0, self::c('#d8d0c0'), 4);
                }
            }
            $m->cyl([0, $h * 0.45, 0], 8.9, 8.9, 0.5, $dark, 18, [0, 0, 0], false);
        }
        // the gate towards the camera
        foreach ([-1, 1] as $side) {
            $m->cyl([$side * 2.2, 0, 8.8], 0.9, 0.8, $h + 4.0, $dark, 6, [0, 0, 0], true, $wood);
        }
        $m->slab([0, $h + 2.4, 8.8], [6.0, 1.0, 1.0], $wood);
        // a lookout inside
        $y = $h + 1.5;
        foreach ([[-2.4, -2.4], [2.4, -2.4], [-2.4, 2.4], [2.4, 2.4]] as [$x, $z]) {
            $m->cyl([$x, 0, $z - 1.0], 0.45, 0.4, $y + 4.0, $dark, 5);
        }
        $m->slab([0, $y, -1.0], [6.4, 0.7, 6.4], $wood, [0, 0, 0], Mesh::times($wood, 1.2));
        self::roof($m, $y + 4.0, 5.0, 4.2, self::c('#c89a48'), self::c('#f0cc7c'), 4, false, 0.0, -1.0);
        if ($lv >= 2) {
            self::pennant($m, 0.0, $y + 7.8, -1.0, $flag, 3.5, 4.4);
        }
        return $y + 9.0;
    }

    // ---- the little folk: grassy hills, round doors, flowers ---------------------------------------------------

    private static function slingers(Mesh $m, float $H, ?int $spec, int $lv, array $flag): float
    {
        $soil = self::c('#6a5a3a');
        $grass = self::c('#7cb44a');
        $stone = self::c('#b4ac98');
        $h = 7.0 * $H;
        $m->ball([0, 0.4, -1.0], [10.0, $h, 9.4], $soil, 16, 8, [0, 0, 0], $grass, 0.06, 1);
        // the round door towards the camera, round windows, a chimney
        $m->cyl([0, 2.9, 6.2], 3.2, 3.2, 1.0, $stone, 16, [M_PI / 2, 0, 0], true, Mesh::times($stone, 1.1));
        $m->cyl([0, 2.9, 7.0], 2.5, 2.5, 0.5, $flag, 16, [M_PI / 2, 0, 0], true, Mesh::times($flag, 1.15));
        $m->ball([0.9, 2.9, 7.6], [0.4, 0.4, 0.4], self::gold(), 6, 4);
        foreach ([-1, 1] as $side) {
            $m->cyl([$side * 5.2, 3.0, 5.2], 1.4, 1.4, 0.7, $stone, 12, [M_PI / 2, 0, -$side * 0.6]);
            $m->cyl([$side * 5.35, 3.0, 5.6], 1.0, 1.0, 0.4, self::glow(), 12, [M_PI / 2, 0, -$side * 0.6]);
        }
        $m->cyl([-4.0, $h * 0.75, -3.0], 1.2, 1.0, 4.4, $stone, 8, [0, 0, 0], true, Mesh::times($stone, 1.1));
        self::smoke($m, -4.0, $h * 0.75 + 4.4, -3.0);
        // stones for the slings, flowers, a little fence
        $s = $spec === 0 ? 1.3 : 1.0;
        $col = $spec === 1 ? self::c('#7ad4ff', 1.3) : self::c('#9a968c');
        foreach ([[5.6, 7.6], [6.8, 6.2], [6.0, 6.8]] as $i => [$x, $z]) {
            $m->ball([$x, 0.8 + $i * 0.5, $z], [1.0 * $s, 0.8 * $s, 1.0 * $s], $col, 6, 4, [0, $i, 0], null, 0.15, $i);
        }
        foreach ([[-5.6, 7.4, '#ffffff'], [-6.6, 6.4, '#f6d468'], [-4.6, 8.2, '#f4a6ba'], [3.0, 8.6, '#f6d468']] as [$x, $z, $hex]) {
            $m->ball([$x, 0.9, $z], [0.6, 0.45, 0.6], self::c($hex, 1.1), 5, 3);
        }
        foreach ([-7.6, -6.0, 6.0, 7.6] as $x) {
            $m->cyl([$x, 0, 8.6 - abs($x) * 0.4], 0.25, 0.22, 2.4, self::c('#8a6a42'), 4);
        }
        if ($lv >= 3) {
            $m->material(Mesh::LEAVES * 0.5);
            $m->ball([5.0, $h * 0.6, -5.0], [2.8, 2.4, 2.8], self::c('#3a7a30'), 8, 4, [0, 0, 0], self::c('#a8d860'), 0.15, 3);
            $m->material(Mesh::SOLID);
        }
        if ($lv >= 2) {
            self::pennant($m, 1.6, $h * 0.85, -1.0, $flag, 5.0 + $lv, 4.2);
        }
        return $h + 6.0;
    }

    private static function traps(Mesh $m, float $H, ?int $spec, int $lv, array $flag): float
    {
        $wood = self::c('#a06e3e');
        $dark = self::c('#6a4626');
        $roof = self::c('#5a8a3a');
        $roofTop = self::c('#9ac25a');
        $h = 8.0 * $H;
        $m->slab([0, 0, -1.0], [12.0, 1.0, 10.0], self::c('#8a8070'), [0, 0, 0], self::c('#a49a88'));
        $m->slab([0, 1.0, -1.6], [11.0, $h - 1.0, 8.4], Mesh::times($wood, 0.8), [0, 0, 0], $wood);
        foreach ([-4.2, -1.4, 1.4, 4.2] as $x) {
            $m->slab([$x, 1.0, 2.65], [0.25, $h - 1.0, 0.3], $dark);
        }
        // a lean-to roof of mossy shingles, sloping towards the camera
        $m->box([0, $h + 1.4, -0.8], [12.6, 0.9, 11.4], $roof, [0.24, 0, 0], $roofTop);
        // tools on the front, a saw and an axe
        $m->slab([-2.6, 3.0, 2.9], [3.4, 1.0, 0.2], self::c('#c8ccd4'));
        $m->slab([-0.7, 3.0, 2.9], [0.6, 1.0, 0.3], $dark);
        self::rod($m, [2.4, 1.6, 2.95], [3.4, 5.6, 2.95], 0.2, 0.2, $dark, 4);
        $m->box([3.5, 5.3, 2.95], [1.4, 1.0, 0.25], self::c('#b8bcc4'), [0, 0, -0.25]);
        // the log pile beside it
        $logs = $spec === 1 ? 2.0 : 1.0;
        foreach ([[0.8, 0.0], [2.6, 0.0], [1.7, 1.5]] as $i => [$y, $dz]) {
            $m->cyl([7.6, $y * $logs + 0.9 * $logs, -3.6 + $dz + $i * 1.8], 0.9 * $logs, 0.9 * $logs, 7.0, self::c('#7a5030'), 10, [0, 0, M_PI / 2], true,
                self::c('#d8b888'), true);
        }
        // a stump with a trap on it
        $m->cyl([-6.6, 0, 5.4], 1.8, 1.6, 2.2, $dark, 10, [0, 0, 0], true, self::c('#d8b888'));
        $iron = self::c($spec === 0 ? '#c8ccd4' : '#8a8e96');
        $m->cyl([-6.6, 2.2, 5.4], 1.5, 1.5, 0.3, $iron, 10);
        for ($i = 0; $i < ($spec === 0 ? 8 : 5); $i++) {
            $a = $i * 2 * M_PI / ($spec === 0 ? 8 : 5);
            $m->cyl([-6.6 + cos($a) * 1.2, 2.5, 5.4 + sin($a) * 1.2], 0.28, 0.0, 1.2, $iron, 4);
        }
        if ($lv >= 3) {
            $m->material(Mesh::LEAVES * 0.4);
            $m->ball([-5.2, 1.4, -4.4], [3.0, 2.4, 2.6], self::c('#3a7a30'), 8, 4, [0, 0, 0], self::c('#a8d860'), 0.15, 2);
            $m->material(Mesh::SOLID);
        }
        if ($lv >= 2) {
            self::pennant($m, -5.0, $h + 1.0, -5.0, $flag, 5.0 + $lv, 4.2);
        }
        return $h + 6.0;
    }

    private static function inn(Mesh $m, float $H, ?int $spec, int $lv, array $flag): float
    {
        $plaster = self::c('#f2e8d2');
        $beam = self::c('#5a3a24');
        $tile = self::c('#b8583a');
        $tileTop = self::c('#e08458');
        $stone = self::c('#a49a88');
        $wide = $spec === 0 ? 1.15 : 1.0;
        $h1 = 6.0 * $H;
        $h2 = 5.2 * $H;
        $w = 14.0 * $wide;
        $m->slab([0, 0, -0.6], [$w, $h1, 11.0], Mesh::times($stone, 0.85), [0, 0, 0], $stone);
        $m->slab([0, $h1, -0.6], [$w + 1.4, $h2, 12.2], Mesh::times($plaster, 0.92), [0, 0, 0], $plaster);
        // timber frame on the upper floor
        foreach ([-0.5, -0.17, 0.17, 0.5] as $f) {
            $m->slab([$f * ($w + 1.2), $h1, 5.55], [0.45, $h2, 0.25], $beam);
        }
        $m->slab([0, $h1, 5.55], [$w + 1.6, 0.5, 0.3], $beam);
        $m->slab([0, $h1 + $h2 - 0.5, 5.55], [$w + 1.6, 0.5, 0.3], $beam);
        foreach ([-1, 1] as $side) {
            self::rod($m, [$side * 0.17 * ($w + 1.2), $h1 + 0.4, 5.6], [$side * 0.5 * ($w + 1.2), $h1 + $h2 - 0.4, 5.6], 0.2, 0.2, $beam, 4);
        }
        self::gable($m, 0.0, $h1 + $h2, -0.6, $w + 3.0, 13.6, 0.68, $tile, $tileTop);
        // the chimney and its smoke
        $m->slab([-$w * 0.3, $h1 + $h2, -3.0], [2.4, 7.0, 2.4], $stone, [0, 0, 0], Mesh::times($stone, 1.1));
        self::smoke($m, -$w * 0.3, $h1 + $h2 + 7.0, -3.0);
        // lit windows, the door, the hanging sign, a barrel
        foreach ([-0.32, 0.32] as $f) {
            $m->slab([$f * $w, 1.6, 5.0], [2.2, 2.4, 0.3], self::glow());
            $m->slab([$f * $w, 1.4, 5.1], [2.8, 0.4, 0.5], $beam);
            $m->slab([$f * ($w + 1.2), $h1 + 1.2, 5.65], [1.8, 2.2, 0.3], self::glow());
        }
        $m->slab([0, 0, 5.2], [2.8, 4.4, 0.4], self::c('#7a5030'), [0, 0, 0], self::c('#9a6a40'));
        $m->slab([$w / 2 + 1.0, $h1 + 0.4, 5.0], [2.4, 0.3, 0.3], $beam);
        $m->slab([$w / 2 + 1.8, $h1 - 2.6, 5.0], [2.2, 2.6, 0.3], self::c('#7a5030'), [0, 0, 0], self::c('#9a6a40'));
        $m->cyl([$w / 2 + 1.8, $h1 - 2.0, 5.2], 0.6, 0.55, 1.2, self::gold(), 8);
        $barrels = $spec === 1 ? [[-$w / 2 - 1.6, 4.0], [-$w / 2 - 1.6, 1.2], [-$w / 2 - 1.4, -1.6], [$w / 2 + 1.4, -3.4]] : [[-$w / 2 - 1.4, 4.0]];
        foreach ($barrels as [$x, $z]) {
            $m->cyl([$x, 0, $z], 1.2, 1.3, 2.6, self::c('#8a5a30'), 10, [0, 0, 0], true, self::c('#b07a48'));
            $m->cyl([$x, 0.5, $z], 1.32, 1.32, 0.3, self::c('#3a3a3a'), 10);
            $m->cyl([$x, 2.0, $z], 1.26, 1.26, 0.3, self::c('#3a3a3a'), 10);
        }
        if ($spec === 1) {
            $m->cyl([$w / 2 + 1.6, 0, 1.0], 2.2, 2.0, 4.0, self::c('#c87a40'), 14, [0, 0, 0], true, self::c('#f0a868'));
        }
        if ($lv >= 3) {
            $m->material(Mesh::LEAVES * 0.25);
            foreach ([-0.32, 0.32] as $f) {
                $m->ball([$f * ($w + 1.2), $h1 + 0.1, 6.0], [1.4, 0.6, 0.6], self::c('#3a7a30'), 6, 3, [0, 0, 0], self::c('#e86a8a'), 0.2, 1);
            }
            $m->material(Mesh::SOLID);
        }
        if ($lv >= 2) {
            self::pennant($m, $w * 0.35, $h1 + $h2 + 3.6, -0.6, $flag, 5.0, 4.4);
        }
        return $h1 + $h2 + 9.0;
    }

    private static function garden(Mesh $m, float $H, ?int $spec, int $lv, array $flag): float
    {
        $hedge = self::c($spec === 0 ? '#3a2a4a' : '#2e6a2e');
        $hedgeTop = self::c($spec === 0 ? '#9a6ab8' : '#8cc458');
        $h = 4.6 * $H;
        $m->cyl([0, 0, 0], 9.6, 9.6, 0.4, self::c('#6a5a3a'), 18, [0, 0, 0], true, self::c('#7a6a42'));
        // a round hedge, open towards the camera
        $m->material(Mesh::LEAVES * 0.4);
        for ($i = 0; $i < 9; $i++) {
            $a = M_PI / 2 + 0.55 + $i * (2 * M_PI - 1.1) / 8;
            $m->ball([cos($a) * 8.0, $h * 0.55, sin($a) * 8.0], [3.0, $h * 0.75, 3.0], $hedge, 8, 4, [0, $a, 0], $hedgeTop, 0.14, $i);
        }
        // the rose arch at the opening
        for ($k = 0; $k <= 6; $k++) {
            $t = M_PI * $k / 6;
            $m->ball([cos($t) * 3.2, 1.0 + sin($t) * 7.0, 8.2], [1.1, 1.1, 1.1], $hedge, 6, 4, [0, $k, 0], $hedgeTop, 0.2, $k);
        }
        $m->material(Mesh::SOLID);
        foreach ([[2.2, 9.6], [-1.8, 11.2], [0.4, 12.4], [-2.6, 8.6], [2.8, 11.6]] as [$t, $y]) {
            $m->ball([cos($t / 3.0) * 3.2 * ($t > 0 ? 1 : -1), $y * 0.6, 8.9], [0.55, 0.55, 0.4], self::c($spec === 0 ? '#e0b0ff' : '#ff7a9a', 1.15), 5, 3);
        }
        // flower beds and a scarecrow
        $beds = ['#ffffff', '#f6d468', '#f4a6ba', '#c4b8f4', '#ff9a5a'];
        for ($i = 0; $i < 14; $i++) {
            $a = $i * 2.4;
            $r = 2.0 + ($i % 4) * 1.2;
            $m->ball([cos($a) * $r, 0.9, sin($a) * $r - 0.6], [0.65, 0.5, 0.65], self::c($beds[$i % 5], 1.1), 5, 3);
        }
        $wood = self::c('#8a6a42');
        $m->cyl([0, 0, -2.0], 0.3, 0.25, 8.6 * $H, $wood, 5);
        $m->slab([0, 6.0 * $H, -2.0], [6.0, 0.4, 0.4], $wood);
        $m->slab([0, 4.6 * $H, -2.0], [2.6, 2.8, 1.4], self::c('#7a8a4a'), [0, 0, 0], self::c('#9aaa5a'));
        $m->ball([0, 8.4 * $H, -2.0], [1.3, 1.4, 1.3], self::c('#e8c890'), 8, 5);
        $m->cyl([0, 9.0 * $H, -2.0], 2.4, 2.4, 0.3, self::c('#c89a48'), 12);
        $m->cyl([0, 9.3 * $H, -2.0], 1.2, 0.8, 1.6, self::c('#c89a48'), 10);
        // the slingers hide in the hedge: their eyes
        foreach ([[M_PI * 0.15, 0], [M_PI * 0.9, 1]] as [$a, $k]) {
            foreach ([-0.5, 0.5] as $o) {
                $m->ball([cos($a) * 10.2 + $o * sin($a), $h * 0.6, sin($a) * 10.2 - $o * cos($a)], [0.35, 0.45, 0.35], self::c('#ffffff', 1.2), 4, 2);
            }
        }
        if ($spec === 1) {
            for ($i = 0; $i < 6; $i++) {
                $a = $i * 1.05;
                $m->cyl([cos($a) * 10.0, 0.3, sin($a) * 10.0], 1.4, 1.4, 0.3, self::c('#b89a5a'), 10, [0.15, $a, 0.1], false);
            }
        }
        if ($lv >= 2) {
            self::pennant($m, -7.0, $h, -5.0, $flag, 6.0 + $lv, 4.4);
        }
        return 10.4 * $H + 2.0;
    }

    // ---- the orcs: dark wood, red cloth, bones, iron spikes ----------------------------------------------------

    private static function barracks(Mesh $m, float $H, ?int $spec, int $lv, array $flag): float
    {
        $cloth = self::c('#8a2a1e');
        $clothTop = self::c('#c0503a');
        $wood = self::c('#4e3624');
        $bone = self::c('#e8e0c8');
        $h = 15.0 * $H;
        $m->cyl([0, 0, 0], 10.4, 1.2, $h, $cloth, 10, [0, 0.3, 0], false, $clothTop);
        $m->cyl([0, 0, 0], 10.8, 10.8, 1.0, self::c('#3a2618'), 10, [0, 0.3, 0], false);
        for ($i = 0; $i < 5; $i++) {
            $a = $i * 2 * M_PI / 5 + 0.3;
            self::rod($m, [cos($a) * 10.6, 0.0, sin($a) * 10.6], [cos($a) * 0.8, $h + 3.0, sin($a) * 0.8], 0.3, 0.25, $wood, 4);
        }
        // the dark opening towards the camera
        $m->tri([-3.2, 0.1, 9.0], [3.2, 0.1, 9.0], [0.0, $h * 0.55, 5.2], [0, 0.4, 1], self::c('#1a100c'), self::c('#1a100c'), self::c('#2a1a12'));
        // a totem with a skull, spears, a fire
        $m->cyl([-8.4, 0, 6.2], 0.6, 0.5, 13.0 + $lv, $wood, 6);
        $m->ball([-8.4, 13.6 + $lv, 6.2], [1.4, 1.3, 1.3], $bone, 8, 5);
        foreach ([-1, 1] as $side) {
            self::rod($m, [-8.4 + $side * 1.0, 14.0 + $lv, 6.2], [-8.4 + $side * 2.4, 16.0 + $lv, 6.2], 0.4, 0.0, $bone, 5);
        }
        $m->slab([-8.4, 9.4 + $lv, 6.7], [1.6, 3.0, 0.2], $flag);
        foreach ([[7.6, 5.0], [8.6, 3.2]] as [$x, $z]) {
            self::rod($m, [$x, 0, $z], [$x + 0.6, 11.0, $z - 0.8], 0.2, 0.2, $wood, 4);
            self::rod($m, [$x + 0.6, 11.0, $z - 0.8], [$x + 0.7, 12.8, $z - 0.9], 0.5, 0.0, self::c('#8a8e96'), 4);
        }
        $m->cyl([4.0, 0, 9.6], 2.0, 1.8, 0.6, self::c('#6a645c'), 10);
        $m->cyl([4.0, 0.6, 9.6], 1.2, 0.0, 2.2 + ($spec === 0 ? 1.4 : 0.0), self::c('#ff8a30', 1.5), 8);
        $m->cyl([4.0, 0.6, 9.6], 0.6, 0.0, 1.4, self::c('#ffe060', 1.5), 6);
        if ($spec === 1) {
            for ($i = 0; $i < 5; $i++) {
                $a = M_PI / 2 + 0.9 + $i * 0.9;
                $m->cyl([cos($a) * 11.0, 2.4, sin($a) * 11.0], 2.0, 2.0, 0.5, self::c('#7a7e86'), 12, [M_PI / 2, -$a + M_PI / 2, 0], true, self::c('#a8acb4'));
            }
        }
        if ($lv >= 3 || $spec === 0) {
            foreach ([0.0, 1.6, 3.2, 4.8] as $a) {
                self::rod($m, [cos($a) * 9.6, 1.2, sin($a) * 9.6], [cos($a) * 12.2, 2.6, sin($a) * 12.2], 0.4, 0.0, $bone, 4);
            }
        }
        if ($lv >= 2) {
            self::pennant($m, 0.0, $h + 2.2, 0.0, $flag, 5.0, 4.8);
        }
        return $h + 8.0;
    }

    private static function catapult(Mesh $m, float $H, ?int $spec, int $lv, array $flag): float
    {
        $wood = self::c('#6a4a2c');
        $dark = self::c('#3e2a1a');
        $iron = self::c('#5a5e66');
        $bone = self::c('#e8e0c8');
        $m->slab([0, 1.6, 0], [15.0, 1.6, 9.0], $wood, [0, 0, 0], Mesh::times($wood, 1.2));
        foreach ([[-5.2, -1], [-5.2, 1], [5.2, -1], [5.2, 1]] as [$x, $side]) {
            $m->cyl([$x, 2.4, $side * 5.0], 2.6, 2.6, 1.0, $dark, 12, [M_PI / 2, 0, 0], true, Mesh::times($wood, 1.1), true);
            $m->cyl([$x, 2.4, $side * 5.05], 2.7, 2.7, 0.3, $iron, 12, [M_PI / 2, 0, 0]);
            $m->cyl([$x, 2.4, $side * 5.6], 0.6, 0.6, 0.5, $iron, 8, [M_PI / 2, 0, 0]);
        }
        // the frame, its axle and the throwing arm with its load
        foreach ([-1, 1] as $side) {
            self::rod($m, [-3.0, 3.2, $side * 3.6], [0.4, 11.0 * $H, $side * 3.2], 0.7, 0.6, $wood, 6);
            self::rod($m, [3.8, 3.2, $side * 3.6], [0.4, 11.0 * $H, $side * 3.2], 0.7, 0.6, $wood, 6);
        }
        $m->cyl([0.4, 11.0 * $H - 0.6, -4.0], 0.7, 0.7, 8.0, $iron, 8, [M_PI / 2, 0, 0]);
        self::rod($m, [5.6, 4.6, 0], [-7.6, 9.8 * $H + 5.0, 0], 0.8, 0.6, $wood, 8, Mesh::times($wood, 1.2));
        $m->slab([5.0, 3.2, 0], [3.4, 2.8, 3.4], $dark, [0, 0, 0.3], $wood);
        foreach ([[-1, -1], [1, -1], [-1, 1], [1, 1]] as [$a, $b]) {
            $m->cyl([5.0 + $a * 1.4, 4.6, $b * 1.4], 0.35, 0.0, 1.4, $iron, 4, [0, 0, -$a * 0.7]);
        }
        $cup = [-7.6, 9.8 * $H + 5.0, 0];
        $m->cyl([$cup[0], $cup[1] - 0.8, 0], 1.2, 2.2, 1.6, $dark, 10, [0, 0, 0], false);
        if ($spec === 0) {
            $m->ball([$cup[0], $cup[1] + 1.0, 0], [2.2, 2.0, 2.2], self::c('#4a3a30'), 8, 5);
            $m->ball([$cup[0], $cup[1] + 2.4, 0], [1.4, 1.8, 1.4], self::c('#ff8a30', 1.5), 6, 4);
        } else {
            $s = $spec === 1 ? 1.35 : 1.0;
            $m->ball([$cup[0], $cup[1] + 1.0 * $s, 0], [2.2 * $s, 2.0 * $s, 2.2 * $s], self::c('#7a766c'), 8, 5, [0, 0, 0], self::c('#a8a498'), 0.15, 2);
        }
        // skulls on the frame, a ragged flag
        $m->ball([-3.2, 6.2, 4.4], [1.0, 1.0, 1.0], $bone, 6, 4);
        $m->ball([3.6, 6.2, -4.4], [1.0, 1.0, 1.0], $bone, 6, 4);
        if ($lv >= 2) {
            self::pennant($m, 6.6, 3.2, 3.8, $flag, 8.0 + $lv, 4.6);
        }
        return 9.8 * $H + 9.0;
    }

    private static function pitch(Mesh $m, float $H, ?int $spec, int $lv, array $flag): float
    {
        $pot = self::c('#2e2c32');
        $potTop = self::c('#5a5862');
        $stone = self::c('#6a645c');
        $wood = self::c('#4e3624');
        $h = 8.0 * $H;
        $m->cyl([0, 0, 0], 9.6, 9.4, 1.6, Mesh::times($stone, 0.8), 16, [0, 0, 0], true, $stone);
        for ($i = 0; $i < 10; $i++) {
            $a = $i * 2 * M_PI / 10;
            $m->ball([cos($a) * 6.4, 2.0, sin($a) * 6.4], [1.6, 1.1, 1.4], $stone, 6, 3, [0, $a, 0], self::c('#8a847a'), 0.15, $i);
        }
        // the fire under the cauldron
        foreach ([0.0, 2.1, 4.2] as $a) {
            $m->cyl([cos($a) * 2.4, 1.6, sin($a) * 2.4], 1.8, 0.0, 4.4, self::c('#ff7a24', 1.45), 6);
            $m->cyl([cos($a) * 2.4, 1.6, sin($a) * 2.4], 0.9, 0.0, 2.8, self::c('#ffe060', 1.5), 5);
        }
        // the cauldron, its bubbling pitch
        $m->ball([0, $h * 0.5 + 3.0, 0], [6.4, $h * 0.55, 6.4], $pot, 16, 8, [0, 0, 0], $potTop);
        $m->cyl([0, $h * 0.5 + 3.0 + $h * 0.3, 0], 6.0, 6.0, 0.9, $potTop, 16, [0, 0, 0], false);
        $m->cyl([0, $h * 0.5 + 3.0 + $h * 0.3 + 0.9, 0], 6.0, 6.0, 0.1, self::c('#140c14'), 16);
        $y = $h * 0.5 + 4.0 + $h * 0.3;
        foreach ([[-2.0, 1.0, 1.2], [1.8, -1.4, 0.9], [0.6, 2.4, 0.7]] as [$x, $z, $r]) {
            $m->ball([$x, $y + 0.2, $z], [$r, $r * 0.7, $r], self::c($spec === 0 ? '#ff7a24' : '#4a3a4a', $spec === 0 ? 1.4 : 1.0), 6, 4);
        }
        // a tripod of logs, a ladle
        foreach ([0.5, 2.6, 4.7] as $a) {
            self::rod($m, [cos($a) * 8.4, 1.6, sin($a) * 8.4], [0, $y + 8.0, 0], 0.45, 0.35, $wood, 5);
        }
        $m->cyl([0, $y + 1.0, 0], 0.08, 0.08, 7.0, self::c('#3a3a40'), 3);
        self::rod($m, [2.0, $y + 0.5, 3.0], [5.6, $y + 6.0, 6.0], 0.25, 0.25, $wood, 4);
        if ($spec === 1) {
            foreach ([-1, 1] as $side) {
                $m->cyl([$side * 8.4, 1.6, 4.0], 1.4, 1.5, 3.0, self::c('#5a3a24'), 10, [0, 0, 0], true, self::c('#2a2026'));
            }
        }
        if ($lv >= 2) {
            self::pennant($m, -7.4, 1.6, -4.0, $flag, 9.0 + $lv, 4.6);
        }
        return $y + 9.0;
    }

    private static function drums(Mesh $m, float $H, ?int $spec, int $lv, array $flag): float
    {
        $body = self::c('#7a2a1c');
        $bodyTop = self::c('#a84430');
        $skin = self::c('#e8d4a8');
        $wood = self::c('#4e3624');
        $bone = self::c('#e8e0c8');
        $iron = self::c('#5a5e66');
        $h = 8.0 * $H;
        // a stand, the great drum, its lacing
        foreach ([[-1, -1], [-1, 1], [1, -1], [1, 1]] as [$x, $z]) {
            self::rod($m, [$x * 5.6, 0, $z * 5.6], [$x * 4.6, 3.0, $z * 4.6], 0.5, 0.45, $wood, 5);
        }
        $m->cyl([0, 3.0, 0], 7.4, 8.2, $h, $body, 18, [0, 0, 0], false, $bodyTop);
        $m->cyl([0, 3.0 + $h, 0], 8.4, 8.4, 0.8, $iron, 18, [0, 0, 0], false);
        $m->cyl([0, 3.0 + $h + 0.1, 0], 8.0, 8.0, 0.4, $skin, 18, [0, 0, 0], true, Mesh::times($skin, 1.08));
        $m->cyl([0, 3.0, 0], 7.6, 7.6, 0.6, $iron, 18, [0, 0, 0], false);
        for ($i = 0; $i < 12; $i++) {
            $a = $i * 2 * M_PI / 12;
            self::rod($m, [cos($a) * 7.6, 3.6, sin($a) * 7.6], [cos($a + 0.26) * 8.25, 2.8 + $h, sin($a + 0.26) * 8.25], 0.12, 0.12, $skin, 3);
        }
        // two small drums
        foreach ([[-8.6, 5.0, 3.4], [8.2, 6.0, 3.0]] as [$x, $z, $r]) {
            $m->cyl([$x, 0, $z], $r, $r + 0.4, 4.0, $body, 12, [0, 0, 0], false, $bodyTop);
            $m->cyl([$x, 4.0, $z], $r + 0.2, $r + 0.2, 0.3, $skin, 12);
        }
        // crossed bone sticks on top, a banner pole with a skull
        foreach ([-1, 1] as $side) {
            self::rod($m, [-3.0, 3.0 + $h + 0.6, $side * 3.0], [3.4, 3.0 + $h + 2.4, -$side * 2.0], 0.35, 0.3, $bone, 5);
            $m->ball([3.4, 3.0 + $h + 2.4, -$side * 2.0], [0.7, 0.7, 0.7], $bone, 5, 3);
        }
        $m->cyl([-7.0, 0, -5.0], 0.4, 0.35, 15.0 + $lv * 1.5, $wood, 6);
        $m->ball([-7.0, 15.6 + $lv * 1.5, -5.0], [1.3, 1.2, 1.2], $bone, 8, 5);
        $m->slab([-5.6, 9.0 + $lv * 1.5, -5.0], [2.6, 5.0, 0.25], $spec === 0 ? self::c('#1a1a1a') : $flag, [0, 0, 0], self::c('#c0503a'));
        if ($spec === 1) {
            foreach ([0.4, 2.0, 3.6, 5.2] as $a) {
                self::rod($m, [cos($a) * 8.0, 3.0 + $h * 0.5, sin($a) * 8.0], [cos($a) * 10.4, 3.0 + $h * 0.5, sin($a) * 10.4], 0.5, 0.0, $iron, 4);
            }
        }
        if ($lv >= 2) {
            self::pennant($m, 7.0, 0.0, -4.6, $flag, 12.0 + $lv, 4.6);
        }
        return 3.0 + $h + 6.0;
    }
}
