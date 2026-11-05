<?php

namespace App\View3d;

/**
 * The bones of a creature: each turns about its joint (its pivot, in the model's rest pose), carrying its children.
 * A model's vertices belong to one bone each (Mesh::bone()); the shader moves them with the bone's matrix
 * (World3d: u_bones). A pose gives each bone's turn [rx, ry, rz] (radians: about x, y, z, applied z, then x, then y,
 * as Mesh's own rotations) and may move the root; matrices() gives the 16 floats (column-major) per bone. Pure.
 *
 * Model axes: the creature faces +x, y is up, its right is +z. A turn about z by a positive angle swings a hanging
 * limb forwards (towards +x), and leans what stands up backwards.
 */
final class Skeleton
{
    public const MAX = 16;

    /**
     * @param list<array{0: int, 1: array{0: float, 1: float, 2: float}}> $bones [parent (-1: the root), pivot] each,
     *        parents listed before their children
     */
    public function __construct(public readonly array $bones)
    {
        if (count($bones) > self::MAX) {
            throw new \LengthException('a skeleton has at most ' . self::MAX . ' bones');
        }
    }

    public function count(): int
    {
        return count($this->bones);
    }

    /** A bone's pivot in the rest pose. @return array{0: float, 1: float, 2: float} */
    public function pivot(int $bone): array
    {
        return $this->bones[$bone][1];
    }

    /**
     * The matrices of a pose: one per bone, in order (16 floats each, column-major), then identities up to MAX
     * (the shader's array is whole).
     * @param array<int, array{0: float, 1: float, 2: float}> $turns bone => [rx, ry, rz] (missing: not turned)
     * @param array{0: float, 1: float, 2: float} $move the root moved by this (a bob, a lunge)
     * @param array<int, float> $stretch bone => a scale about its pivot (a breath, a squash), 1 if missing
     * @return list<float>
     */
    public function matrices(array $turns, array $move = [0.0, 0.0, 0.0], array $stretch = []): array
    {
        $global = [];
        $out = [];
        foreach ($this->bones as $i => [$parent, $p]) {
            [$rx, $ry, $rz] = $turns[$i] ?? [0.0, 0.0, 0.0];
            $r = self::rotation((float)$rx, (float)$ry, (float)$rz);
            $k = $stretch[$i] ?? 1.0;
            if ($k != 1.0) {
                foreach ($r as $row => $cols) {
                    $r[$row] = [$cols[0] * $k, $cols[1] * $k, $cols[2] * $k];
                }
            }
            // about the pivot: T(p) R T(-p) = [R, p - R p]
            $t = [
                $p[0] - ($r[0][0] * $p[0] + $r[0][1] * $p[1] + $r[0][2] * $p[2]),
                $p[1] - ($r[1][0] * $p[0] + $r[1][1] * $p[1] + $r[1][2] * $p[2]),
                $p[2] - ($r[2][0] * $p[0] + $r[2][1] * $p[1] + $r[2][2] * $p[2]),
            ];
            if ($parent < 0) {
                $t = [$t[0] + $move[0], $t[1] + $move[1], $t[2] + $move[2]];
                $g = [$r, $t];
            } else {
                [$pr, $pt] = $global[$parent];
                $g = [self::mul($pr, $r), [
                    $pr[0][0] * $t[0] + $pr[0][1] * $t[1] + $pr[0][2] * $t[2] + $pt[0],
                    $pr[1][0] * $t[0] + $pr[1][1] * $t[1] + $pr[1][2] * $t[2] + $pt[1],
                    $pr[2][0] * $t[0] + $pr[2][1] * $t[1] + $pr[2][2] * $t[2] + $pt[2],
                ]];
            }
            $global[$i] = $g;
            [$m, $tt] = $g;
            array_push($out, $m[0][0], $m[1][0], $m[2][0], 0.0, $m[0][1], $m[1][1], $m[2][1], 0.0,
                $m[0][2], $m[1][2], $m[2][2], 0.0, $tt[0], $tt[1], $tt[2], 1.0);
        }
        for ($i = count($this->bones); $i < self::MAX; $i++) {
            array_push($out, 1.0, 0.0, 0.0, 0.0, 0.0, 1.0, 0.0, 0.0, 0.0, 0.0, 1.0, 0.0, 0.0, 0.0, 0.0, 1.0);
        }
        return $out;
    }

    /** Where a point of bone $bone (rest pose) goes in a pose's matrices. @param list<float> $m @return array{0: float, 1: float, 2: float} */
    public static function apply(array $m, int $bone, array $p): array
    {
        $o = $bone * 16;
        return [
            $m[$o] * $p[0] + $m[$o + 4] * $p[1] + $m[$o + 8] * $p[2] + $m[$o + 12],
            $m[$o + 1] * $p[0] + $m[$o + 5] * $p[1] + $m[$o + 9] * $p[2] + $m[$o + 13],
            $m[$o + 2] * $p[0] + $m[$o + 6] * $p[1] + $m[$o + 10] * $p[2] + $m[$o + 14],
        ];
    }

    /** R = Ry Rx Rz (as Mesh's rotations). @return array{0: array{0: float, 1: float, 2: float}, 1: array{0: float, 1: float, 2: float}, 2: array{0: float, 1: float, 2: float}} */
    private static function rotation(float $rx, float $ry, float $rz): array
    {
        if ($rx == 0.0 && $ry == 0.0 && $rz == 0.0) {
            return [[1.0, 0.0, 0.0], [0.0, 1.0, 0.0], [0.0, 0.0, 1.0]];
        }
        [$cx, $sx, $cy, $sy, $cz, $sz] = [cos($rx), sin($rx), cos($ry), sin($ry), cos($rz), sin($rz)];
        // Ry * Rx
        $a = [[$cy, $sy * $sx, $sy * $cx], [0.0, $cx, -$sx], [-$sy, $cy * $sx, $cy * $cx]];
        // (Ry Rx) * Rz
        return self::mul($a, [[$cz, -$sz, 0.0], [$sz, $cz, 0.0], [0.0, 0.0, 1.0]]);
    }

    /** @return array{0: array{0: float, 1: float, 2: float}, 1: array{0: float, 1: float, 2: float}, 2: array{0: float, 1: float, 2: float}} */
    private static function mul(array $a, array $b): array
    {
        $o = [];
        for ($i = 0; $i < 3; $i++) {
            for ($j = 0; $j < 3; $j++) {
                $o[$i][$j] = $a[$i][0] * $b[0][$j] + $a[$i][1] * $b[1][$j] + $a[$i][2] * $b[2][$j];
            }
        }
        return $o;
    }
}
