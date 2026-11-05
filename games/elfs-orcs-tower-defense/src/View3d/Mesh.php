<?php

namespace App\View3d;

/**
 * A triangle mesh built in code: boxes, cylinders / cones, balls, triangles, flat quads, merged into one list of
 * vertices (x y z, normal x y z, colour r g b, material: 10 floats each, triangles not indexed). One mesh = one
 * buffer = one draw call.
 *
 * Axes: x and z are the map's x and y, y is up. Every primitive can be scaled, rotated (radians, applied roll z,
 * then pitch x, then yaw y) and moved: $pos is the centre of a box or a ball, the centre of the base of a cylinder.
 *
 * The material ($mat, set before adding) tells the shader how to paint a vertex: SOLID, a number in (0, 1] for
 * leaves, grass and cloth that move in the wind (how much), GROUND for the painted ground, SKY for the sky of the
 * menus' vignettes, MOTE + a seed (0..1) for the specks of light that float over the map, SOFT for the soft shadows.
 */
final class Mesh
{
    public const STRIDE = 10;

    public const SOLID = 0.0;
    public const LEAVES = 0.35;
    public const GRASS = 1.0;
    public const GROUND = 2.0;
    /** the sky behind a vignette (World3d::vignette()): its colours as they are, clouds from the noise */
    public const SKY = 3.0;
    public const MOTE = 4.0;
    public const SOFT = 6.0;

    /** @var list<float> */
    public array $v = [];

    /** the material of what is added next */
    public float $mat = self::SOLID;

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

    /** A colour times $k. @return array{0: float, 1: float, 2: float} */
    public static function times(array $c, float $k): array
    {
        return [$c[0] * $k, $c[1] * $k, $c[2] * $k];
    }

    /** A number 0..1 from integers (the same each time): for scattering things without randomness. */
    public static function hash(int ...$n): float
    {
        // 16 bits: crc32() is signed (and its products overflow) in the browser's 32-bit PHP
        return (crc32(implode(',', $n)) & 0xffff) / 65536;
    }

    public function vertexCount(): int
    {
        return intdiv(count($this->v), self::STRIDE);
    }

    /** Sets the material of what is added next. */
    public function material(float $mat): self
    {
        $this->mat = $mat;
        return $this;
    }

    /**
     * What is added next moves with bone $i of a creature's skeleton (Skeleton: the shader poses it with u_bones);
     * $metal: it shines like metal. Encoded in the material: BONE + $i, METAL_BONE + $i.
     */
    public function bone(int $i, bool $metal = false): self
    {
        $this->mat = ($metal ? self::METAL_BONE : self::BONE) + $i;
        return $this;
    }

    /** Whether what is added next shines (keeps the bone). */
    public function metal(bool $on): self
    {
        if ($this->mat >= self::BONE) {
            $this->mat = ($on ? self::METAL_BONE : self::BONE) + fmod($this->mat - self::BONE, self::METAL_BONE - self::BONE);
        }
        return $this;
    }

    /** materials of posed vertices: BONE + bone (0 .. 15), METAL_BONE + bone for shining ones */
    public const BONE = 10.0;
    public const METAL_BONE = 30.0;

    // ---- sculpting: smooth surfaces ---------------------------------------------------------------------------

