<?php

namespace App\View3d;

/**
 * The little living scenes of the menus (the side cards): a round island of grass floating in the sky, its underside
 * of earth and rock narrowing to a point, roots hanging from it; a side's four towers on it and its foes walking round
 * them. Pure geometry and framing: FactionSelectScene draws it through World3d::vignette(). Units: the battle's (a
 * tower about 30 tall); the scene scales it all by SCALE.
 */
final class Diorama
{
    /** the island's radius */
    public const RADIUS = 46.0;
    /** how deep its underside goes, down to the tip */
    public const DEPTH = 32.0;
    /** how much bigger than in the battle everything is drawn (the camera stays well beyond Rig::NEAR) */
    public const SCALE = 2.2;
    /** the towers and the foes, against their size in the battle */
    public const TOWER = 0.85;
    public const UNIT = 1.25;
    /** the tallest tower at its best level (the elves' archers, with their flag): tests/VignettesTest checks it */
    public const TALLEST = 52.0;
    /** where the four towers stand: [x, z] */
    public const SPOTS = [[-20.0, -8.0], [-5.0, 7.0], [9.0, -10.0], [21.0, 5.0]];
    /** the camera: its angle above the horizon (low enough to show the underside), how far it sways (radians) */
    public const PITCH = 19.0;
    public const SWAY = 0.45;
    /** how dark the soft shadows are: the 3D layer is over a dark 2D there, which their blending lets show a little */
    public const SHADOW = 0.25;

    /** The island: grass on top (soft patches), bushes and stones round the edge, earth and rock under it, roots. */
    public static function island(): Mesh
    {
        $r = self::RADIUS;
        $grass = Mesh::rgb('#527e3c');
        $m = new Mesh();
        // the underside: a band of turf, earth, then rock, down to a point
        $m->cyl([0, -3.0, 0], $r * 0.93, $r, 3.0, Mesh::rgb('#5a6e30'), 28, [0, 0, 0], false, Mesh::rgb('#3e6a2c'));
        $m->cyl([0, -16.0, 0], $r * 0.58, $r * 0.93, 13.0, Mesh::rgb('#5c4030'), 28, [0, 0, 0], false, Mesh::rgb('#7a5a3c'));
        $m->cyl([0, -26.0, 0], $r * 0.22, $r * 0.58, 10.0, Mesh::rgb('#5e5a56'), 20, [0, 0, 0], false, Mesh::rgb('#5c4030'));
        $m->cyl([0, -self::DEPTH, 0], 0.0, $r * 0.22, self::DEPTH - 26.0, Mesh::rgb('#4a4744'), 12, [0, 0, 0], false, Mesh::rgb('#5e5a56'));
        // stones sticking out of it, all round (the camera turns)
        for ($i = 0; $i < 9; $i++) {
            $a = $i * 0.7 + 0.4;
            $d = 5.0 + 17.0 * Mesh::hash($i, 21);
            $size = 2.6 + 2.2 * Mesh::hash($i, 23);
            $rho = self::underside($d) - $size * 0.3;
            $m->ball([cos($a) * $rho, -$d, sin($a) * $rho], [$size, $size * 0.8, $size], Mesh::rgb('#8a847a'), 6, 3, [0, 0, 0],
                Mesh::rgb('#a8a296'), 0.2, $i);
        }
        // roots hanging under it
        for ($i = 0; $i < 7; $i++) {
            $a = $i * 0.9 + 1.1;
            $d = 4.0 + 8.0 * Mesh::hash($i, 31);
            $rho = self::underside($d) - 0.5;
            $len = 6.0 + 8.0 * Mesh::hash($i, 33);
            $m->cyl([cos($a) * $rho, -$d - $len, sin($a) * $rho], 0.0, 0.9, $len, Mesh::rgb('#4a3424'), 5, [0, 0, 0], false,
                Mesh::rgb('#5c4232'));
        }
        // the grass: a lid, with soft patches of a deeper and a lighter green
        $m->cyl([0, -0.2, 0], $r, $r, 0.2, $grass, 28);
        for ($i = 0; $i < 8; $i++) {
            $a = $i * 2.3;
            $d = $r * (0.2 + 0.55 * Mesh::hash($i, 3));
            $size = 7.0 + 6.0 * Mesh::hash($i, 5);
            $patch = Mesh::mix(Mesh::rgb('#4a7536'), Mesh::rgb('#6e9646'), Mesh::hash($i, 7));
            $m->cyl([cos($a) * $d, 0.02, sin($a) * $d], $size, $size, 0.05, $patch, 12);
        }
        // bushes and stones along the edge
        for ($i = 0; $i < 9; $i++) {
            $a = $i * 0.7 + 3.4;
            $d = $r * 0.88;
            $size = 3.0 + 2.5 * Mesh::hash($i, 11);
            if ($i % 3 === 2) {
                $m->ball([cos($a) * $d, 0.8, sin($a) * $d], [$size * 0.8, $size * 0.5, $size * 0.7], Mesh::rgb('#9a958a'), 6, 3);
            } else {
                $m->material(Mesh::LEAVES);
                $m->ball([cos($a) * $d, $size * 0.6, sin($a) * $d], [$size, $size * 0.85, $size], Mesh::rgb('#30602a'), 7, 4, [0, 0, 0],
                    Mesh::rgb('#6c9e40'), 0.15, $i);
                $m->material(Mesh::SOLID);
            }
        }
        return $m;
    }

