<?php

namespace Tests;

use App\Td\GameData;
use App\View3d\GroundMap;
use App\View3d\MapModel;
use App\View3d\Mesh;
use App\View3d\Props;
use App\View3d\Rig;
use App\View3d\Towers;
use App\View3d\Units;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * The 3D side without a window: every model builds with sound vertices, the camera shows every platform, a click on
 * the screen finds the ground point it was cast from.
 */
final class ViewTest extends TestCase
{
    private static function data(): GameData
    {
        return new GameData(dirname(__DIR__) . '/assets/data');
    }

    /** No NaN, no infinity, unit normals, colours in range, whole triangles. */
    private static function assertSound(Mesh $m, string $what): void
    {
        self::assertGreaterThan(0, $m->vertexCount(), "$what is empty");
        self::assertSame(0, $m->vertexCount() % 3, "$what has a broken triangle");
        foreach ($m->v as $i => $value) {
            self::assertTrue(is_finite($value), "$what: vertex float $i is $value");
        }
        for ($i = 0, $n = count($m->v); $i < $n; $i += Mesh::STRIDE * 7) {
            $len = sqrt($m->v[$i + 3] ** 2 + $m->v[$i + 4] ** 2 + $m->v[$i + 5] ** 2);
            self::assertEqualsWithDelta(1.0, $len, 0.01, "$what: a normal is not a unit vector");
            foreach ([6, 7, 8] as $k) {
                self::assertGreaterThanOrEqual(0.0, $m->v[$i + $k]);
                self::assertLessThan(2.1, $m->v[$i + $k]);
            }
            self::assertTrue($m->v[$i + 9] >= 0.0 && $m->v[$i + 9] < 7.0, "$what: unknown material {$m->v[$i + 9]}");
        }
    }

    public function testEveryCreatureBuildsInEveryWalkingFrame(): void
    {
        foreach (array_keys(Units::KINDS) as $sprite) {
            for ($frame = 0; $frame < Units::FRAMES; $frame++) {
                self::assertSound(Units::build($sprite, $frame), "$sprite/$frame");
            }
            self::assertGreaterThan(3.0, Units::height($sprite));
        }
    }

    public function testEveryCreatureOfTheDataHasAModel(): void
    {
        $data = self::data();
        $sprites = [];
        foreach ($data->enemies as $enemy) {
            $sprites[$enemy['sprite']] = true;
        }
        foreach ($data->heroes as $hero) {
            $sprites[$hero['sprite']] = true;
        }
        foreach ($data->towers as $tower) {
            foreach ([$tower['levels'][0]['soldiers']['sprite'] ?? null, $tower['levels'][1]['soldiers']['sprite'] ?? null] as $soldier) {
                if ($soldier !== null) {
                    $sprites[$soldier] = true;
                }
            }
        }
        foreach (array_keys($sprites) as $sprite) {
            self::assertArrayHasKey($sprite, Units::KINDS, "no 3D model for the creature '$sprite'");
        }
    }

    public function testEveryTowerOfTheDataHasAModelAtEveryLevelAndSpecialization(): void
    {
        $sprites = array_unique(array_column(self::data()->towers, 'sprite'));
        foreach ($sprites as $sprite) {
            self::assertContains($sprite, Towers::SPRITES);
        }
        foreach (Towers::SPRITES as $sprite) {
            foreach ([1, 2, 3] as $level) {
                self::assertSound(Towers::build($sprite, $level, null), "$sprite level $level");
            }
            foreach ([0, 1] as $spec) {
                self::assertSound(Towers::build($sprite, 4, $spec), "$sprite spec $spec");
            }
        }
        self::assertGreaterThan(Towers::height(1), Towers::height(3));
    }

    public function testThePropsBuild(): void
    {
        foreach (['arrow', 'bolt', 'javelin', 'stone', 'rock', 'pitch', 'spark', 'orb', 'note', 'star'] as $kind) {
            self::assertSound(Props::projectile($kind), "projectile $kind");
        }
        foreach ([[false, true, false], [true, true, false], [false, false, false], [false, true, true]] as [$stone, $standing, $spiked]) {
            self::assertSound(Props::wall($stone, $standing, $spiked), 'wall');
        }
        self::assertSound(Props::trap(true), 'trap');
        self::assertSound(Props::trap(false), 'trap');
        self::assertSound(Props::mound(), 'mound');
        self::assertSound(Props::disc(), 'disc');
        self::assertSound(Props::ring(), 'ring');
        self::assertSound(Props::ball(), 'ball');
    }

