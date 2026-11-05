<?php

namespace JumPHP\Collision;

/**
 * The tests between two shapes (circle, box, convex polygon): `Collision::test($a, $b)` is null when they do not overlap, else a Hit (the
 * normal from $a to $b, how deep, and so how to push them apart). Rays: `Collision::raycast()`. Separating axis theorem for the
 * polygons; the simple cases (two circles, two boxes) have their own, cheaper, maths. Pure PHP on floats: the same in the browser.
 */
final class Collision
{
    public static function test(Shape $a, Shape $b): ?Hit
    {
        if ($a instanceof Circle && $b instanceof Circle) {
            return self::circles($a, $b);
        }
        if ($a instanceof Box && $b instanceof Box) {
            return self::boxes($a, $b);
        }
        if ($a instanceof Circle) {
            return self::circleAndPolygon($a, self::polygon($b));
        }
        if ($b instanceof Circle) {
            return self::circleAndPolygon($b, self::polygon($a))?->flipped();
        }
        return self::polygons(self::polygon($a), self::polygon($b));
    }

    public static function overlaps(Shape $a, Shape $b): bool
    {
        return self::test($a, $b) !== null;
    }

    private static function polygon(Shape $s): Polygon
    {
        return match (true) {
            $s instanceof Polygon => $s,
            $s instanceof Box => $s->toPolygon(),
            default => throw new \InvalidArgumentException('Not a shape this version knows: ' . $s::class),
        };
    }

    private static function circles(Circle $a, Circle $b): ?Hit
    {
        $dx = $b->x - $a->x;
        $dy = $b->y - $a->y;
        $sum = $a->radius + $b->radius;
        $d2 = $dx * $dx + $dy * $dy;
        if ($d2 >= $sum * $sum) {
            return null;
        }
        $d = sqrt($d2);
        return $d < 1e-12 ? new Hit(1.0, 0.0, $sum) : new Hit($dx / $d, $dy / $d, $sum - $d);
    }

    private static function boxes(Box $a, Box $b): ?Hit
    {
        [$ax1, $ay1, $ax2, $ay2] = $a->bounds();
        [$bx1, $by1, $bx2, $by2] = $b->bounds();
        $ox = min($ax2, $bx2) - max($ax1, $bx1);
        $oy = min($ay2, $by2) - max($ay1, $by1);
        if ($ox <= 0 || $oy <= 0) {
            return null;
        }
        if ($ox < $oy) {
            return new Hit(($bx1 + $bx2) >= ($ax1 + $ax2) ? 1.0 : -1.0, 0.0, $ox);
        }
        return new Hit(0.0, ($by1 + $by2) >= ($ay1 + $ay2) ? 1.0 : -1.0, $oy);
    }

    private static function polygons(Polygon $a, Polygon $b): ?Hit
    {
        $best = INF;
        $bn = [0.0, 0.0];
        foreach ([...$a->axes(), ...$b->axes()] as [$ax, $ay]) {
            [$minA, $maxA] = $a->project($ax, $ay);
            [$minB, $maxB] = $b->project($ax, $ay);
            $overlap = min($maxA, $maxB) - max($minA, $minB);
            if ($overlap <= 0) {
                return null;                          // a gap along this axis: they are apart
            }
            if ($overlap < $best) {
                $best = $overlap;
                $bn = [$ax, $ay];
            }
        }
        return self::oriented($bn[0], $bn[1], $best, $a->center(), $b->center());
    }

    private static function circleAndPolygon(Circle $c, Polygon $p): ?Hit
    {
        // the polygon's own axes, and the one from the circle's centre to the nearest corner
        $axes = $p->axes();
        $nearest = null;
        $dist = INF;
        foreach ($p->points as [$x, $y]) {
            $d = ($x - $c->x) ** 2 + ($y - $c->y) ** 2;
            if ($d < $dist) {
                $dist = $d;
                $nearest = [$x, $y];
            }
        }
        $len = sqrt($dist);
        if ($nearest !== null && $len > 1e-12) {
            $axes[] = [($nearest[0] - $c->x) / $len, ($nearest[1] - $c->y) / $len];
        }
        $best = INF;
        $bn = [1.0, 0.0];
        foreach ($axes as [$ax, $ay]) {
            [$minP, $maxP] = $p->project($ax, $ay);
            $mid = $c->x * $ax + $c->y * $ay;
            $overlap = min($maxP, $mid + $c->radius) - max($minP, $mid - $c->radius);
            if ($overlap <= 0) {
                return null;
            }
            if ($overlap < $best) {
                $best = $overlap;
                $bn = [$ax, $ay];
            }
        }
        return self::oriented($bn[0], $bn[1], $best, [$c->x, $c->y], $p->center());
    }

