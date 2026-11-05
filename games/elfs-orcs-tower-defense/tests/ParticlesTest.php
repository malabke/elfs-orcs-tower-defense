<?php

namespace Tests;

use App\Scene\PlayScene;
use App\Td\Battle;
use App\View3d\Particles;
use JumPHP\Testing\GameTestCase;

/** The battle's particles: how they live (plain unit tests), and that the battle makes them (a game test, in 3D). */
final class ParticlesTest extends GameTestCase
{
    public function testABurstFliesFallsAndFadesAway(): void
    {
        $p = new Particles();
        $p->burst(100.0, 10.0, 50.0, 6, [1.0, 0.5, 0.2], 20.0, 10.0, 0.5, 1.0);
        self::assertSame(6, $p->count());
        $p->update(0.1);
        foreach ($p->list as $one) {
            self::assertNotEquals([100.0, 50.0], [$one['x'], $one['z']], 'it moved');
            self::assertGreaterThanOrEqual(0.4, $one['y'], 'never under the ground');
        }
        for ($i = 0; $i < 60; $i++) {
            $p->update(1 / 60);
        }
        self::assertSame(0, $p->count(), 'all gone after their life');
    }

    public function testAParticleFadesInThenOut(): void
    {
        $p = ['age' => 0.0, 'life' => 1.0, 'size' => 2.0];
        self::assertSame(0.0, Particles::look($p)[0]);
        self::assertGreaterThan(0.8, Particles::look(['age' => 0.2] + $p)[0]);
        self::assertLessThan(0.2, Particles::look(['age' => 0.95] + $p)[0]);
        self::assertLessThan(2.0, Particles::look(['age' => 0.9] + $p)[1], 'it shrinks');
    }

    public function testThereAreNeverTooManyParticles(): void
    {
        $p = new Particles();
        for ($i = 0; $i < 100; $i++) {
            $p->power(200.0, 100.0, 40.0);
        }
        self::assertSame(Particles::MAX, $p->count());
    }

    public function testTheRollsStayBetweenTheirBounds(): void
    {
        $p = new Particles(123);
        for ($i = 0; $i < 2000; $i++) {
            $v = $p->rand(-3.0, 5.0);
            self::assertTrue($v >= -3.0 && $v < 5.0);
        }
    }

    public function testBuildingAndShootingThrowDustAndSparksInTheBattle(): void
    {
        if (!$this->game->engine->gl()->isAvailable()) {
            self::markTestSkipped('No OpenGL here: particles are for the 3D battle.');
        }
        $this->game->start(PlayScene::class, ['faction' => 'elves', 'map' => 'thornvale', 'countdown' => 1, 'gold' => 2000]);
        /** @var Battle $battle */
        $battle = $this->game->peek('battle');
        /** @var Particles $particles */
        $particles = $this->game->peek('particles');
        self::assertSame(0, $particles->count());
        self::assertTrue($battle->build(0, 'elf_archer'));
        $this->game->run(2);
        self::assertGreaterThan(10, $particles->count(), 'dust and motes where the tower rises');

        // the first wave comes into the towers' range: hits and kills make sparks and puffs
        for ($slot = 1; $slot < count($battle->slots); $slot++) {
            $battle->build($slot, 'elf_songmage');
        }
        $this->game->run(60);
        $kills = 0;
        for ($i = 0; $i < 60 && $kills === 0; $i++) {
            $before = $battle->gold;
            $this->game->run(20);
            $kills += $battle->gold > $before ? 1 : 0;
        }
        self::assertGreaterThan(0, $kills, 'the towers killed something');
        self::assertGreaterThan(3, $particles->count(), 'sparks, dust and coins over the fight');
        $this->game->shot('particles');
    }

    public function testTheSidesPowerFallsAsAShowerOfLight(): void
    {
        if (!$this->game->engine->gl()->isAvailable()) {
            self::markTestSkipped('No OpenGL here: particles are for the 3D battle.');
        }
        $this->game->start(PlayScene::class, ['faction' => 'dwarves', 'map' => 'thornvale', 'countdown' => 999, 'gold' => 2000]);
        (new \ReflectionProperty(PlayScene::class, 'banners'))->setValue($this->game->scene(), []);
        /** @var Battle $battle */
        $battle = $this->game->peek('battle');
        /** @var Particles $particles */
        $particles = $this->game->peek('particles');
        $battle->powerTimer = 0.0;
        $road = $battle->nearestRoad(240.0, 140.0);
        $battle->spawnEnemy('grunt');
        self::assertTrue($battle->castPower($road['x'], $road['y']));
        self::assertTrue($battle->build(4, 'dwarf_slingshot'));
        $this->game->run(8);
        self::assertGreaterThan(30, $particles->count());
        $this->game->shot('particles-power');
    }
}
