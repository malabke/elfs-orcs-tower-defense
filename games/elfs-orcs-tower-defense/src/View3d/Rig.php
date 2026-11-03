<?php

namespace App\View3d;

use JumPHP\Gl\Mat4;

/**
 * The 3D camera of the battle: it looks down at the map from the south at a fixed angle, from far enough to keep
 * every building platform (and the towers on them) in the part of the screen under the top bar. World axes: x and
 * z are the map's x and y (one unit = one pixel of the 480 x 240 play area), y is up.
 */
final class Rig implements Projection
{
    public const FOV = 24.0;
    /** the camera is always 400+ units from what it shows: a far near plane keeps a 16-bit depth buffer exact */
    public const NEAR = 180.0;
    public const FAR = 2600.0;

    /** @var array{0: float, 1: float, 2: float} */
    private array $eye = [240.0, 380.0, 330.0];
    /** @var array{0: float, 1: float, 2: float} */
    private array $target = [240.0, 0.0, 120.0];
    /** @var float[] */
    private array $vp;
    private float $tanHalf;
    private float $aspect;
    private float $shakeX = 0.0;
    private float $shakeY = 0.0;

    public function __construct(private float $w = 480.0, private float $h = 270.0)
    {
        $this->aspect = $w / $h;
        $this->tanHalf = tan(deg2rad(self::FOV) / 2);
        $this->vp = $this->matrix($this->eye, $this->target);
    }

    /**
     * Picks the closest camera that shows the box ($minX..$maxX, $minZ..$maxZ) of the map, with things up to
     * $height tall on it, between the screen rows $top and $bottom.
     */
    public function fit(float $minX, float $minZ, float $maxX, float $maxZ, float $height, float $top, float $bottom, float $pitchDeg = 58.0): void
    {
        $pitch = deg2rad($pitchDeg);
        $dir = [0.0, sin($pitch), cos($pitch)];
        $corners = [];
        foreach ([0.0, $height] as $y) {
            foreach ([[$minX, $minZ], [$maxX, $minZ], [$minX, $maxZ], [$maxX, $maxZ]] as [$x, $z]) {
                $corners[] = [$x, $y, $z];
            }
        }
        $cx = ($minX + $maxX) / 2;
        $mid = ($top + $bottom) / 2;
        $best = null;
        for ($d = 120.0; $d <= 1600.0 && $best === null; $d += 6.0) {
            $bestOff = INF;
            for ($tz = $minZ - 40.0; $tz <= $maxZ + 40.0; $tz += 8.0) {
                $target = [$cx, 0.0, $tz];
                $eye = [$cx + $dir[0] * $d, $dir[1] * $d, $tz + $dir[2] * $d];
                $vp = $this->matrix($eye, $target);
                $lo = INF;
                $hi = -INF;
                $ok = true;
                foreach ($corners as $c) {
                    $p = Mat4::project($vp, $c, $this->w, $this->h);
                    if ($p === null || $p[0] < 6 || $p[0] > $this->w - 6) {
                        $ok = false;
                        break;
                    }
                    $lo = min($lo, $p[1]);
                    $hi = max($hi, $p[1]);
                }
                if ($ok && $lo >= $top && $hi <= $bottom && abs(($lo + $hi) / 2 - $mid) < $bestOff) {
                    $bestOff = abs(($lo + $hi) / 2 - $mid);
                    $best = [$eye, $target];
                }
            }
        }
        [$this->eye, $this->target] = $best ?? [[$cx, 700.0, $maxZ + 400.0], [$cx, 0.0, ($minZ + $maxZ) / 2]];
        $this->update(0.0);
    }

    /** The matrices for this frame; $shake: how far (map pixels) the camera trembles. */
    public function update(float $shake, float $time = 0.0): void
    {
        $this->shakeX = $shake * sin($time * 83.0);
        $this->shakeY = $shake * 0.6 * sin($time * 61.0 + 1.0);
        $eye = [$this->eye[0] + $this->shakeX, $this->eye[1] + $this->shakeY, $this->eye[2]];
        $target = [$this->target[0] + $this->shakeX, $this->target[1], $this->target[2]];
        $this->vp = $this->matrix($eye, $target);
    }

    /** @return float[] projection x view */
    public function viewProj(): array
    {
        return $this->vp;
    }

    /** @return array{0: float, 1: float, 2: float} */
    public function eye(): array
    {
        return $this->eye;
    }

    public function screen(float $x, float $y, float $height = 0.0): array
    {
        $p = Mat4::project($this->vp, [$x, $height, $y], $this->w, $this->h);
        return $p === null ? [-100.0, -100.0] : [$p[0], $p[1]];
    }

    public function world(float $sx, float $sy): array
    {
        $f = $this->norm([$this->target[0] - $this->eye[0], $this->target[1] - $this->eye[1], $this->target[2] - $this->eye[2]]);
        $r = $this->norm($this->cross($f, [0.0, 1.0, 0.0]));
        $u = $this->cross($r, $f);
        $nx = $sx / $this->w * 2 - 1;
        $ny = 1 - $sy / $this->h * 2;
        $d = [
            $f[0] + $r[0] * $nx * $this->tanHalf * $this->aspect + $u[0] * $ny * $this->tanHalf,
            $f[1] + $r[1] * $nx * $this->tanHalf * $this->aspect + $u[1] * $ny * $this->tanHalf,
            $f[2] + $r[2] * $nx * $this->tanHalf * $this->aspect + $u[2] * $ny * $this->tanHalf,
        ];
        if ($d[1] >= -1e-6) {
            return [$this->target[0], $this->target[2]];
        }
        $t = -$this->eye[1] / $d[1];
        return [$this->eye[0] + $t * $d[0], $this->eye[2] + $t * $d[2]];
    }

    public function scale(float $x, float $y): float
    {
        [$ax] = $this->screen($x, $y);
        [$bx] = $this->screen($x + 10.0, $y);
        return abs($bx - $ax) / 10.0;
    }

    /** @return float[] */
    private function matrix(array $eye, array $target): array
    {
        return Mat4::multiply(Mat4::perspective(self::FOV, $this->aspect, self::NEAR, self::FAR), Mat4::lookAt($eye, $target));
    }

    /** @param float[] $v @return float[] */
    private function norm(array $v): array
    {
        $len = sqrt($v[0] ** 2 + $v[1] ** 2 + $v[2] ** 2) ?: 1.0;
        return [$v[0] / $len, $v[1] / $len, $v[2] / $len];
    }

    /** @param float[] $a @param float[] $b @return float[] */
    private function cross(array $a, array $b): array
    {
        return [$a[1] * $b[2] - $a[2] * $b[1], $a[2] * $b[0] - $a[0] * $b[2], $a[0] * $b[1] - $a[1] * $b[0]];
    }
}
