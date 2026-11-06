<?php

namespace JumPHP\Collision;

/** A CONVEX polygon (3 points or more, in order, either way round). A concave shape is several convex ones. */
final class Polygon implements Shape
{
    /** @var list<array{0: float, 1: float}> */
    public array $points;

    /** @param list<array{0: float|int, 1: float|int}> $points */
    public function __construct(array $points)
    {
        if (count($points) < 3) {
            throw new \InvalidArgumentException('A polygon needs 3 points at least.');
        }
        $this->points = array_map(fn ($p) => [(float)$p[0], (float)$p[1]], array_values($points));
        if (!$this->isConvex()) {
            throw new \InvalidArgumentException('A polygon must be convex: cut a concave shape into convex ones.');
        }
    }

    /** A regular polygon of $sides sides around a centre; $angle in degrees turns it. */
    public static function regular(float $x, float $y, float $radius, int $sides = 6, float $angle = 0): self
    {
        $points = [];
        for ($i = 0; $i < max(3, $sides); $i++) {
            $a = deg2rad($angle) + 2 * M_PI * $i / max(3, $sides);
            $points[] = [$x + cos($a) * $radius, $y + sin($a) * $radius];
        }
        return new self($points);
    }

    /** Turns it by $degrees around its centre (a new polygon). */
    public function rotated(float $degrees): self
    {
        [$cx, $cy] = $this->center();
        $c = cos(deg2rad($degrees));
        $s = sin(deg2rad($degrees));
        return new self(array_map(fn ($p) => [$cx + ($p[0] - $cx) * $c - ($p[1] - $cy) * $s, $cy + ($p[0] - $cx) * $s + ($p[1] - $cy) * $c], $this->points));
    }

    public function bounds(): array
    {
        $xs = array_column($this->points, 0);
        $ys = array_column($this->points, 1);
        return [min($xs), min($ys), max($xs), max($ys)];
    }

    /** The mean of its points (the middle of a regular one). */
    public function center(): array
    {
        $n = count($this->points);
        return [array_sum(array_column($this->points, 0)) / $n, array_sum(array_column($this->points, 1)) / $n];
    }

    public function contains(float $x, float $y): bool
    {
        $sign = 0;
        $n = count($this->points);
        for ($i = 0; $i < $n; $i++) {
            [$ax, $ay] = $this->points[$i];
            [$bx, $by] = $this->points[($i + 1) % $n];
            $cross = ($bx - $ax) * ($y - $ay) - ($by - $ay) * ($x - $ax);
            if (abs($cross) < 1e-9) {
                continue;
            }
            $s = $cross > 0 ? 1 : -1;
            if ($sign !== 0 && $s !== $sign) {
                return false;
            }
            $sign = $s;
        }
        return true;
    }

    public function moveTo(float $x, float $y): static
    {
        [$cx, $cy] = $this->center();
        return $this->translate($x - $cx, $y - $cy);
    }

    public function translate(float $dx, float $dy): static
    {
        $this->points = array_map(fn ($p) => [$p[0] + $dx, $p[1] + $dy], $this->points);
        return $this;
    }

    /** @return list<array{0: float, 1: float}> the unit normals of its edges (the axes of the separating axis test) */
    public function axes(): array
    {
        $axes = [];
        $n = count($this->points);
        for ($i = 0; $i < $n; $i++) {
            [$ax, $ay] = $this->points[$i];
            [$bx, $by] = $this->points[($i + 1) % $n];
            $len = hypot($bx - $ax, $by - $ay);
            if ($len > 1e-12) {
                $axes[] = [-($by - $ay) / $len, ($bx - $ax) / $len];
            }
        }
        return $axes;
    }

    /** @return array{0: float, 1: float} the lowest and the highest of its points along an axis */
    public function project(float $ax, float $ay): array
    {
        $min = INF;
        $max = -INF;
        foreach ($this->points as [$x, $y]) {
            $d = $x * $ax + $y * $ay;
            $min = min($min, $d);
            $max = max($max, $d);
        }
        return [$min, $max];
    }

    private function isConvex(): bool
    {
        $n = count($this->points);
        $sign = 0;
        for ($i = 0; $i < $n; $i++) {
            [$ax, $ay] = $this->points[$i];
            [$bx, $by] = $this->points[($i + 1) % $n];
            [$cx, $cy] = $this->points[($i + 2) % $n];
            $cross = ($bx - $ax) * ($cy - $by) - ($by - $ay) * ($cx - $bx);
            if (abs($cross) < 1e-9) {
                continue;
            }
            $s = $cross > 0 ? 1 : -1;
            if ($sign !== 0 && $s !== $sign) {
                return false;
            }
            $sign = $s;
        }
        return $sign !== 0;
    }
}