    /**
     * A smooth closed surface round an axis, through rings: [along, rx, rz, dx, dz] each (the distance along the
     * axis, the half-sizes of the ellipse across it: rx towards local +x, rz towards local +z, and how far its middle
     * is moved: dx, dz). Between two given rings $rows rows are added on a smooth curve. The axis is local +y,
     * from $pos, turned by $rot (as box()); or give $to (a point): the axis goes from $pos to $to, rings' "along"
     * are then 0..1. $col: one colour, or callable(float $t 0..1 along, float $a 0..2pi round from +x towards +z):
     * colour, for painted details. $shape: callable(float $t, float $a): float, a factor on the radius (pleats,
     * muscles). Normals are those of the surface (smooth); rings of radius 0 at the ends close it.
     * @param list<array<int, float>> $rings
     */
    public function lathe(array $rings, array|callable $col, int $seg = 12, array $pos = [0, 0, 0], array $rot = [0, 0, 0],
                          ?array $to = null, int $rows = 2, ?callable $shape = null): self
    {
        $n = count($rings);
        if ($n < 2) {
            return $this;
        }
        // the rings on a smooth curve (Catmull-Rom through the given ones)
        $dense = [];
        for ($i = 0; $i < $n - 1; $i++) {
            $p0 = $rings[max(0, $i - 1)];
            $p1 = $rings[$i];
            $p2 = $rings[$i + 1];
            $p3 = $rings[min($n - 1, $i + 2)];
            for ($k = 0; $k < $rows; $k++) {
                $t = $k / $rows;
                $ring = [];
                for ($c = 0; $c < 5; $c++) {
                    $a = (float)($p0[$c] ?? 0.0);
                    $b = (float)($p1[$c] ?? 0.0);
                    $cc = (float)($p2[$c] ?? 0.0);
                    $d = (float)($p3[$c] ?? 0.0);
                    $v = 0.5 * (2 * $b + (-$a + $cc) * $t + (2 * $a - 5 * $b + 4 * $cc - $d) * $t * $t + (-$a + 3 * $b - 3 * $cc + $d) * $t * $t * $t);
                    // radii never cross zero between two rings that do not
                    $ring[] = ($c === 1 || $c === 2) ? max(0.0, $v) : $v;
                }
                $dense[] = $ring;
            }
        }
        $dense[] = array_map('floatval', $rings[$n - 1] + [0.0, 0.0, 0.0, 0.0, 0.0]);
        $first = (float)$rings[0][0];
        $last = (float)$rings[$n - 1][0];
        $span = ($last - $first) ?: 1.0;

        // the frame of the axis
        if ($to !== null) {
            $axis = [$to[0] - $pos[0], $to[1] - $pos[1], $to[2] - $pos[2]];
            $len = sqrt($axis[0] ** 2 + $axis[1] ** 2 + $axis[2] ** 2) ?: 1.0;
            $u = [$axis[0] / $len, $axis[1] / $len, $axis[2] / $len];
            // local x: the world's +x made square to the axis (or +y when the axis is along x)
            $ref = abs($u[0]) > 0.9 ? [0.0, 1.0, 0.0] : [1.0, 0.0, 0.0];
            $dot = $ref[0] * $u[0] + $ref[1] * $u[1] + $ref[2] * $u[2];
            $ex = [$ref[0] - $dot * $u[0], $ref[1] - $dot * $u[1], $ref[2] - $dot * $u[2]];
            $el = sqrt($ex[0] ** 2 + $ex[1] ** 2 + $ex[2] ** 2) ?: 1.0;
            $ex = [$ex[0] / $el, $ex[1] / $el, $ex[2] / $el];
            $ez = [$ex[1] * $u[2] - $ex[2] * $u[1], $ex[2] * $u[0] - $ex[0] * $u[2], $ex[0] * $u[1] - $ex[1] * $u[0]];
            $place = static fn (float $along, float $x, float $z): array => [
                $pos[0] + $u[0] * $along * $len + $ex[0] * $x + $ez[0] * $z,
                $pos[1] + $u[1] * $along * $len + $ex[1] * $x + $ez[1] * $z,
                $pos[2] + $u[2] * $along * $len + $ex[2] * $x + $ez[2] * $z,
            ];
        } else {
            $xf = $this->xf($pos, [1, 1, 1], $rot);
            $m = $xf['m'];
            $place = static fn (float $along, float $x, float $z): array => [
                $m[0][0] * $x + $m[0][1] * $along + $m[0][2] * $z + $pos[0],
                $m[1][0] * $x + $m[1][1] * $along + $m[1][2] * $z + $pos[1],
                $m[2][0] * $x + $m[2][1] * $along + $m[2][2] * $z + $pos[2],
            ];
        }

        // the grid of points (rows x seg + 1, the seam repeated), their colours
        $grid = [];
        $cols = [];
        $centres = [];
        foreach ($dense as $r => [$along, $rx, $rz, $dx, $dz]) {
            $t = ($along - $first) / $span;
            $centres[$r] = $place($along, $dx, $dz);
            for ($s = 0; $s <= $seg; $s++) {
                $a = 2 * M_PI * $s / $seg;
                $f = $shape !== null ? $shape($t, $a) : 1.0;
                $grid[$r][$s] = $place($along, $dx + cos($a) * $rx * $f, $dz + sin($a) * $rz * $f);
                $cols[$r][$s] = is_callable($col) ? $col($t, $a) : $col;
            }
        }
        $this->grid($grid, $cols, $centres);
        return $this;
    }