    /** The radius of the island's underside $d below the grass (the turf band, the earth, the rock, the tip). */
    private static function underside(float $d): float
    {
        $r = self::RADIUS;
        return match (true) {
            $d <= 3.0 => $r * (1.0 - 0.07 * $d / 3.0),
            $d <= 16.0 => $r * (0.93 - 0.35 * ($d - 3.0) / 13.0),
            $d <= 26.0 => $r * (0.58 - 0.36 * ($d - 16.0) / 10.0),
            default => $r * 0.22 * max(0.0, self::DEPTH - $d) / (self::DEPTH - 26.0),
        };
    }

    /**
     * The camera of a vignette $aspect wide (width / height), for Rig::orbit(): [target, distance] (scaled). The
     * closest that keeps in the frame the top of the tallest tower at the back (the camera swayed) and the island's tip.
     * @return array{0: array{0: float, 1: float, 2: float}, 1: float}
     */
    public static function camera(float $aspect): array
    {
        $p = deg2rad(self::PITCH);
        [$c, $s] = [cos($p), sin($p)];
        // rows of the screen (down), in units, seen from that angle: the highest point and the lowest
        $back = 19.0;                                       // how far back a tower's top can get as the camera sways
        $top = -(self::TALLEST * self::TOWER * $c + $back * $s);
        $tip = self::DEPTH * $c;
        $tan = tan(deg2rad(Rig::FOV) / 2);
        $halfH = ($tip - $top) / 2 * 1.14;                  // a margin (what is near the camera looks bigger)
        $halfW = (self::RADIUS + 5.0) * 1.08;
        $distance = max($halfH / $tan, $halfW / ($tan * max(0.5, $aspect)));
        // the middle of it all in the middle of the frame
        $mid = ($top + $tip) / 2;
        return [[0.0, -$mid / $c * self::SCALE, 0.0], $distance * self::SCALE];
    }

    /** About how far (units) a foe has walked round the island at time $t: its legs keep in step with it. */
    public static function walked(float $t): float
    {
        return $t * 0.32 * self::RADIUS * 0.73;
    }

    /** Where foe $i of $n walks at time $t, round the towers: [x, z, yaw] (the yaw of World3d::draw()). @return array{0: float, 1: float, 2: float} */
    public static function walker(int $i, int $n, float $t): array
    {
        $a = -$t * 0.32 + $i * 2 * M_PI / max(1, $n);
        [$rx, $rz] = [self::RADIUS * 0.8, self::RADIUS * 0.66];
        $x = cos($a) * $rx;
        $z = sin($a) * $rz;
        // it goes clockwise seen from above: its heading is the tangent
        $dx = sin($a) * $rx;
        $dz = -cos($a) * $rz;
        return [$x, $z, -atan2($dz, $dx)];
    }
}