    /** @param array{0: float, 1: float} $from @param array{0: float, 1: float} $to the axis turned to point from the first centre to the second */
    private static function oriented(float $nx, float $ny, float $depth, array $from, array $to): Hit
    {
        if (($to[0] - $from[0]) * $nx + ($to[1] - $from[1]) * $ny < 0) {
            [$nx, $ny] = [-$nx, -$ny];
        }
        return new Hit($nx, $ny, $depth);
    }

    /**
     * The first shape a ray meets: from ($ox, $oy) along ($dx, $dy) (any length, it is normalised), at most $max away.
     *
     * @param iterable<string|int, Shape> $shapes the key is the id given back in the RayHit
     */
    public static function raycast(float $ox, float $oy, float $dx, float $dy, iterable $shapes, float $max = INF): ?RayHit
    {
        $len = hypot($dx, $dy);
        if ($len < 1e-12) {
            return null;
        }
        [$dx, $dy] = [$dx / $len, $dy / $len];
        $best = null;
        foreach ($shapes as $id => $shape) {
            $hit = match (true) {
                $shape instanceof Circle => self::rayCircle($ox, $oy, $dx, $dy, $shape),
                default => self::rayPolygon($ox, $oy, $dx, $dy, self::polygon($shape)),
            };
            if ($hit !== null && $hit[0] <= $max && ($best === null || $hit[0] < $best[0])) {
                $best = [...$hit, $shape, $id];
            }
        }
        return $best === null ? null : new RayHit($best[0], $ox + $dx * $best[0], $oy + $dy * $best[0], $best[1], $best[2], $best[3], is_int($best[4]) || is_string($best[4]) ? $best[4] : null);
    }

    /** @return array{0: float, 1: float, 2: float}|null distance, normal x, normal y */
    private static function rayCircle(float $ox, float $oy, float $dx, float $dy, Circle $c): ?array
    {
        $fx = $ox - $c->x;
        $fy = $oy - $c->y;
        $b = $fx * $dx + $fy * $dy;
        $cc = $fx * $fx + $fy * $fy - $c->radius * $c->radius;
        if ($cc > 0 && $b > 0) {
            return null;                                  // outside and going away
        }
        $disc = $b * $b - $cc;
        if ($disc < 0) {
            return null;
        }
        $t = -$b - sqrt($disc);
        if ($t < 0) {
            $t = 0.0;                                     // it starts inside: it hits at once
        }
        $px = $ox + $dx * $t - $c->x;
        $py = $oy + $dy * $t - $c->y;
        $n = hypot($px, $py) ?: 1.0;
        return [$t, $px / $n, $py / $n];
    }

    /** @return array{0: float, 1: float, 2: float}|null */
    private static function rayPolygon(float $ox, float $oy, float $dx, float $dy, Polygon $p): ?array
    {
        $best = null;
        $n = count($p->points);
        for ($i = 0; $i < $n; $i++) {
            [$ax, $ay] = $p->points[$i];
            [$bx, $by] = $p->points[($i + 1) % $n];
            $ex = $bx - $ax;
            $ey = $by - $ay;
            $den = $dx * $ey - $dy * $ex;
            if (abs($den) < 1e-12) {
                continue;                                 // parallel
            }
            $t = (($ax - $ox) * $ey - ($ay - $oy) * $ex) / $den;      // along the ray
            $u = (($ax - $ox) * $dy - ($ay - $oy) * $dx) / $den;      // along the edge
            if ($t >= 0 && $u >= 0 && $u <= 1 && ($best === null || $t < $best[0])) {
                $len = hypot($ex, $ey);
                $nx = $ey / $len;
                $ny = -$ex / $len;
                if ($nx * $dx + $ny * $dy > 0) {
                    [$nx, $ny] = [-$nx, -$ny];            // the face that looks at the ray
                }
                $best = [$t, $nx, $ny];
            }
        }
        return $best;
    }
}
