<?php

namespace Tests;

use JumPHP\Collision\Box;
use JumPHP\Collision\Circle;
use JumPHP\Collision\Collision;
use JumPHP\Collision\CollisionWorld;
use JumPHP\Collision\Polygon;
use JumPHP\Collision\SpatialHash;
use PHPUnit\Framework\TestCase;

/** The extension's geometry, with no window. */
final class CollisionTest extends TestCase
{
    public function testTwoCirclesGiveTheWayOut(): void
    {
        $hit = Collision::test(new Circle(0, 0, 10), new Circle(15, 0, 10));
        self::assertEqualsWithDelta([1.0, 0.0, 5.0], [$hit->nx, $hit->ny, $hit->depth], 1e-9);
        self::assertNull(Collision::test(new Circle(0, 0, 10), new Circle(21, 0, 10)));
        self::assertNotNull(Collision::test(new Circle(5, 5, 3), new Circle(5, 5, 3)), 'the same place: still a normal');
    }

    public function testTwoBoxesAreSeparatedByTheShorterWay(): void
    {
        $hit = Collision::test(new Box(0, 0, 10, 10), new Box(8, 2, 10, 10));
        self::assertEqualsWithDelta([1.0, 0.0, 2.0], [$hit->nx, $hit->ny, $hit->depth], 1e-9);
        $hit = Collision::test(new Box(0, 0, 10, 10), new Box(2, -8, 10, 10));
        self::assertEqualsWithDelta([0.0, -1.0, 2.0], [$hit->nx, $hit->ny, $hit->depth], 1e-9);
        self::assertNull(Collision::test(new Box(0, 0, 10, 10), new Box(10, 0, 5, 5)), 'touching is not overlapping');
    }

    public function testPushingTheFirstShapeByTheHitSeparatesThem(): void
    {
        $cases = [
            [new Circle(8, 8, 6), new Box(10, 0, 10, 20)],
            [new Box(0, 0, 10, 10), Polygon::regular(14, 5, 8, 6)],
            [Polygon::regular(0, 0, 10, 5, 20), Polygon::regular(12, 3, 10, 7)],
            [new Circle(0, 0, 8), Polygon::regular(10, 2, 9, 4, 45)],
        ];
        foreach ($cases as $i => [$a, $b]) {
            $hit = Collision::test($a, $b);
            self::assertNotNull($hit, "case $i overlaps");
            [$dx, $dy] = $hit->pushOutOfFirst();
            $a->translate($dx * 1.001, $dy * 1.001);
            self::assertNull(Collision::test($a, $b), "case $i: apart after the push");
        }
    }

    public function testTheTestIsTheSameFromTheOtherSide(): void
    {
        $a = new Circle(8, 8, 6);
        $b = Polygon::regular(14, 6, 9, 5);
        $ab = Collision::test($a, $b);
        $ba = Collision::test($b, $a);
        self::assertEqualsWithDelta($ab->depth, $ba->depth, 1e-9);
        self::assertEqualsWithDelta(-$ab->nx, $ba->nx, 1e-9);
        self::assertEqualsWithDelta(-$ab->ny, $ba->ny, 1e-9);
    }

    public function testAConcavePolygonIsRefused(): void
    {
        $this->expectExceptionMessage('convex');
        new Polygon([[0, 0], [10, 0], [5, 3], [10, 10], [0, 10]]);
    }

    public function testPointsAreInOrOut(): void
    {
        $hex = Polygon::regular(50, 50, 20, 6);
        self::assertTrue($hex->contains(50, 50));
        self::assertFalse($hex->contains(80, 50));
        self::assertTrue((new Circle(0, 0, 5))->contains(3, 3));
        self::assertTrue((new Box(0, 0, 4, 4))->contains(4, 4));
    }

    public function testARayStopsAtTheFirstShapeItMeets(): void
    {
        $shapes = ['far' => new Box(100, -10, 10, 20), 'near' => new Circle(50, 0, 10), 'aside' => new Box(60, 40, 10, 10)];
        $hit = Collision::raycast(0, 0, 3, 0, $shapes);
        self::assertSame('near', $hit->id);
        self::assertEqualsWithDelta(40.0, $hit->distance, 1e-9);
        self::assertEqualsWithDelta(-1.0, $hit->nx, 1e-9, 'the normal looks back at the ray');
        self::assertNull(Collision::raycast(0, 0, 3, 0, $shapes, 30), 'out of reach');
        self::assertNull(Collision::raycast(0, 0, 0, -1, $shapes));
        $box = Collision::raycast(0, 0, 1, 0, ['far' => $shapes['far']]);
        self::assertEqualsWithDelta(100.0, $box->distance, 1e-9);
    }

    public function testTheBroadPhaseOnlyPairsShapesThatShareACell(): void
    {
        $hash = new SpatialHash(50);
        $hash->insert('a', new Circle(10, 10, 5));
        $hash->insert('b', new Circle(20, 10, 5));
        $hash->insert('far', new Circle(500, 500, 5));
        self::assertSame([['a', 'b']], $hash->pairs());
        self::assertSame(['far'], $hash->query([490, 490, 510, 510]));
        $hash->remove('b');
        self::assertSame([], $hash->pairs());
    }

    public function testTheWorldFindsWhatTouches(): void
    {
        $w = new CollisionWorld();
        $w->reset(32);
        $w->add('wall', new Box(100, 0, 10, 200));
        $ball = $w->add('ball', new Circle(95, 50, 8));
        $w->add('lone', new Circle(10, 10, 4));
        $pairs = $w->collisions();
        self::assertCount(1, $pairs);
        self::assertSame(['wall', 'ball'], [$pairs[0][0], $pairs[0][1]], 'in the order they were added');
        self::assertSame(['wall'], $w->at(105, 100));
        self::assertSame(['wall'], $w->overlapping($ball, 'ball'));
        $ball->moveTo(40, 50);
        $w->update('ball');
        self::assertSame([], $w->collisions());
        self::assertSame('ball', $w->raycast(40, 50, 1, 0)->id, 'it starts inside the ball: that is the first thing it meets');
        self::assertSame('wall', $w->raycast(40, 50, 1, 0, INF, 'ball')->id, 'except the ball: the wall');
    }
}