    /**
     * A limb from $a to $b: round, its radius along it from the profile [[t 0..1, radius], ...] (a bulge, a joint),
     * smooth (its normals; one row per ring of the profile, $rows to round it more). $col as lathe(). $flat: its
     * section squashed across (1: round).
     * @param list<array{0: float, 1: float}> $profile
     */
    public function limb(array $a, array $b, array $profile, array|callable $col, int $seg = 8, float $flat = 1.0, int $rows = 1): self
    {
        $rings = [];
        foreach ($profile as [$t, $r]) {
            $rings[] = [$t, $r, $r * $flat, 0.0, 0.0];
        }
        return $this->lathe($rings, $col, $seg, $a, [0, 0, 0], $b, $rows);
    }

    /**
     * A flat shape with a thickness (a blade, an axe head, a shield plate): the polygon $poly (points [u, v] in its
     * own plane, in order, convex or star-shaped round its first point's... centre) laid on the plane through $o
     * spanned by $eu and $ev (unit vectors), $thick through it; its edge bevelled a little. $col, $edge colours.
     * @param list<array{0: float, 1: float}> $poly
     */
    public function plate(array $poly, array $o, array $eu, array $ev, float $thick, array $col, ?array $edge = null): self
    {
        $n = count($poly);
        if ($n < 3) {
            return $this;
        }
        $en = [$eu[1] * $ev[2] - $eu[2] * $ev[1], $eu[2] * $ev[0] - $eu[0] * $ev[2], $eu[0] * $ev[1] - $eu[1] * $ev[0]];
        $edge ??= Mesh::times($col, 0.8);
        $cu = 0.0;
        $cv = 0.0;
        foreach ($poly as [$u, $v]) {
            $cu += $u / $n;
            $cv += $v / $n;
        }
        $at = static fn (float $u, float $v, float $w): array => [
            $o[0] + $eu[0] * $u + $ev[0] * $v + $en[0] * $w,
            $o[1] + $eu[1] * $u + $ev[1] * $v + $en[1] * $w,
            $o[2] + $eu[2] * $u + $ev[2] * $v + $en[2] * $w,
        ];
        $x = self::identity();
        $h = $thick / 2;
        // the two faces: a fan from the middle (its ridge a little thicker: the bevel)
        foreach ([1, -1] as $side) {
            $mid = $at($cu, $cv, $side * $h * 1.6);
            $nrm = [$en[0] * $side, $en[1] * $side, $en[2] * $side];
            for ($i = 0; $i < $n; $i++) {
                [$u0, $v0] = $poly[$i];
                [$u1, $v1] = $poly[($i + 1) % $n];
                $p0 = $at($u0, $v0, $side * $h * 0.4);
                $p1 = $at($u1, $v1, $side * $h * 0.4);
                if ($side > 0) {
                    $this->emit($mid, $nrm, Mesh::times($col, 1.08), $x);
                    $this->emit($p0, $nrm, $col, $x);
                    $this->emit($p1, $nrm, $col, $x);
                } else {
                    $this->emit($mid, $nrm, Mesh::times($col, 1.08), $x);
                    $this->emit($p1, $nrm, $col, $x);
                    $this->emit($p0, $nrm, $col, $x);
                }
            }
        }
        // the rim
        for ($i = 0; $i < $n; $i++) {
            [$u0, $v0] = $poly[$i];
            [$u1, $v1] = $poly[($i + 1) % $n];
            $du = $u1 - $u0;
            $dv = $v1 - $v0;
            $l = sqrt($du * $du + $dv * $dv) ?: 1.0;
            // outwards in the plane: the edge's normal, away from the middle
            $nu = $dv / $l;
            $nv = -$du / $l;
            if (($u0 - $cu) * $nu + ($v0 - $cv) * $nv < 0) {
                [$nu, $nv] = [-$nu, -$nv];
            }
            $nrm = [$eu[0] * $nu + $ev[0] * $nv, $eu[1] * $nu + $ev[1] * $nv, $eu[2] * $nu + $ev[2] * $nv];
            $a = $at($u0, $v0, $h * 0.4);
            $b = $at($u1, $v1, $h * 0.4);
            $c = $at($u1, $v1, -$h * 0.4);
            $d = $at($u0, $v0, -$h * 0.4);
            foreach ([[$a, $b, $c], [$a, $c, $d]] as $tri) {
                foreach ($tri as $p) {
                    $this->emit($p, $nrm, $edge, $x);
                }
            }
        }
        return $this;
    }

