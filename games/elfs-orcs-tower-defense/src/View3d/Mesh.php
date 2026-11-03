<?php

namespace App\View3d;

/**
 * A triangle mesh built in code: boxes, cylinders / cones, balls, flat quads, merged into one list of vertices
 * (x y z, normal x y z, colour r g b: 9 floats each, triangles not indexed). One mesh = one buffer = one draw call.
 *
 * Axes: x and z are the map's x and y, y is up. Every primitive can be scaled, rotated (radians, applied roll z,
 * then pitch x, then yaw y) and moved: $pos is the centre of a box or a ball, the centre of the base of a cylinder.
 */
final class Mesh
{
    public const STRIDE = 9;

    /** @var list<float> */
    public array $v = [];

    /** '#rrggbb' or '#rgb' to [r, g, b] 0..1, times $k (darker / lighter). @return array{0: float, 1: float, 2: float} */
    public static function rgb(string $hex, float $k = 1.0): array
    {
        $h = ltrim($hex, '#');
        if (strlen($h) === 3) {
            $h = $h[0] . $h[0] . $h[1] . $h[1] . $h[2] . $h[2];
        }
        return [hexdec(substr($h, 0, 2)) / 255 * $k, hexdec(substr($h, 2, 2)) / 255 * $k, hexdec(substr($h, 4, 2)) / 255 * $k];
    }

    /** A colour mixed with another: 0 = $a, 1 = $b. @return array{0: float, 1: float, 2: float} */
    public static function mix(array $a, array $b, float $t): array
    {
        return [$a[0] + ($b[0] - $a[0]) * $t, $a[1] + ($b[1] - $a[1]) * $t, $a[2] + ($b[2] - $a[2]) * $t];
    }

    public function vertexCount(): int
    {
        return intdiv(count($this->v), self::STRIDE);
    }

    // ---- primitives -------------------------------------------------------------------------------------------

    /** A box of size $size centred on $pos. */
    public function box(array $pos, array $size, array $col, array $rot = [0, 0, 0]): self
    {
        $h = [$size[0] / 2, $size[1] / 2, $size[2] / 2];
        $x = $this->xf($pos, [1, 1, 1], $rot);
        foreach ([0, 1, 2] as $axis) {
            foreach ([1, -1] as $sign) {
                $u = ($axis + 1) % 3;
                $w = ($axis + 2) % 3;
                $n = [0, 0, 0];
                $n[$axis] = $sign;
                $quad = [];
                foreach ([[-1, -1], [1, -1], [1, 1], [-1, 1]] as [$du, $dw]) {
                    $p = [0, 0, 0];
                    $p[$axis] = $sign * $h[$axis];
                    $p[$u] = $du * $h[$u];
                    $p[$w] = $dw * $h[$w];
                    $quad[] = $p;
                }
                foreach ([[0, 1, 2], [0, 2, 3]] as [$a, $b, $c]) {
                    $this->emit($quad[$a], $n, $col, $x);
                    $this->emit($quad[$b], $n, $col, $x);
                    $this->emit($quad[$c], $n, $col, $x);
                }
            }
        }
        return $this;
    }

    /** A box standing on $pos (the middle of its bottom face). */
    public function slab(array $pos, array $size, array $col, array $rot = [0, 0, 0]): self
    {
        return $this->box([$pos[0], $pos[1] + $size[1] / 2, $pos[2]], $size, $col, $rot);
    }

