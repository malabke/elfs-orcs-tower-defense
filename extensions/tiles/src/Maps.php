<?php

namespace JumPHP\Tiles;

use JumPHP\Tile\ArrayLoader;
use JumPHP\Tile\TileMap;

/** Turns a Grid into a TileMap the engine draws and collides with. */
final class Maps
{
    /**
     * @param string|null $tileset tileset picture (assets/...); without it the walls are flat blocks
     * @param bool $autotile walls get the picture picked from their neighbours (AutoTile: needs a tileset of 16 tiles in the mask order)
     */
    public static function toMap(Grid $g, int $tileSize = 16, ?string $tileset = null, bool $autotile = false): TileMap
    {
        $rows = [];
        foreach ($g->toRows([0 => '.', 1 => '#']) as $row) {
            $rows[] = $row;
        }
        $map = ArrayLoader::fromRows($rows, legend: ['#' => 1], tileWidth: $tileSize, tileset: $tileset);
        if ($autotile && $tileset !== null) {
            $tiles = AutoTile::apply($g, 1);
            foreach ($map->layers as $name => $_) {
                for ($y = 0; $y < $g->height; $y++) {
                    for ($x = 0; $x < $g->width; $x++) {
                        $map->setTile($name, $x, $y, $tiles->get($x, $y));
                    }
                }
                break;
            }
        }
        return $map;
    }

    /** The other way: a TileMap (made in the level editor, or Tiled) as a Grid, 1 for every tile of its collision layer (or of $layer), 0 for the rest: Path::find() works on it. */
    public static function toGrid(TileMap $map, ?string $layer = null): Grid
    {
        $layer ??= $map->getCollisionLayer() ?? array_key_first($map->layers);
        $g = new Grid($map->width, $map->height, 0);
        for ($y = 0; $y < $map->height; $y++) {
            for ($x = 0; $x < $map->width; $x++) {
                $g->set($x, $y, $map->getTile((string)$layer, $x, $y) !== 0 ? 1 : 0);
            }
        }
        return $g;
    }
}
