<?php

namespace Tests;

use App\Scene\DefeatScene;
use App\Scene\PauseScene;
use App\Scene\PlayScene;
use App\Scene\VictoryScene;
use App\Td\Battle;
use JumPHP\Testing\GameTestCase;

/**
 * Game tests: the game played frame by frame, as the player sees it.
 */
final class PlayTest extends GameTestCase
{
    private function battle(): Battle
    {
        return $this->game->peek('battle');
    }

    /** A click of the mouse (or a tap) at a point of the screen. */
    private function click(float $x, float $y): void
    {
        $mouse = $this->game->engine->input->getMouse();
        $mouse->move($x, $y);
        $mouse->down(1);
        $this->game->run(1);
        $mouse->up(1);
        $this->game->run(1);
    }

    private function clickSlot(int $slot): void
    {
        $s = $this->battle()->slots[$slot];
        $this->click($s['x'], $s['y'] + PlayScene::MAP_Y);
    }

    public function testThePlayerBuildsATowerWithTheMouse(): void
    {
        $this->game->start(PlayScene::class, ['countdown' => 999]);
        $battle = $this->battle();
        $gold = $battle->gold;
        $this->clickSlot(1);
        self::assertSame(1, $this->game->peek('menuSlot'));
        // the ring: up is the first tower (the archers); a first click shows it, a second one builds it
        $s = $battle->slots[1];
        $this->click($s['x'], $s['y'] + PlayScene::MAP_Y - 28);
        self::assertSame('build:elf_archer', $this->game->peek('choice'));
        $this->game->shot('ring-open');
        $this->click($s['x'], $s['y'] + PlayScene::MAP_Y - 28);
        self::assertNotNull($battle->tower(1));
        self::assertSame('elf_archer', $battle->tower(1)->type);
        self::assertSame($gold - 70, $battle->gold);
        self::assertNull($this->game->peek('menuSlot'));
        $this->game->run(20);
    }

    public function testTheKeyboardCursorBuildsUpgradesAndSells(): void
    {
        $this->game->start(PlayScene::class, ['countdown' => 999, 'gold' => 1000]);
        $battle = $this->battle();
        $this->game->press('right', 2);
        $slot = $this->game->peek('cursor');
        self::assertNotSame(0, $slot, 'the cursor jumped to another platform');
        $this->game->press('confirm', 2);
        self::assertSame($slot, $this->game->peek('menuSlot'));
        $this->game->press('right', 2);   // the second tower: the moon sanctuary
        $this->game->press('confirm', 2);
        self::assertSame('elf_sanctuary', $battle->tower($slot)?->type);

        $this->game->press('confirm', 2);
        $this->game->press('up', 2);      // upgrade
        $this->game->press('confirm', 2);
        self::assertSame(2, $battle->tower($slot)->level);

        $gold = $battle->gold;
        $this->game->press('confirm', 2);
        $this->game->press('down', 2);    // sell
        $this->game->press('confirm', 2);
        self::assertNull($battle->tower($slot));
        self::assertSame($gold + (int)floor((90 + 80) * 0.7), $battle->gold);
    }

    public function testAnEnemyWalksTheRoadAndTakesALifeAtTheBastion(): void
    {
        $this->game->start(PlayScene::class, ['countdown' => 999, 'speed' => 3]);
        $battle = $this->battle();
        $battle->hero = null;   // it would stop the goblin
        $goblin = $battle->spawnEnemy('goblin_runner');
        $this->game->wait(2);
        self::assertGreaterThan(0, $goblin->distance);
        $this->game->shot('goblin-on-the-road');
        $this->game->wait(6);
        self::assertTrue($goblin->escaped);
        self::assertSame($battle->maxLives - 1, $battle->lives);
    }

    public function testTowersShootAndKillTheFirstWave(): void
    {
        $this->game->start(PlayScene::class, ['countdown' => 1, 'gold' => 600, 'speed' => 3]);
        $battle = $this->battle();
        foreach ([0, 1, 3] as $slot) {
            $battle->build($slot, 'elf_archer');
        }
        $battle->build(2, 'elf_songmage');
        $gold = $battle->gold;
        $this->game->wait(8);
        self::assertGreaterThan(0, $battle->kills);
        self::assertGreaterThan($gold, $battle->gold, 'the kills gave gold');
    }

    public function testTheSpeedButtonsAndTheCallWaveButton(): void
    {
        $this->game->start(PlayScene::class, []);
        $battle = $this->battle();
        $this->game->press('speed', 2);
        self::assertSame(2, $this->game->peek('speed'));
        $gold = $battle->gold;
        $this->game->press('call_wave', 2);
        self::assertSame(1, $battle->waveIndex);
        self::assertGreaterThan($gold, $battle->gold, 'calling the wave early gave gold');
        $this->game->wait(2);
        self::assertNotEmpty($battle->enemies);
    }

    public function testPauseStopsTheGameAndResumes(): void
    {
        $this->game->start(PlayScene::class, ['countdown' => 999]);
        $battle = $this->battle();
        $battle->spawnEnemy('grunt');
        $this->game->press('pause', 2);
        self::assertInstanceOf(PauseScene::class, $this->game->scene());
        $time = $battle->time;
        $this->game->wait(1);
        self::assertSame($time, $battle->time, 'nothing moves under the pause menu');
        $this->game->press('confirm', 2);   // RESUME
        self::assertInstanceOf(PlayScene::class, $this->game->scene());
        $this->game->wait(1);
        self::assertGreaterThan($time, $battle->time);
    }

    public function testTheLastWaveLostWithoutTowersShowsTheDefeat(): void
    {
        $this->game->start(PlayScene::class, ['wave' => 9, 'countdown' => 0.1, 'lives' => 1, 'speed' => 3]);
        $battle = $this->battle();
        $this->game->wait(12);
        self::assertSame('lost', $battle->state);
        $this->game->wait(1);
        self::assertInstanceOf(DefeatScene::class, $this->game->scene());
    }

    public function testTheBotHoldsTheLastWaveAndTheVictoryShowsTheStars(): void
    {
        $this->game->start(PlayScene::class, ['wave' => 9, 'countdown' => 8, 'gold' => 30000, 'demo' => true]);
        for ($i = 0; $i < 120 && !$this->game->scene() instanceof VictoryScene; $i++) {
            $this->game->wait(1);
        }
        self::assertInstanceOf(VictoryScene::class, $this->game->scene());
        self::assertSame(3, $this->game->peek('stars'));
        $this->game->wait(1.5);
    }
}