    /** A cylinder from radius $rb at the base to $rt at the top (0: a cone), $h tall. */
    public function cyl(array $pos, float $rb, float $rt, float $h, array $col, int $seg = 8, array $rot = [0, 0, 0], bool $cap = true): self
    {
        $x = $this->xf($pos, [1, 1, 1], $rot);
        $slope = $h > 0 ? ($rb - $rt) / $h : 0.0;
        for ($i = 0; $i < $seg; $i++) {
            $a0 = 2 * M_PI * $i / $seg;
            $a1 = 2 * M_PI * ($i + 1) / $seg;
            [$c0, $s0, $c1, $s1] = [cos($a0), sin($a0), cos($a1), sin($a1)];
            $p00 = [$rb * $c0, 0.0, $rb * $s0];
            $p10 = [$rb * $c1, 0.0, $rb * $s1];
            $p01 = [$rt * $c0, $h, $rt * $s0];
            $p11 = [$rt * $c1, $h, $rt * $s1];
            $n0 = [$c0, $slope, $s0];
            $n1 = [$c1, $slope, $s1];
            $this->emit($p00, $n0, $col, $x);
            $this->emit($p10, $n1, $col, $x);
            $this->emit($p11, $n1, $col, $x);
            $this->emit($p00, $n0, $col, $x);
            $this->emit($p11, $n1, $col, $x);
            $this->emit($p01, $n0, $col, $x);
            if ($cap && $rt > 0.001) {
                $up = [0, 1, 0];
                $this->emit([0.0, $h, 0.0], $up, $col, $x);
                $this->emit($p01, $up, $col, $x);
                $this->emit($p11, $up, $col, $x);
            }
        }
        return $this;
    }

    /** An ellipsoid with radii $r, centred on $pos. */
    public function ball(array $pos, array $r, array $col, int $seg = 6, int $rings = 4, array $rot = [0, 0, 0]): self
    {
        $x = $this->xf($pos, $r, $rot);
        $pt = static fn (float $phi, float $th): array => [sin($phi) * cos($th), cos($phi), sin($phi) * sin($th)];
        for ($j = 0; $j < $rings; $j++) {
            $p0 = M_PI * $j / $rings;
            $p1 = M_PI * ($j + 1) / $rings;
            for ($i = 0; $i < $seg; $i++) {
                $t0 = 2 * M_PI * $i / $seg;
                $t1 = 2 * M_PI * ($i + 1) / $seg;
                $a = $pt($p0, $t0);
                $b = $pt($p0, $t1);
                $c = $pt($p1, $t1);
                $d = $pt($p1, $t0);
                if ($j > 0) {
                    foreach ([$a, $b, $c] as $p) {
                        $this->emit($p, $p, $col, $x);
                    }
                }
                if ($j < $rings - 1) {
                    foreach ([$a, $c, $d] as $p) {
                        $this->emit($p, $p, $col, $x);
                    }
                }
            }
        }
        return $this;
    }

    /** A flat quad facing up at height $y: corners ($x0, $z0) to ($x1, $z1). */
    public function floor(float $x0, float $z0, float $x1, float $z1, float $y, array $col): self
    {
        return $this->quad([[$x0, $y, $z0], [$x1, $y, $z0], [$x1, $y, $z1], [$x0, $y, $z1]], [0, 1, 0], [$col, $col, $col, $col]);
    }

    /**
     * A quad of 4 corners (in order) with its normal and one colour per corner (smooth shading).
     * @param array<int, array{0: float, 1: float, 2: float}> $p
     * @param array<int, array{0: float, 1: float, 2: float}> $cols
     */
    public function quad(array $p, array $n, array $cols): self
    {
        $id = [[1, 0, 0], [0, 1, 0], [0, 0, 1]];
        $x = ['m' => $id, 's' => [1, 1, 1], 'inv' => [1, 1, 1], 't' => [0, 0, 0]];
        foreach ([[0, 1, 2], [0, 2, 3]] as $tri) {
            foreach ($tri as $k) {
                $this->emit($p[$k], $n, $cols[$k], $x);
            }
        }
        return $this;
    }

    // ---- composition ------------------------------------------------------------------------------------------

