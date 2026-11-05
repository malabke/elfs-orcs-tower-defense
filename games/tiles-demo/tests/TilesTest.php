<?php

namespace Tests;

use JumPHP\Tiles\AutoTile;
use JumPHP\Tiles\Caves;
use JumPHP\Tiles\Grid;
use JumPHP\Tiles\Maps;
use JumPHP\Tiles\Path;
use JumPHP\Tiles\Rng;
use JumPHP\Tiles\Rooms;
use PHPUnit\Framework\TestCase;

final class TilesTest extends TestCase
{
    public function testTheSameSeedGivesTheSameNumbers(): void
    {
        $a = new Rng(42);
        $b = new Rng(42);
        for ($i = 0; $i < 20; $i++) {
            self::assertSame($a->float(), $b->float());
        }
        self::assertNotSame((new Rng(1))->float(), (new Rng(2))->float());
        $r = new Rng(7);
        for ($i = 0; $i < 100; $i++) {
            self::assertContains($r->between(3, 5), [3, 4, 5]);
        }
    }

    public function testAGridIsReadAndWrittenAsRows(): void
    {
        $g = Grid::fromRows(['###', '#.#', '###']);
        self::assertSame(1, $g->get(0, 0));
        self::assertSame(0, $g->get(1, 1));
        self::assertSame(1, $g->get(-5, 9), 'outside is wall');
        self::assertSame(['###', '#.#', '###'], $g->toRows());
        self::assertSame(8, $g->count(1));
    }

    public function testACaveIsOneOpenAreaAndTheSameForASeed(): void
    {
        $g = Caves::generate(40, 24, 5);
        self::assertGreaterThan(50, $g->count(0));
        [$x, $y] = $this->firstOpen($g);
        self::assertCount($g->count(0), $g->region($x, $y), 'all the floor is reachable');
        self::assertSame($g->toRows(), Caves::generate(40, 24, 5)->toRows());
        self::assertNotSame($g->toRows(), Caves::generate(40, 24, 6)->toRows());
        self::assertSame(1, $g->get(0, 0));
    }

    public function testDungeonRoomsAreJoined(): void
    {
        $d = new Rooms(50, 30, 3, 7);
        self::assertGreaterThan(2, count($d->rooms));
        [$x, $y] = $d->center($d->rooms[0]);
        $reach = $d->grid->region($x, $y);
        foreach ($d->rooms as $room) {
            [$cx, $cy] = $d->center($room);
            self::assertArrayHasKey("$cx,$cy", $reach);
        }
    }

    public function testThePathAvoidsWalls(): void
    {
        $g = Grid::fromRows(['.....', '.###.', '.....']);
        $path = Path::find($g, 0, 0, 4, 2);
        self::assertSame([0, 0], $path[0]);
        self::assertSame([4, 2], $path[count($path) - 1]);
        self::assertCount(7, $path);
        foreach ($path as [$x, $y]) {
            self::assertSame(0, $g->get($x, $y));
        }
        self::assertNull(Path::find(Grid::fromRows(['.#.']), 0, 0, 2, 0));
    }

    public function testAutoTileReadsTheNeighbours(): void
    {
        $g = Grid::fromRows(['.#.', '###', '.#.']);
        self::assertSame(15, AutoTile::mask($g, 1, 1));
        self::assertSame(5, AutoTile::mask($g, 1, 0), 'below, and the border counts as wall above');
        self::assertSame(10, AutoTile::mask($g, 0, 1), 'right, and the border on the left');
        self::assertSame(0, AutoTile::apply($g)->get(0, 0));
    }

    public function testAGridBecomesAMapTheEngineCollidesWith(): void
    {
        $map = Maps::toMap(Grid::fromRows(['###', '#.#', '###']), 16);
        self::assertSame(48, $map->pixelWidth());
        self::assertNotSame(0, $map->getTile($map->getCollisionLayer(), 0, 0));
        self::assertSame(0, $map->getTile($map->getCollisionLayer(), 1, 1));
    }

    /** @return array{0: int, 1: int} */
    private function firstOpen(Grid $g): array
    {
        for ($y = 0; $y < $g->height; $y++) {
            for ($x = 0; $x < $g->width; $x++) {
                if ($g->get($x, $y) === 0) {
                    return [$x, $y];
                }
            }
        }
        self::fail('no floor');
    }
}