    public static function maps(): array
    {
        return array_map(static fn (string $m) => [$m], self::data()->mapIds());
    }

    #[DataProvider('maps')]
    public function testEveryMapBuilds(string $id): void
    {
        $data = self::data();
        $built = MapModel::build($data->map($id), $data->faction('elves')['colors']);
        self::assertSound($built['mesh'], "map $id");
        // the browser's PHP builds it too, with 128 MB
        self::assertLessThan(100000, $built['mesh']->vertexCount(), 'a map stays one light buffer');
        self::assertCount(3, $built['fog']);
        self::assertSame(GroundMap::W * GroundMap::H * 4, strlen($built['ground']), 'the ground texture is whole');
        self::assertSame(0.0, MapModel::height(240.0, 120.0), 'the board is flat (clicks are cast on y = 0)');
        self::assertGreaterThan(5.0, MapModel::height(240.0, -90.0), 'hills rise beyond the far edge');
    }

    /** Every platform (and the tower on it) is on the screen, under the top bar, with a camera that fits the map. */
    #[DataProvider('maps')]
    public function testTheCameraShowsEveryPlatform(string $id): void
    {
        $map = self::data()->map($id);
        $rig = self::rigFor($map);
        foreach ($map['slots'] as [$sx, $sy]) {
            $x = ($sx + 1) * 16.0;
            $z = ($sy + 1) * 16.0;
            foreach ([0.0, 40.0] as $height) {
                [$px, $py] = $rig->screen($x, $z, $height);
                self::assertGreaterThan(30, $py, "$id: the platform $sx,$sy is under the top bar");
                self::assertLessThan(270, $py, "$id: the platform $sx,$sy is below the screen");
                self::assertGreaterThan(0, $px);
                self::assertLessThan(480, $px);
            }
        }
        [$gx, $gy] = $rig->screen($map['bastion'][0] * 16.0 + 16, $map['bastion'][1] * 16.0 + 48);
        self::assertTrue($gx > 0 && $gx < 480 && $gy > 30 && $gy < 270, "$id: the castle's gate is on the screen");
    }

    #[DataProvider('maps')]
    public function testAPointOfTheScreenFindsItsPointOnTheGround(string $id): void
    {
        $rig = self::rigFor(self::data()->map($id));
        foreach ([[60.0, 40.0], [240.0, 120.0], [400.0, 200.0], [30.0, 220.0]] as [$x, $z]) {
            [$sx, $sy] = $rig->screen($x, $z);
            [$wx, $wz] = $rig->world($sx, $sy);
            self::assertEqualsWithDelta($x, $wx, 0.2);
            self::assertEqualsWithDelta($z, $wz, 0.2);
        }
        self::assertGreaterThan(0.4, $rig->scale(240.0, 120.0));
    }

    public function testTheNearEdgeOfTheMapIsBiggerThanTheFarOne(): void
    {
        $rig = self::rigFor(self::data()->map('thornvale'));
        self::assertGreaterThan($rig->scale(240.0, 20.0), $rig->scale(240.0, 220.0));
    }

    private static function rigFor(array $map): Rig
    {
        $minX = $minZ = INF;
        $maxX = $maxZ = -INF;
        foreach ($map['slots'] as [$sx, $sy]) {
            $minX = min($minX, ($sx + 1) * 16.0 - 18);
            $maxX = max($maxX, ($sx + 1) * 16.0 + 18);
            $minZ = min($minZ, ($sy + 1) * 16.0 - 18);
            $maxZ = max($maxZ, ($sy + 1) * 16.0 + 18);
        }
        [$bx, $by] = $map['bastion'];
        $minX = min($minX, $bx * 16.0);
        $maxX = max($maxX, $bx * 16.0 + 48);
        $minZ = min($minZ, $by * 16.0);
        $maxZ = max($maxZ, $by * 16.0 + 96);
        $rig = new Rig(480.0, 270.0);
        $rig->fit($minX, $minZ, $maxX, $maxZ, 60.0, 36.0, 266.0, 52.0);
        return $rig;
    }
}
