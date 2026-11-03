<?php

namespace Tests;

use App\Scene\PlayScene;
use App\Td\Battle;
use JumPHP\Testing\GameTestCase;

/**
 * The battle in 3D, played: the camera, the click that goes through it, what is drawn. Needs OpenGL (a window on
 * Mesa in bin/jumphp test): the tests are skipped without it.
 */
final class ThreeDTest extends GameTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        if (!$this->game->engine->gl()->isAvailable()) {
            self::markTestSkipped('No OpenGL here: the 3D battle is not drawn.');
        }
    }

    private function tap(float $x, float $y): void
    {
        $mouse = $this->game->engine->input->getMouse();
        $mouse->move($x, $y);
        $mouse->down(1);
        $this->game->run(1);
        $mouse->up(1);
        $this->game->run(1);
    }

    /** The wave banner would hide the middle of the picture. */
    private function clearBanners(): void
    {
        (new \ReflectionProperty(PlayScene::class, 'banners'))->setValue($this->game->scene(), []);
    }

    public function testTheBattleIsDrawnInThreeD(): void
    {
        $this->game->start(PlayScene::class, ['faction' => 'elves', 'countdown' => 999, 'gold' => 600]);
        self::assertTrue($this->game->peek('use3d'));
        /** @var Battle $battle */
        $battle = $this->game->peek('battle');
        foreach ([0, 1, 3, 5] as $i => $slot) {
            $battle->build($slot, ['elf_archer', 'elf_sanctuary', 'elf_songmage', 'elf_sentinel'][$i]);
        }
        foreach (['iron_orc' => 150, 'giant_bat' => 220, 'goblin_runner' => 60, 'grunt' => 330] as $type => $distance) {
            $battle->spawnEnemy($type)->distance = $distance;
        }
        $this->game->run(60);
        $this->clearBanners();
        $this->game->run(4);
        $this->game->shot('3d-elves');
    }

    public function testClickingAPlatformInThreeDOpensItsRingAndBuilds(): void
    {
        $this->game->start(PlayScene::class, ['faction' => 'elves', 'countdown' => 999]);
        $scene = $this->game->scene();
        /** @var Battle $battle */
        $battle = $this->game->peek('battle');
        $gold = $battle->gold;
        $s = $battle->slots[1];
        [$px, $py] = $scene->screenOf($s['x'], $s['y']);
        $this->tap($px, $py);
        self::assertSame(1, $this->game->peek('menuSlot'));
        $this->game->run(2);
        $this->game->shot('3d-ring');
        // the first choice, above the platform: the archers; one tap shows it, the next builds it
        $this->tap($px, $py - 28);
        self::assertSame('build:elf_archer', $this->game->peek('choice'));
        $this->tap($px, $py - 28);
        self::assertSame('elf_archer', $battle->tower(1)?->type);
        self::assertSame($gold - 70, $battle->gold);
        $this->game->run(30);
        $this->game->shot('3d-built');
    }

    public function testTheHeroIsSentToTheRoadUnderThePointer(): void
    {
        $this->game->start(PlayScene::class, ['faction' => 'elves', 'countdown' => 999]);
        $scene = $this->game->scene();
        /** @var Battle $battle */
        $battle = $this->game->peek('battle');
        $this->tap(17, $this->game->engine->sdl->getRenderer()->getHeight() - 17);
        self::assertSame('hero', $this->game->peek('aim'));
        [$sx, $sy] = $scene->screenOf(48.0, 48.0);
        $this->tap($sx, $sy);
        self::assertNull($this->game->peek('aim'));
        $this->game->wait(8);
        self::assertLessThan(24, hypot($battle->hero->x - 48, $battle->hero->y - 48));
    }

    public function testThePowerFallsOnTheGroundPointedAt(): void
    {
        $this->game->start(PlayScene::class, ['faction' => 'dwarves', 'countdown' => 999]);
        $scene = $this->game->scene();
        /** @var Battle $battle */
        $battle = $this->game->peek('battle');
        $battle->hero = null;
        $battle->powerTimer = 0.0;
        $orc = $battle->spawnEnemy('iron_orc');
        $orc->distance = 200;
        $this->game->run(10);
        [$sx, $sy] = $scene->screenOf($orc->x, $orc->y);
        $this->game->press('power', 2);
        self::assertSame('power', $this->game->peek('aim'));
        $this->game->run(2);
        $this->game->shot('3d-power-aim');
        $this->tap($sx, $sy);
        self::assertLessThan($orc->maxHp, $orc->hp, 'the rockslide fell on the orc');
    }
}
