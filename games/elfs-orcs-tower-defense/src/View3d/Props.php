<?php

namespace App\View3d;

/**
 * The small things of the battle in 3D: projectiles (each facing +x, about its own length), walls and traps on the
 * road, the mound of an enemy under the road, and the plain shapes (disc, ring, ball) used for shadows, ranges,
 * blasts and glows.
 */
final class Props
{
    /** A flat disc of radius 1 facing up, white (tinted when drawn). */
    public static function disc(): Mesh
    {
        return (new Mesh())->cyl([0, 0, 0], 1.0, 1.0, 0.02, [1.0, 1.0, 1.0], 20);
    }

    /** A soft shadow of radius 1 (material SOFT: the shader fades it out towards its edge). */
    public static function softDisc(): Mesh
    {
        return (new Mesh())->material(Mesh::SOFT)->cyl([0, 0, 0], 1.0, 1.0, 0.01, [1.0, 1.0, 1.0], 20);
    }

    /** A flat ring of outer radius 1, inner 0.93. */
    public static function ring(): Mesh
    {
        $m = new Mesh();
        $w = [1.0, 1.0, 1.0];
        for ($i = 0; $i < 40; $i++) {
            $a = 2 * M_PI * $i / 40;
            $b = 2 * M_PI * ($i + 1) / 40;
            $m->quad([[cos($a) * 0.93, 0.0, sin($a) * 0.93], [cos($a), 0.0, sin($a)], [cos($b), 0.0, sin($b)], [cos($b) * 0.93, 0.0, sin($b) * 0.93]],
                [0, 1, 0], [$w, $w, $w, $w]);
        }
        return $m;
    }

    /** A ball of radius 1. */
    public static function ball(): Mesh
    {
        return (new Mesh())->ball([0, 0, 0], [1.0, 1.0, 1.0], [1.0, 1.0, 1.0], 8, 5);
    }

    /** What flies: a model by kind (arrow bolt javelin stone rock pitch spark orb note star). */
    public static function projectile(string $kind): Mesh
    {
        $m = new Mesh();
        $wood = Mesh::rgb('#d8c8a0');
        switch ($kind) {
            case 'bolt':
                $m->box([0, 0, 0], [5.0, 0.8, 0.8], Mesh::rgb('#8a8a94'));
                $m->cyl([2.5, 0, 0], 0.9, 0.0, 2.0, Mesh::rgb('#d0d0d8'), 4, [0, 0, -M_PI / 2]);
                break;
            case 'javelin':
                $m->box([0, 0, 0], [8.0, 0.7, 0.7], Mesh::rgb('#9a7040'));
                $m->cyl([4.0, 0, 0], 0.9, 0.0, 2.6, Mesh::rgb('#d0d0d8'), 4, [0, 0, -M_PI / 2]);
                break;
            case 'stone':
                $m->ball([0, 0, 0], [1.1, 1.1, 1.1], Mesh::rgb('#c0c0b4'), 5, 3);
                break;
            case 'rock':
                $m->ball([0, 0, 0], [2.6, 2.3, 2.6], Mesh::rgb('#7a766c'), 6, 4);
                break;
            case 'pitch':
                $m->ball([0, 0, 0], [2.2, 2.2, 2.2], Mesh::rgb('#241c24'), 6, 4);
                $m->ball([0, 1.4, 0], [1.3, 1.6, 1.3], Mesh::rgb('#ff8a20', 1.4), 5, 3);
                break;
            case 'spark':
                $m->ball([0, 0, 0], [1.6, 1.6, 1.6], Mesh::rgb('#ffa030', 1.5), 5, 3);
                break;
            case 'orb':
                $m->ball([0, 0, 0], [2.0, 2.0, 2.0], Mesh::rgb('#9ab8ff', 1.4), 6, 4);
                $m->ball([0, 0, 0], [1.0, 1.0, 1.0], Mesh::rgb('#ffffff', 1.4), 5, 3);
                break;
            case 'note':
                $m->ball([0, 0, 0], [1.5, 1.3, 1.5], Mesh::rgb('#ffdc5a', 1.4), 5, 3);
                $m->box([1.0, 2.2, 0], [0.5, 3.6, 0.5], Mesh::rgb('#ffdc5a', 1.4));
                break;
            case 'star':
                $m->box([0, 0, 0], [4.4, 0.8, 0.8], Mesh::rgb('#fff08c', 1.5));
                $m->box([0, 0, 0], [0.8, 4.4, 0.8], Mesh::rgb('#fff08c', 1.5));
                $m->box([0, 0, 0], [0.8, 0.8, 4.4], Mesh::rgb('#fff08c', 1.5));
                break;
            default: // arrow
                $m->box([0, 0, 0], [6.0, 0.5, 0.5], $wood);
                $m->cyl([3.0, 0, 0], 0.8, 0.0, 1.8, Mesh::rgb('#d0d0d8'), 4, [0, 0, -M_PI / 2]);
                $m->box([-2.8, 0, 0], [1.4, 0.2, 1.6], Mesh::rgb('#e8e0c8'));
        }
        return $m;
    }

