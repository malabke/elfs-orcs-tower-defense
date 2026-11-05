# Tiles

Procedural tile maps: caves, dungeon rooms, auto-tiling, path finding. Everything works on a `Grid` (0 = floor, 1 = wall) and a seed gives the
same result on every platform (the browser's 32-bit PHP included). This file is documentation: `bin/jumphp extension:add` copies it into the game
and the JumPHP IDE shows it, with a **Play** button for each sample.

## Make a level

```php
use JumPHP\Tiles\{Caves, Rooms, Maps, Path, AutoTile};

$grid = Caves::generate(60, 34, seed: 7);            // one open area, walls all around
$dungeon = new Rooms(60, 34, seed: 7, count: 8);     // ->grid, ->rooms (x, y, w, h), ->center($room)
$map = Maps::toMap($grid, 16);                       // a TileMap: draw it with TileMapRenderer, collide with CollisionSystem
```

## Classes

| Class | What for |
|---|---|
| `Grid` | `fromRows([...])`, `get/set/fill`, `count`, `neighbors`, `region($x, $y)` (flood fill), `toRows` |
| `Rng` | seeded random: `float()`, `between()`, `chance()`, `pick()` |
| `Caves` | `generate($w, $h, $seed, $fill = 0.45, $steps = 4)` cellular automaton, only the biggest area is kept |
| `Rooms` | rooms that do not overlap, joined by corridors |
| `AutoTile` | `mask()` / `apply()`: the picture of a wall from its neighbours (up 1, right 2, down 4, left 8) |
| `Path` | `find($grid, $sx, $sy, $gx, $gy, diagonal: false)`: A*, a list of `[x, y]` or null |
| `Maps` | `toMap($grid, $tileSize, $tileset, $autotile)`, and `toGrid($map)` the other way (a level made by hand, to find paths on it) |
| `Level` | `cave()` / `dungeon()` / `fromGrid()`: the grid + `start`, `exit` (the farthest floor by path), `items`; `toMap()` (objects `start` / `exit` / `item`), `toTiledJson()` |
| `TiledExport` | `toJson(['collision' => $grid], $tileSize, $tileset, $objects)`: a Tiled map (.tmj) |

## Generate, then finish by hand

```php
$level = Level::dungeon(60, 34, seed: 7, items: 8);
$map = $level->toMap(16);                                  // play it: $map->object('start'), $map->objectsOfType('item')
file_put_contents('assets/maps/level1.tmj', $level->toTiledJson(16));   // or edit it: open the .tmj in the IDE's level editor
$grid = Maps::toGrid(TiledJsonLoader::load('assets/maps/level1.tmj'));  // and read the edited level back for Path::find()
```

## Samples

- **TilesSample**: a cave or a dungeon from a seed (Space: another seed, Tab: cave / dungeon): start (green), exit (red), items (yellow) and the shortest path.