    /** Another mesh, scaled, turned about y and moved, added to this one. */
    public function add(self $other, array $t = [0, 0, 0], float $scale = 1.0, float $yaw = 0.0): self
    {
        [$c, $s] = [cos($yaw), sin($yaw)];
        $v = $other->v;
        for ($i = 0, $n = count($v); $i < $n; $i += self::STRIDE) {
            $x = $v[$i] * $scale;
            $y = $v[$i + 1] * $scale;
            $z = $v[$i + 2] * $scale;
            $nx = $v[$i + 3];
            $nz = $v[$i + 5];
            array_push($this->v, $x * $c + $z * $s + $t[0], $y + $t[1], -$x * $s + $z * $c + $t[2],
                $nx * $c + $nz * $s, $v[$i + 4], -$nx * $s + $nz * $c, $v[$i + 6], $v[$i + 7], $v[$i + 8]);
        }
        return $this;
    }

    /** Every position multiplied by $k (a bigger or smaller creature; normals stay). */
    public function scaleAll(float $k): self
    {
        for ($i = 0, $n = count($this->v); $i < $n; $i += self::STRIDE) {
            $this->v[$i] *= $k;
            $this->v[$i + 1] *= $k;
            $this->v[$i + 2] *= $k;
        }
        return $this;
    }

    // ---- internals --------------------------------------------------------------------------------------------

    /** @return array{m: array, s: array, inv: array, t: array} */
    private function xf(array $t, array $s, array $rot): array
    {
        [$rx, $ry, $rz] = $rot;
        if ($rx == 0 && $ry == 0 && $rz == 0) {
            $m = [[1, 0, 0], [0, 1, 0], [0, 0, 1]];
        } else {
            [$cx, $sx, $cy, $sy, $cz, $sz] = [cos($rx), sin($rx), cos($ry), sin($ry), cos($rz), sin($rz)];
            $mul = static function (array $a, array $b): array {
                $o = [];
                for ($i = 0; $i < 3; $i++) {
                    for ($j = 0; $j < 3; $j++) {
                        $o[$i][$j] = $a[$i][0] * $b[0][$j] + $a[$i][1] * $b[1][$j] + $a[$i][2] * $b[2][$j];
                    }
                }
                return $o;
            };
            $m = $mul($mul([[$cy, 0, $sy], [0, 1, 0], [-$sy, 0, $cy]], [[1, 0, 0], [0, $cx, -$sx], [0, $sx, $cx]]),
                [[$cz, -$sz, 0], [$sz, $cz, 0], [0, 0, 1]]);
        }
        return ['m' => $m, 's' => $s, 'inv' => [1 / ($s[0] ?: 1), 1 / ($s[1] ?: 1), 1 / ($s[2] ?: 1)], 't' => $t];
    }

    /** @param array{m: array, s: array, inv: array, t: array} $x */
    private function emit(array $p, array $n, array $col, array $x): void
    {
        $m = $x['m'];
        $px = $p[0] * $x['s'][0];
        $py = $p[1] * $x['s'][1];
        $pz = $p[2] * $x['s'][2];
        $qx = $n[0] * $x['inv'][0];
        $qy = $n[1] * $x['inv'][1];
        $qz = $n[2] * $x['inv'][2];
        $nx = $m[0][0] * $qx + $m[0][1] * $qy + $m[0][2] * $qz;
        $ny = $m[1][0] * $qx + $m[1][1] * $qy + $m[1][2] * $qz;
        $nz = $m[2][0] * $qx + $m[2][1] * $qy + $m[2][2] * $qz;
        $len = sqrt($nx * $nx + $ny * $ny + $nz * $nz) ?: 1.0;
        array_push($this->v,
            $m[0][0] * $px + $m[0][1] * $py + $m[0][2] * $pz + $x['t'][0],
            $m[1][0] * $px + $m[1][1] * $py + $m[1][2] * $pz + $x['t'][1],
            $m[2][0] * $px + $m[2][1] * $py + $m[2][2] * $pz + $x['t'][2],
            $nx / $len, $ny / $len, $nz / $len, $col[0], $col[1], $col[2]);
    }
}
