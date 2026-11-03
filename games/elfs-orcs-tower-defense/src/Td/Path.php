<?php

namespace App\Td;

/**
 * A line of waypoints (pixels of the play area) and the point at any distance along it.
 */
final class Path
{
    /** @var list<array{0: float, 1: float}> */
    public readonly array $points;
    /** @var list<float> distance from the start to each point */
    private array $distances = [];
    public readonly float $length;

    /** @param list<array{0: float, 1: float}> $points */
    public function __construct(array $points)
    {
        $this->points = $points;
        $total = 0.0;
        foreach ($this->points as $i => $point) {
            if ($i > 0) {
                $total += hypot($point[0] - $this->points[$i - 1][0], $point[1] - $this->points[$i - 1][1]);
            }
            $this->distances[] = $total;
        }
        $this->length = $total;
    }

    /** From waypoints in tile corners (maps.yaml). */
    public static function fromTiles(array $tiles, int $tile = GameData::TILE): self
    {
        return new self(array_map(static fn (array $p) => [(float)$p[0] * $tile, (float)$p[1] * $tile], $tiles));
    }

    /** The straight line from the first point to the last one (flying enemies). */
    public function direct(): self
    {
        return new self([$this->points[0], $this->points[count($this->points) - 1]]);
    }

    /** @return array{0: float, 1: float, 2: float, 3: float} x, y and the direction (dx, dy) at $distance */
    public function at(float $distance): array
    {
        $n = count($this->points);
        if ($distance <= 0 || $n < 2) {
            [$dx, $dy] = $n < 2 ? [1.0, 0.0] : $this->direction(0);
            return [$this->points[0][0], $this->points[0][1], $dx, $dy];
        }
        for ($i = 1; $i < $n; $i++) {
            if ($distance <= $this->distances[$i] || $i === $n - 1) {
                $segment = $this->distances[$i] - $this->distances[$i - 1];
                $t = $segment > 0 ? min(1.0, ($distance - $this->distances[$i - 1]) / $segment) : 1.0;
                [$ax, $ay] = $this->points[$i - 1];
                [$bx, $by] = $this->points[$i];
                [$dx, $dy] = $this->direction($i - 1);
                return [$ax + ($bx - $ax) * $t, $ay + ($by - $ay) * $t, $dx, $dy];
            }
        }
        return [$this->points[$n - 1][0], $this->points[$n - 1][1], 1.0, 0.0];
    }

    /** @return array{0: float, 1: float} unit direction of segment $i */
    private function direction(int $i): array
    {
        $dx = $this->points[$i + 1][0] - $this->points[$i][0];
        $dy = $this->points[$i + 1][1] - $this->points[$i][1];
        $len = hypot($dx, $dy);
        return $len > 0 ? [$dx / $len, $dy / $len] : [1.0, 0.0];
    }

    /** Shortest distance from a point to the path. */
    public function distanceTo(float $x, float $y): float
    {
        $best = INF;
        for ($i = 1, $n = count($this->points); $i < $n; $i++) {
            $best = min($best, self::segmentDistance($x, $y, $this->points[$i - 1], $this->points[$i]));
        }
        return $best;
    }

    /** The distance along the path of its point nearest to ($x, $y). */
    public function project(float $x, float $y): float
    {
        $best = INF;
        $at = 0.0;
        for ($i = 1, $n = count($this->points); $i < $n; $i++) {
            [$ax, $ay] = $this->points[$i - 1];
            [$bx, $by] = $this->points[$i];
            $dx = $bx - $ax;
            $dy = $by - $ay;
            $len2 = $dx * $dx + $dy * $dy;
            $t = $len2 > 0 ? max(0.0, min(1.0, (($x - $ax) * $dx + ($y - $ay) * $dy) / $len2)) : 0.0;
            $d = hypot($x - ($ax + $dx * $t), $y - ($ay + $dy * $t));
            if ($d < $best) {
                $best = $d;
                $at = $this->distances[$i - 1] + sqrt($len2) * $t;
            }
        }
        return $at;
    }

    public static function segmentDistance(float $x, float $y, array $a, array $b): float
    {
        $dx = $b[0] - $a[0];
        $dy = $b[1] - $a[1];
        $len2 = $dx * $dx + $dy * $dy;
        $t = $len2 > 0 ? max(0.0, min(1.0, (($x - $a[0]) * $dx + ($y - $a[1]) * $dy) / $len2)) : 0.0;
        return hypot($x - ($a[0] + $dx * $t), $y - ($a[1] + $dy * $t));
    }
}
