<?php

namespace App\View3d;

/**
 * The little living scenes of the menus (the side cards): a round island of grass floating over a crust of earth,
 * a side's four towers on it and its foes walking round them. Pure geometry: FactionSelectScene draws it through
 * World3d::vignette(). Units: the battle's (a tower about 30 tall); the scene scales it all by SCALE.
 */
final class Diorama
{
    /** the island's radius */
    public const RADIUS = 46.0;
    /** how much bigger than in the battle everything is drawn (the camera stays beyond Rig::NEAR) */
    public const SCALE = 2.2;
    /** where the four towers stand: [x, z] */
    public const SPOTS = [[-22.0, -8.0], [-6.0, 6.0], [10.0, -10.0], [24.0, 4.0]];

    /** The island: grass on top (patches of two greens), a crust of earth and stones under it, a few bushes. */
    public static function island(array $grass = [0.42, 0.66, 0.28]): Mesh
    {
        $r = self::RADIUS;
        $m = new Mesh();
        // the crust: earth, narrowing downwards, a lighter rim of turf
        $m->cyl([0, -16, 0], $r * 0.35, $r * 0.92, 13.0, Mesh::rgb('#5a3e2a'), 18, [0, 0, 0], false, Mesh::rgb('#7a5a3a'));
        $m->cyl([0, -3, 0], $r * 0.92, $r, 3.0, Mesh::rgb('#6e5232'), 18, [0, 0, 0], false, Mesh::times($grass, 0.8));
        $m->cyl([0, -16.5, 0], $r * 0.08, $r * 0.35, 0.6, Mesh::rgb('#4a3222'), 12);
        // the grass: a lid, with soft patches
        $m->cyl([0, -0.2, 0], $r, $r, 0.2, $grass, 18);
        for ($i = 0; $i < 7; $i++) {
            $a = $i * 2.3;
            $d = $r * (0.25 + 0.5 * Mesh::hash($i, 3));
            $m->cyl([cos($a) * $d, 0.02, sin($a) * $d], 7.0 + 5 * Mesh::hash($i, 5), 7.0 + 5 * Mesh::hash($i, 5), 0.05,
                Mesh::times($grass, 0.86 + 0.28 * Mesh::hash($i, 7)), 10);
        }
        // bushes and stones along the edge
        for ($i = 0; $i < 9; $i++) {
            $a = $i * 0.7 + 3.4;
            $d = $r * 0.86;
            $size = 3.0 + 2.5 * Mesh::hash($i, 11);
            if ($i % 3 === 2) {
                $m->ball([cos($a) * $d, 0.8, sin($a) * $d], [$size * 0.8, $size * 0.5, $size * 0.7], Mesh::rgb('#9a958a'), 6, 3);
            } else {
                $leaf = Mesh::mix($grass, [0.2, 0.42, 0.18], 0.5);
                $m->ball([cos($a) * $d, $size * 0.6, sin($a) * $d], [$size, $size * 0.85, $size], $leaf, 7, 4, [0, 0, 0],
                    Mesh::times($grass, 1.2), 0.15, $i);
            }
        }
        return $m;
    }

    /** Where foe $i of $n walks at time $t, round the towers: [x, z, yaw] (the yaw of World3d::draw()). @return array{0: float, 1: float, 2: float} */
    public static function walker(int $i, int $n, float $t): array
    {
        $r = self::RADIUS * 0.72;
        $a = -$t * 0.32 + $i * 2 * M_PI / max(1, $n);
        $x = cos($a) * $r;
        $z = sin($a) * $r * 0.8;
        // it goes clockwise seen from above: its heading is the tangent
        $dx = sin($a);
        $dz = -cos($a) * 0.8;
        return [$x, $z, -atan2($dz, $dx)];
    }
}
