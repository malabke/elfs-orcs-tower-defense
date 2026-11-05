<?php

namespace Tests;

use App\Td\GameData;
use App\View3d\GroundMap;
use App\View3d\MapModel;
use App\View3d\Mesh;
use App\View3d\Props;
use App\View3d\Rig;
use App\View3d\Skeleton;
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
            $mat = $m->v[$i + 9];
            $bone = $mat >= Mesh::METAL_BONE ? $mat - Mesh::METAL_BONE : $mat - Mesh::BONE;
            self::assertTrue(($mat >= 0.0 && $mat < 7.0) || ($mat >= Mesh::BONE && $bone >= 0 && $bone < Skeleton::MAX), "$what: unknown material $mat");
        }
    }

    public function testEveryCreatureIsBuiltAndPosedInEveryAnimation(): void
    {
        foreach (array_keys(Units::KINDS) as $sprite) {
            foreach (Units::parts($sprite) as $part) {
                $mesh = Units::build($sprite, $part);
                self::assertSound($mesh, "$sprite $part");
                // every vertex moves with a bone of its skeleton
                $bones = Units::skeleton($sprite, $part)->count();
                for ($i = 9, $n = count($mesh->v); $i < $n; $i += Mesh::STRIDE) {
                    $mat = $mesh->v[$i];
                    self::assertGreaterThanOrEqual(Mesh::BONE, $mat, "$sprite $part: a vertex without a bone");
                    self::assertLessThan($bones, $mat >= Mesh::METAL_BONE ? $mat - Mesh::METAL_BONE : $mat - Mesh::BONE, "$sprite $part: a bone it has not");
                }
                foreach (Units::ANIMS as $anim) {
                    foreach ([0.0, 0.3, 0.7, 2.6] as $t) {
                        $pose = Units::pose($sprite, $anim, $t, $part);
                        self::assertCount(16 * Skeleton::MAX, $pose);
                        foreach ($pose as $value) {
                            self::assertTrue(is_finite($value), "$sprite $part $anim $t: a matrix is not finite");
                        }
                    }
                }
            }
            self::assertGreaterThan(3.0, Units::height($sprite));
            self::assertGreaterThan(3.0, Units::stride($sprite));
        }
    }

    /** People and mounts keep their feet on the ground whatever they do: they neither sink nor float. */
    public function testCreaturesKeepTheirFeetOnTheGround(): void
    {
        foreach (Units::KINDS as $sprite => $d) {
            if ($d['k'] === 'wings') {
                continue;
            }
            foreach (['walk', 'fight', 'idle', 'stun'] as $anim) {
                foreach ([0.0, 0.125, 0.25, 0.375, 0.5, 0.625, 0.75, 0.875] as $t) {
                    $low = INF;
                    foreach (Units::posed(Units::build($sprite), Units::pose($sprite, $anim, $t)) as $p) {
                        $low = min($low, $p[1]);
                    }
                    self::assertGreaterThan(-0.6, $low, "$sprite sinks into the ground ($anim at $t)");
                    self::assertLessThan(0.8, $low, "$sprite floats above the ground ($anim at $t)");
                }
            }
        }
    }

    /** A rider sits on its saddle, above its mount's back, in every pose. */
    public function testRidersSitOnTheirMounts(): void
    {
        foreach (Units::KINDS as $sprite => $d) {
            if (!isset($d['rider'])) {
                continue;
            }
            foreach (['walk', 'fight', 'idle'] as $anim) {
                [$min] = self::partBounds($sprite, 'rider', $anim, 0.3);
                [, $mountMax] = self::partBounds($sprite, 'body', $anim, 0.3);
                self::assertGreaterThan(5.0, $min[1], "$sprite: the rider's feet hang along the flanks, above the ground");
                self::assertGreaterThan($mountMax[1] - 9.0, $min[1] + 4.0);
            }
        }
    }

    /** @return array{0: array<int, float>, 1: array<int, float>} */
    private static function partBounds(string $sprite, string $part, string $anim, float $t): array
    {
        $min = [INF, INF, INF];
        $max = [-INF, -INF, -INF];
        foreach (Units::posed(Units::build($sprite, $part), Units::pose($sprite, $anim, $t, $part)) as $p) {
            for ($a = 0; $a < 3; $a++) {
                $min[$a] = min($min[$a], $p[$a]);
                $max[$a] = max($max[$a], $p[$a]);
            }
        }
        return [$min, $max];
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