    /**
     * A smooth open surface (a cape, a wing's skin, a mane): a grid of points, rows of columns, a colour per point
     * (or one, or callable(float $row 0..1, float $col 0..1)); its normals point away from $inside.
     * @param list<list<array{0: float, 1: float, 2: float}>> $grid
     */
    public function sheet(array $grid, array|callable $col, array $inside): self
    {
        $rows = count($grid);
        if ($rows < 2 || count($grid[0]) < 2) {
            return $this;
        }
        $cols = [];
        $n = count($grid[0]);
        foreach ($grid as $r => $row) {
            foreach ($row as $s => $p) {
                $cols[$r][$s] = is_callable($col) ? $col($r / ($rows - 1), $s / ($n - 1)) : $col;
            }
        }
        $this->grid($grid, $cols, array_fill(0, $rows, $inside), false);
        return $this;
    }

    /**
     * Triangles of a grid of points (rows of points: closed round, the last point repeating the first, or open),
     * smooth normals from the grid, pointing away from each row's centre. @param list<list<array{0: float, 1: float, 2: float}>> $grid
     * @param list<list<array{0: float, 1: float, 2: float}>> $cols @param list<array{0: float, 1: float, 2: float}> $centres
     */
    private function grid(array $grid, array $cols, array $centres, bool $closed = true): void
    {
        $rows = count($grid);
        $count = count($grid[0]);
        $seg = $closed ? $count - 1 : $count;
        $normals = [];
        for ($r = 0; $r < $rows; $r++) {
            for ($s = 0; $s < $seg; $s++) {
                $p = $grid[$r][$s];
                $right = $closed ? $grid[$r][($s + 1) % $seg] : $grid[$r][min($count - 1, $s + 1)];
                $left = $closed ? $grid[$r][($s - 1 + $seg) % $seg] : $grid[$r][max(0, $s - 1)];
                $up = $grid[min($rows - 1, $r + 1)][$s];
                $down = $grid[max(0, $r - 1)][$s];
                $tu = [$right[0] - $left[0], $right[1] - $left[1], $right[2] - $left[2]];
                $tv = [$up[0] - $down[0], $up[1] - $down[1], $up[2] - $down[2]];
                $nx = $tu[1] * $tv[2] - $tu[2] * $tv[1];
                $ny = $tu[2] * $tv[0] - $tu[0] * $tv[2];
                $nz = $tu[0] * $tv[1] - $tu[1] * $tv[0];
                $len = sqrt($nx * $nx + $ny * $ny + $nz * $nz);
                $c = $centres[$r];
                $out = [$p[0] - $c[0], $p[1] - $c[1], $p[2] - $c[2]];
                if ($len < 1e-6) {
                    // a closed end: the normal along the axis, outwards
                    $other = $centres[$r === 0 ? min(1, $rows - 1) : $r - 1];
                    $out = [$c[0] - $other[0], $c[1] - $other[1], $c[2] - $other[2]];
                    $ol = sqrt($out[0] ** 2 + $out[1] ** 2 + $out[2] ** 2);
                    $normals[$r][$s] = $ol > 1e-9 ? [$out[0] / $ol, $out[1] / $ol, $out[2] / $ol] : [0.0, 1.0, 0.0];
                    continue;
                }
                if ($nx * $out[0] + $ny * $out[1] + $nz * $out[2] < 0) {
                    [$nx, $ny, $nz] = [-$nx, -$ny, -$nz];
                }
                $normals[$r][$s] = [$nx / $len, $ny / $len, $nz / $len];
            }
            if ($closed) {
                $normals[$r][$seg] = $normals[$r][0];
            }
        }
        $x = self::identity();
        for ($r = 0; $r < $rows - 1; $r++) {
            for ($s = 0; $s < $count - 1; $s++) {
                $quad = [[$r, $s], [$r, $s + 1], [$r + 1, $s + 1], [$r + 1, $s]];
                $p = [$grid[$r][$s], $grid[$r][$s + 1], $grid[$r + 1][$s + 1], $grid[$r + 1][$s]];
                // the quad's facing from its diagonals (sound even when one of its edges is a point), against
                // the normals of its corners: the order that faces outwards
                $d1 = [$p[2][0] - $p[0][0], $p[2][1] - $p[0][1], $p[2][2] - $p[0][2]];
                $d2 = [$p[3][0] - $p[1][0], $p[3][1] - $p[1][1], $p[3][2] - $p[1][2]];
                $fn = [$d1[1] * $d2[2] - $d1[2] * $d2[1], $d1[2] * $d2[0] - $d1[0] * $d2[2], $d1[0] * $d2[1] - $d1[1] * $d2[0]];
                $nm = [0.0, 0.0, 0.0];
                foreach ($quad as [$rr, $ss]) {
                    $nm[0] += $normals[$rr][$ss][0];
                    $nm[1] += $normals[$rr][$ss][1];
                    $nm[2] += $normals[$rr][$ss][2];
                }
                // the diagonals' cross product points the way the order 0 1 2 3 faces: reversed when inwards
                $flip = $fn[0] * $nm[0] + $fn[1] * $nm[1] + $fn[2] * $nm[2] < 0;
                foreach ($flip ? [[0, 2, 1], [0, 3, 2]] : [[0, 1, 2], [0, 2, 3]] as $tri) {
                    [$i0, $i1, $i2] = $tri;
                    // no triangle where the row is a point
                    $e1 = [$p[$i1][0] - $p[$i0][0], $p[$i1][1] - $p[$i0][1], $p[$i1][2] - $p[$i0][2]];
                    $e2 = [$p[$i2][0] - $p[$i0][0], $p[$i2][1] - $p[$i0][1], $p[$i2][2] - $p[$i0][2]];
                    $area = ($e1[1] * $e2[2] - $e1[2] * $e2[1]) ** 2 + ($e1[2] * $e2[0] - $e1[0] * $e2[2]) ** 2 + ($e1[0] * $e2[1] - $e1[1] * $e2[0]) ** 2;
                    if ($area < 1e-12) {
                        continue;
                    }
                    foreach ($tri as $k) {
                        [$rr, $ss] = $quad[$k];
                        $this->emit($grid[$rr][$ss], $normals[$rr][$ss], $cols[$rr][$ss], $x);
                    }
                }
            }
        }
    }

