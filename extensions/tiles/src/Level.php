<?php

namespace JumPHP\Tiles;

use JumPHP\Tile\TileMap;

/**
 * A generated level: the Grid, where the player starts, where the exit is (the farthest floor cell by path), and items scattered on the floor,
 * all from one seed. toMap() gives the TileMap the engine draws; toTiledJson() a .tmj to open in the IDE's level editor and finish by hand.
 */
final class Level
{
    /**
     * @param array{0: int, 1: int} $start
     * @param array{0: int, 1: int} $exit
     * @param list<array{0: int, 1: int}> $items
     */
    public function __construct(public readonly Grid $grid, public readonly array $start, public readonly array $exit, public readonly array $items, public readonly int $seed)
    {
    }

    public static function cave(int $width, int $height, int $seed = 1, int $items = 6, float $fill = 0.45): self
    {
        return self::fromGrid(Caves::generate($width, $height, $seed, $fill), $seed, $items);
    }

    public static function dungeon(int $width, int $height, int $seed = 1, int $items = 6, int $rooms = 8): self
    {
        $d = new Rooms($width, $height, $seed, $rooms);
        // the player starts in the first room, the exit is as far as the path goes from there
        return self::fromGrid($d->grid, $seed, $items, $d->rooms ? $d->center($d->rooms[0]) : null);
    }

    /** @param array{0: int, 1: int}|null $start */
    public static function fromGrid(Grid $grid, int $seed = 1, int $items = 6, ?array $start = null): self
    {
        $start ??= self::firstFloor($grid);
        if ($start === null) {
            throw new \InvalidArgumentException('A level needs some floor.');
        }
        $far = self::farthest($grid, $start);
        $rng = new Rng($seed + 7);
        $floor = array_keys($grid->region($start[0], $start[1]));
        $taken = ["$start[0],$start[1]" => true, "$far[0],$far[1]" => true];
        $placed = [];
        for ($try = 0; $try < $items * 20 && count($placed) < $items && $floor; $try++) {
            $key = $rng->pick($floor);
            if (!isset($taken[$key])) {
                $taken[$key] = true;
                $placed[] = array_map('intval', explode(',', $key));
            }
        }
        return new self($grid, $start, $far, $placed, $seed);
    }

    /** The cell the farthest away from $from, walking (breadth first). @return array{0: int, 1: int} */
    public static function farthest(Grid $g, array $from): array
    {
        $seen = ["$from[0],$from[1]" => true];
        $queue = [$from];
        $last = $from;
        for ($i = 0; $i < count($queue); $i++) {
            $last = $queue[$i];
            foreach ($g->neighbors($last[0], $last[1]) as [$nx, $ny]) {
                if (!isset($seen["$nx,$ny"]) && $g->get($nx, $ny) !== 1) {
                    $seen["$nx,$ny"] = true;
                    $queue[] = [$nx, $ny];
                }
            }
        }
        return $last;
    }

    /** @return array{0: int, 1: int}|null */
    private static function firstFloor(Grid $g): ?array
    {
        for ($y = 0; $y < $g->height; $y++) {
            for ($x = 0; $x < $g->width; $x++) {
                if ($g->get($x, $y) === 0) {
                    return [$x, $y];
                }
            }
        }
        return null;
    }

    /** @return list<array{name: string, type: string, x: int, y: int}> start, exit and items, in tiles */
    public function objects(): array
    {
        $out = [['name' => 'start', 'type' => 'start', 'x' => $this->start[0], 'y' => $this->start[1]],
            ['name' => 'exit', 'type' => 'exit', 'x' => $this->exit[0], 'y' => $this->exit[1]]];
        foreach ($this->items as $i => [$x, $y]) {
            $out[] = ['name' => 'item' . ($i + 1), 'type' => 'item', 'x' => $x, 'y' => $y];
        }
        return $out;
    }

    /** The TileMap (see Maps::toMap), with the objects start / exit / item: `$map->object('start')`, `$map->objectsOfType('item')`, EntityManager::spawnFromMap(). */
    public function toMap(int $tileSize = 16, ?string $tileset = null, bool $autotile = false): TileMap
    {
        $map = Maps::toMap($this->grid, $tileSize, $tileset, $autotile);
        foreach ($this->objects() as $o) {
            $map->objects[] = [
                'type' => $o['type'], 'name' => $o['name'], 'x' => (float)($o['x'] * $tileSize), 'y' => (float)($o['y'] * $tileSize),
                'width' => (float)$tileSize, 'height' => (float)$tileSize, 'properties' => [], 'layer' => 'objects', 'gid' => 0,
            ];
        }
        return $map;
    }

    /** A Tiled map (.tmj) of this level: write it into assets/maps/ and open it in the IDE's level editor. @param array<string, mixed>|null $tileset see TiledExport */
    public function toTiledJson(int $tileSize = 16, ?array $tileset = null): string
    {
        return TiledExport::toJson(['collision' => $this->grid], $tileSize, $tileset, $this->objects());
    }
}
