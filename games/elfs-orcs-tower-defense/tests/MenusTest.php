<?php

namespace Tests;

use App\Scene\DefeatScene;
use App\Scene\MapSelectScene;
use App\Scene\PauseScene;
use App\Scene\PlayScene;
use App\Scene\VictoryScene;
use JumPHP\Testing\GameTestCase;

/**
 * The pause, victory and defeat menus: painted panels of wooden buttons, pressed with the pointer or the keys.
 */
final class MenusTest extends GameTestCase
{
    private const RESTART = ['view' => 'flat', 'faction' => 'elves', 'map' => 'thornvale', 'countdown' => 999];

    /** A tap in the middle of the wooden button of item $i of the menu on top. */
    private function tapButton(int $i): void
    {
        $scene = $this->game->scene();
        [$x, $y, $w, $h] = (new \ReflectionMethod($scene, 'rowRect'))->invoke($scene, $i);
        $mouse = $this->game->engine->input->getMouse();
        $mouse->move($x + $w / 2, $y + $h / 2);
        $mouse->down(1);
        $this->game->run(1);
        $mouse->up(1);
        $this->game->run(1);
    }

    public function testThePauseMenuIsAPanelOfButtonsAndResumeGoesBackToTheBattle(): void
    {
        $this->game->start(PlayScene::class, self::RESTART);
        $this->game->press('pause', 2);
        self::assertInstanceOf(PauseScene::class, $this->game->scene());
        $this->game->shot('menu-pause');
        $this->tapButton(0);   // RESUME
        $this->game->run(2);
        self::assertInstanceOf(PlayScene::class, $this->game->scene());
    }

    public function testTheVictoryShowsItsStarsAndItsButtonsLeadOn(): void
    {
        $this->game->start(PlayScene::class, self::RESTART);
        $this->game->engine->scenes->push(VictoryScene::class, ['stars' => 2, 'lives' => 14, 'restart' => self::RESTART]);
        $this->game->wait(1.5);
        self::assertInstanceOf(VictoryScene::class, $this->game->scene());
        $this->game->shot('menu-victory');
        $this->tapButton(2);   // NEXT MAP, PLAY AGAIN, MAPS
        $this->game->run(40);
        self::assertInstanceOf(MapSelectScene::class, $this->game->scene());
    }

    public function testTheDefeatOffersToRetry(): void
    {
        $this->game->start(PlayScene::class, self::RESTART);
        $this->game->engine->scenes->push(DefeatScene::class, ['wave' => 7, 'restart' => self::RESTART]);
        $this->game->run(10);
        $this->game->shot('menu-defeat');
        $this->game->press('confirm', 40);   // RETRY, picked first
        self::assertInstanceOf(PlayScene::class, $this->game->scene());
    }
}