    /** A wall of stakes across the road, facing +x (it lies along z). $stone: a stone rampart. */
    public static function wall(bool $stone, bool $standing, bool $spiked): Mesh
    {
        $m = new Mesh();
        $wood = Mesh::rgb('#9a7040');
        $dark = Mesh::rgb('#6a4a26');
        $rock = Mesh::rgb('#9a9a92');
        if (!$standing) {
            foreach ([-10.0, -4.0, 3.0, 9.0] as $z) {
                $m->slab([0, 0, $z], [2.0, 2.4, 1.8], $dark, [0, 0, 0.3]);
            }
            return $m;
        }
        if ($stone) {
            $m->slab([0, 0, 0], [5.0, 12.0, 28.0], $rock);
            for ($z = -12.0; $z <= 12.0; $z += 6.0) {
                $m->slab([0, 12.0, $z], [5.4, 2.4, 3.4], Mesh::rgb('#7a7a74'));
            }
            return $m;
        }
        foreach ([-12.0, -8.0, -4.0, 0.0, 4.0, 8.0, 12.0] as $i => $z) {
            $h = 12.0 + ($i % 2) * 2.0;
            $m->cyl([0, 0, $z], 1.7, 1.5, $h, $i % 2 ? $dark : $wood, 5);
            $m->cyl([0, $h, $z], 1.7, 0.0, 3.4, $wood, 5);
        }
        $m->slab([1.6, 4.0, 0], [0.8, 1.4, 27.0], $dark);
        if ($spiked) {
            foreach ([-10.0, -3.0, 4.0, 10.0] as $z) {
                $m->cyl([2.6, 6.0, $z], 0.8, 0.0, 4.4, Mesh::rgb('#b8b8c0'), 4, [0, 0, -M_PI / 2]);
            }
        }
        return $m;
    }

    /** A trap on the road: open jaws (armed) or a closed plate. */
    public static function trap(bool $armed): Mesh
    {
        $m = new Mesh();
        $iron = Mesh::rgb($armed ? '#b0b0b8' : '#5a5048');
        $m->slab([0, 0, 0], [9.0, 0.7, 7.0], Mesh::rgb('#4a4038'));
        if ($armed) {
            foreach ([-1, 1] as $side) {
                for ($i = -3; $i <= 3; $i += 2) {
                    $m->cyl([$i * 1.2, 0.7, $side * 2.6], 0.55, 0.0, 2.4, $iron, 4, [0, 0, 0.0]);
                }
            }
        }
        return $m;
    }

    /** The earth that moves above a tunnelling enemy. */
    public static function mound(): Mesh
    {
        $m = new Mesh();
        $m->ball([0, 0.4, 0], [5.0, 2.8, 4.2], Mesh::rgb('#8a6444'), 6, 3);
        $m->ball([-3.0, 0.3, 1.5], [2.4, 1.4, 2.0], Mesh::rgb('#a07a54'), 5, 3);
        return $m;
    }
}
