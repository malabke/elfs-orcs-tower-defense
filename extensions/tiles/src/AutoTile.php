<?php

namespace JumPHP\Tiles;

/**
 * Picks the right picture of a wall tile from its neighbours (a 16-tile "blob" set). The index is a mask: up = 1, right = 2, down = 4, left = 8,
 * each added when that neighbour is ALSO a wall. A tileset laid out in that order (0 = a lone block ... 15 = surrounded) is then enough.
 */
final class AutoTile
{
    /** @return int 0..15 */
    public static function mask(Grid $g, int $x, int $y, int $solid = 1): int
    {
        return ($g->get($x, $y - 1) === $solid ? 1 : 0)
            | ($g->get($x + 1, $y) === $solid ? 2 : 0)
            | ($g->get($x, $y + 1) === $solid ? 4 : 0)
            | ($g->get($x - 1, $y) === $solid ? 8 : 0);
    }

    /**
     * A new grid where each wall cell holds $firstGid + its mask, and the floor 0.
     *
     * @param int $firstGid the tile id of mask 0 in the map
     */
    public static function apply(Grid $g, int $firstGid = 1, int $solid = 1): Grid
    {
        $out = new Grid($g->width, $g->height, 0);
        for ($y = 0; $y < $g->height; $y++) {
            for ($x = 0; $x < $g->width; $x++) {
                if ($g->get($x, $y) === $solid) {
                    $out->set($x, $y, $firstGid + self::mask($g, $x, $y, $solid));
                }
            }
        }
        return $out;
    }
}
