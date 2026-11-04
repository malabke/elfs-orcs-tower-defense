<?php

namespace App\View3d;

/**
 * What the painted ground needs to know about each point of a map, as one RGBA texture over MapModel::RECT
 * (512 x 256 texels, 1.5 map pixels each): R the road (1 in its middle, 0.5 at its edge, 0 farther than twice its
 * half width), G the water (0.5 on the shore), B the sun (1 lit, less in the soft shadows of the trees, rocks and
 * castle), A the light of the sky (less at the foot of things: soft contact shadows).
 */
final class GroundMap
{
    public const ROAD = 0;
    public const WATER = 1;
    public const SUN = 2;
    public const OPEN = 3;

    public const W = 512;
    public const H = 256;

    /** @var array<int, list<float>> the four channels, row after row */
    public array $ch;
    private float $sx;
    private float $sz;

    public function __construct()
    {
        $n = self::W * self::H;
        $this->ch = [array_fill(0, $n, 0.0), array_fill(0, $n, 0.0), array_fill(0, $n, 1.0), array_fill(0, $n, 1.0)];
        $this->sx = self::W / MapModel::RECT[2];
        $this->sz = self::H / MapModel::RECT[3];
    }

    /** A channel at a point of the map (the nearest texel; 0 / 1 outside). */
    public function at(int $ch, float $x, float $z): float
    {
        $i = (int)(($x - MapModel::RECT[0]) * $this->sx);
        $j = (int)(($z - MapModel::RECT[1]) * $this->sz);
        if ($i < 0 || $j < 0 || $i >= self::W || $j >= self::H) {
            return $ch >= self::SUN ? 1.0 : 0.0;
        }
        return $this->ch[$ch][$j * self::W + $i];
    }

    /** A soft disc that darkens the sun or the sky: down to 1 - $strength inside $core x $r, nothing at $r. */
    public function darken(int $ch, float $x, float $z, float $r, float $strength, float $core = 0.3): void
    {
        $a = &$this->ch[$ch];
        [$i0, $i1, $j0, $j1] = $this->span($x - $r, $z - $r, $x + $r, $z + $r);
        for ($j = $j0; $j <= $j1; $j++) {
            $pz = MapModel::RECT[1] + ($j + 0.5) / $this->sz - $z;
            for ($i = $i0; $i <= $i1; $i++) {
                $px = MapModel::RECT[0] + ($i + 0.5) / $this->sx - $x;
                $d = sqrt($px * $px + $pz * $pz) / $r;
                if ($d >= 1.0) {
                    continue;
                }
                $t = $d <= $core ? 0.0 : ($d - $core) / (1 - $core);
                $v = 1.0 - $strength * (1 - $t * $t * (3 - 2 * $t));
                $k = $j * self::W + $i;
                if ($v < $a[$k]) {
                    $a[$k] = $v;
                }
            }
        }
    }

    /**
     * Adds a soft disc to a channel: 1 inside, 0.5 at about 0.78 x $r, 0 at $r. Discs add up (up to 1), so
     * that those of neighbouring tiles melt into one pond with a smooth shore.
     */
    public function spot(int $ch, float $x, float $z, float $r): void
    {
        $a = &$this->ch[$ch];
        [$i0, $i1, $j0, $j1] = $this->span($x - $r, $z - $r, $x + $r, $z + $r);
        for ($j = $j0; $j <= $j1; $j++) {
            $pz = MapModel::RECT[1] + ($j + 0.5) / $this->sz - $z;
            for ($i = $i0; $i <= $i1; $i++) {
                $px = MapModel::RECT[0] + ($i + 0.5) / $this->sx - $x;
                $t = max(0.0, min(1.0, (sqrt($px * $px + $pz * $pz) / $r - 0.55) / 0.45));
                $k = $j * self::W + $i;
                $a[$k] = min(1.0, $a[$k] + 1.0 - $t * $t * (3 - 2 * $t));
            }
        }
    }

    /** Raises a channel along a segment: 1 on it, 0 at $reach from it (the road). */
    public function segment(int $ch, float $ax, float $az, float $bx, float $bz, float $reach): void
    {
        $a = &$this->ch[$ch];
        [$i0, $i1, $j0, $j1] = $this->span(min($ax, $bx) - $reach, min($az, $bz) - $reach, max($ax, $bx) + $reach, max($az, $bz) + $reach);
        $dx = $bx - $ax;
        $dz = $bz - $az;
        $len2 = $dx * $dx + $dz * $dz ?: 1.0;
        for ($j = $j0; $j <= $j1; $j++) {
            $pz = MapModel::RECT[1] + ($j + 0.5) / $this->sz;
            for ($i = $i0; $i <= $i1; $i++) {
                $px = MapModel::RECT[0] + ($i + 0.5) / $this->sx;
                $t = max(0.0, min(1.0, (($px - $ax) * $dx + ($pz - $az) * $dz) / $len2));
                $ex = $px - $ax - $dx * $t;
                $ez = $pz - $az - $dz * $t;
                $v = 1.0 - sqrt($ex * $ex + $ez * $ez) / $reach;
                $k = $j * self::W + $i;
                if ($v > $a[$k]) {
                    $a[$k] = $v;
                }
            }
        }
    }

    /** The texture's bytes (rows of RGBA, the first row at the map's smallest z). */
    public function rgba(): string
    {
        [$r, $g, $b, $a] = $this->ch;
        $out = '';
        for ($k = 0, $n = self::W * self::H; $k < $n; $k++) {
            $out .= chr((int)($r[$k] * 255.0 + 0.5)) . chr((int)($g[$k] * 255.0 + 0.5)) . chr((int)($b[$k] * 255.0 + 0.5)) . chr((int)($a[$k] * 255.0 + 0.5));
        }
        return $out;
    }

    /** @return array{0: int, 1: int, 2: int, 3: int} the texels (columns i0..i1, rows j0..j1) of a box of the map */
    private function span(float $x0, float $z0, float $x1, float $z1): array
    {
        return [
            max(0, (int)floor(($x0 - MapModel::RECT[0]) * $this->sx)),
            min(self::W - 1, (int)floor(($x1 - MapModel::RECT[0]) * $this->sx)),
            max(0, (int)floor(($z0 - MapModel::RECT[1]) * $this->sz)),
            min(self::H - 1, (int)floor(($z1 - MapModel::RECT[1]) * $this->sz)),
        ];
    }
}
