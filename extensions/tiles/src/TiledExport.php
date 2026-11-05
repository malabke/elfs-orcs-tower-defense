<?php

namespace JumPHP\Tiles;

/** Grids written as a Tiled map (.tmj, JSON): the IDE's level editor (or Tiled) opens it, and JumPHP\Tile\TiledJsonLoader reads it back. */
final class TiledExport
{
    /**
     * @param array<string, Grid> $layers layer name => grid (cell value = tile id in the map, 0 = empty); a layer called "collision" is the collision layer
     * @param array{name?: string, image: string, imageWidth: int, imageHeight: int}|null $tileset a picture of tiles (first tile = id 1)
     * @param list<array{name: string, type: string, x: int, y: int}> $objects positions in tiles
     */
    public static function toJson(array $layers, int $tileSize = 16, ?array $tileset = null, array $objects = []): string
    {
        $first = reset($layers);
        if (!$first instanceof Grid) {
            throw new \InvalidArgumentException('At least one layer (a Grid) is needed.');
        }
        $tiles = [];
        $id = 1;
        foreach ($layers as $name => $grid) {
            $tiles[] = ['id' => $id++, 'name' => (string)$name, 'type' => 'tilelayer', 'x' => 0, 'y' => 0, 'width' => $grid->width, 'height' => $grid->height,
                'opacity' => 1, 'visible' => true, 'data' => $grid->toArray()]
                + ($name === 'collision' ? ['properties' => [['name' => 'collision', 'type' => 'bool', 'value' => true]]] : []);
        }
        $nextObject = 1;
        if ($objects) {
            $list = [];
            foreach ($objects as $o) {
                $list[] = ['id' => $nextObject++, 'name' => $o['name'], 'type' => $o['type'], 'x' => $o['x'] * $tileSize, 'y' => $o['y'] * $tileSize,
                    'width' => $tileSize, 'height' => $tileSize, 'rotation' => 0, 'visible' => true];
            }
            $tiles[] = ['id' => $id++, 'name' => 'objects', 'type' => 'objectgroup', 'x' => 0, 'y' => 0, 'opacity' => 1, 'visible' => true, 'draworder' => 'topdown', 'objects' => $list];
        }
        $sets = [];
        if ($tileset !== null) {
            $columns = max(1, intdiv((int)$tileset['imageWidth'], $tileSize));
            $sets[] = ['firstgid' => 1, 'name' => $tileset['name'] ?? 'tiles', 'image' => $tileset['image'], 'imagewidth' => (int)$tileset['imageWidth'],
                'imageheight' => (int)$tileset['imageHeight'], 'tilewidth' => $tileSize, 'tileheight' => $tileSize, 'columns' => $columns,
                'tilecount' => $columns * max(1, intdiv((int)$tileset['imageHeight'], $tileSize)), 'margin' => 0, 'spacing' => 0];
        }
        return json_encode([
            'compressionlevel' => -1, 'width' => $first->width, 'height' => $first->height, 'tilewidth' => $tileSize, 'tileheight' => $tileSize,
            'infinite' => false, 'orientation' => 'orthogonal', 'renderorder' => 'right-down', 'type' => 'map', 'version' => '1.10', 'tiledversion' => '1.10.2',
            'nextlayerid' => $id, 'nextobjectid' => $nextObject, 'layers' => $tiles, 'tilesets' => $sets,
        ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n";
    }
}