    // ---- primitives -------------------------------------------------------------------------------------------

    /** A box of size $size centred on $pos; $top: the colour of its upper half (a gradient), else $col. */
    public function box(array $pos, array $size, array $col, array $rot = [0, 0, 0], ?array $top = null): self
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
                    foreach ([$a, $b, $c] as $k) {
                        $this->emit($quad[$k], $n, $top !== null && $quad[$k][1] > 0 ? $top : $col, $x);
                    }
                }
            }
        }
        return $this;
    }

    /** A box standing on $pos (the middle of its bottom face). */
    public function slab(array $pos, array $size, array $col, array $rot = [0, 0, 0], ?array $top = null): self
    {
        return $this->box([$pos[0], $pos[1] + $size[1] / 2, $pos[2]], $size, $col, $rot, $top);
    }

    /**
     * A cylinder from radius $rb at the base to $rt at the top (0: a cone), $h tall; $top: the colour at the top
     * (a gradient from $col at the base), $cap: a lid on top, $bottom: a lid under it too.
     */
    public function cyl(array $pos, float $rb, float $rt, float $h, array $col, int $seg = 8, array $rot = [0, 0, 0], bool $cap = true,
                        ?array $top = null, bool $bottom = false): self
    {
        $x = $this->xf($pos, [1, 1, 1], $rot);
        $slope = $h > 0 ? ($rb - $rt) / $h : 0.0;
        $top ??= $col;
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
            $this->emit($p11, $n1, $top, $x);
            $this->emit($p00, $n0, $col, $x);
            $this->emit($p11, $n1, $top, $x);
            $this->emit($p01, $n0, $top, $x);
            if ($cap && $rt > 0.001) {
                $up = [0, 1, 0];
                $this->emit([0.0, $h, 0.0], $up, $top, $x);
                $this->emit($p01, $up, $top, $x);
                $this->emit($p11, $up, $top, $x);
            }
            if ($bottom && $rb > 0.001) {
                $down = [0, -1, 0];
                $this->emit([0.0, 0.0, 0.0], $down, $col, $x);
                $this->emit($p10, $down, $col, $x);
                $this->emit($p00, $down, $col, $x);
            }
        }
        return $this;
    }

    /**
     * An ellipsoid with radii $r, centred on $pos. $top: the colour of its upper side (a gradient from $col below:
     * leaves lit from above), $lump: how bumpy (0 = smooth; a canopy of leaves: 0.15), $seed: which bumps.
     */
    public function ball(array $pos, array $r, array $col, int $seg = 6, int $rings = 4, array $rot = [0, 0, 0], ?array $top = null,
                         float $lump = 0.0, int $seed = 0): self
    {
        $x = $this->xf($pos, $r, $rot);
        $s = $seed * 1.7;
        $pt = static function (float $phi, float $th) use ($lump, $s): array {
            $d = [sin($phi) * cos($th), cos($phi), sin($phi) * sin($th)];
            if ($lump > 0) {
                $k = 1 + $lump * (sin($d[0] * 3.1 + $s) * sin($d[1] * 2.7 + $s * 1.3) + 0.6 * sin($d[2] * 4.3 - $d[0] * 2.1 + $s * 0.7));
                $d = [$d[0] * $k, $d[1] * $k, $d[2] * $k];
            }
            return $d;
        };
        $colour = static function (array $p) use ($col, $top): array {
            if ($top === null) {
                return $col;
            }
            $t = max(0.0, min(1.0, $p[1] * 0.6 + 0.5));
            return Mesh::mix($col, $top, $t * $t * (3 - 2 * $t));
        };
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
                        $this->emit($p, $p, $colour($p), $x);
                    }
                }
                if ($j < $rings - 1) {
                    foreach ([$a, $c, $d] as $p) {
                        $this->emit($p, $p, $colour($p), $x);
                    }
                }
            }
        }
        return $this;
    }

    /** One triangle with its normal and a colour per corner (map coordinates, no transform). */
    public function tri(array $a, array $b, array $c, array $n, array $ca, array $cb, array $cc): self
    {
        $x = self::identity();
        $this->emit($a, $n, $ca, $x);
        $this->emit($b, $n, $cb, $x);
        $this->emit($c, $n, $cc, $x);
        return $this;
    }

    /** A flat quad facing up at height $y: corners ($x0, $z0) to ($x1, $z1). */
    public function floor(float $x0, float $z0, float $x1, float $z1, float $y, array $col): self
    {
        return $this->quad([[$x0, $y, $z0], [$x1, $y, $z0], [$x1, $y, $z1], [$x0, $y, $z1]], [0, 1, 0], [$col, $col, $col, $col]);
    }

    /**
     * A quad of 4 corners (in order) with its normal (or one normal per corner) and one colour per corner.
     * @param array<int, array{0: float, 1: float, 2: float}> $p
     * @param array<int, array{0: float, 1: float, 2: float}> $cols
     */
    public function quad(array $p, array $n, array $cols): self
    {
        $x = self::identity();
        $normals = is_array($n[0]) ? $n : [$n, $n, $n, $n];
        foreach ([[0, 1, 2], [0, 2, 3]] as $tri) {
            foreach ($tri as $k) {
                $this->emit($p[$k], $normals[$k], $cols[$k], $x);
            }
        }
        return $this;
    }

    // ---- composition ------------------------------------------------------------------------------------------

    /** Another mesh, scaled, turned about y, moved and its colours times $tint, added to this one. */
    public function add(self $other, array $t = [0, 0, 0], float $scale = 1.0, float $yaw = 0.0, ?array $tint = null): self
    {
        [$c, $s] = [cos($yaw), sin($yaw)];
        [$tr, $tg, $tb] = $tint ?? [1.0, 1.0, 1.0];
        $v = $other->v;
        for ($i = 0, $n = count($v); $i < $n; $i += self::STRIDE) {
            $x = $v[$i] * $scale;
            $y = $v[$i + 1] * $scale;
            $z = $v[$i + 2] * $scale;
            $nx = $v[$i + 3];
            $nz = $v[$i + 5];
            array_push($this->v, $x * $c + $z * $s + $t[0], $y + $t[1], -$x * $s + $z * $c + $t[2],
                $nx * $c + $nz * $s, $v[$i + 4], -$nx * $s + $nz * $c, $v[$i + 6] * $tr, $v[$i + 7] * $tg, $v[$i + 8] * $tb, $v[$i + 9]);
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
    private static function identity(): array
    {
        return ['m' => [[1, 0, 0], [0, 1, 0], [0, 0, 1]], 's' => [1, 1, 1], 'inv' => [1, 1, 1], 't' => [0, 0, 0]];
    }

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
            $nx / $len, $ny / $len, $nz / $len, $col[0], $col[1], $col[2], $this->mat);
    }
}
