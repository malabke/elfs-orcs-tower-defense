<?php

namespace JumPHP\Tiles;

/** A* on a Grid: the shortest way between two cells over cells that are not walls (4 directions, or 8 without cutting corners). */
final class Path
{
    /** @return list<array{0: int, 1: int}>|null the cells from start to goal included, null when there is no way */
    public static function find(Grid $g, int $sx, int $sy, int $gx, int $gy, bool $diagonal = false, int $blocked = 1): ?array
    {
        if ($g->get($sx, $sy) === $blocked || $g->get($gx, $gy) === $blocked) {
            return null;
        }
        $key = fn (int $x, int $y): string => "$x,$y";
        $h = fn (int $x, int $y): float => $diagonal ? max(abs($x - $gx), abs($y - $gy)) : abs($x - $gx) + abs($y - $gy);
        $cost = [$key($sx, $sy) => 0];
        $from = [];
        $open = [$key($sx, $sy) => [$sx, $sy, $h($sx, $sy)]];
        while ($open) {
            uasort($open, fn ($a, $b) => $a[2] <=> $b[2]);
            $k = array_key_first($open);
            [$x, $y] = $open[$k];
            unset($open[$k]);
            if ($x === $gx && $y === $gy) {
                $path = [[$x, $y]];
                while (isset($from[$k])) {
                    $k = $from[$k];
                    array_unshift($path, array_map('intval', explode(',', $k)));
                }
                return $path;
            }
            foreach ($g->neighbors($x, $y, $diagonal) as [$nx, $ny]) {
                if ($g->get($nx, $ny) === $blocked) {
                    continue;
                }
                if ($nx !== $x && $ny !== $y && ($g->get($nx, $y) === $blocked || $g->get($x, $ny) === $blocked)) {
                    continue; // no cutting a corner
                }
                $nk = $key($nx, $ny);
                $c = $cost[$key($x, $y)] + 1;
                if (!isset($cost[$nk]) || $c < $cost[$nk]) {
                    $cost[$nk] = $c;
                    $from[$nk] = $key($x, $y);
                    $open[$nk] = [$nx, $ny, $c + $h($nx, $ny)];
                }
            }
        }
        return null;
    }
}
