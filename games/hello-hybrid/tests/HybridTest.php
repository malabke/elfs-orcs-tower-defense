<?php

namespace Tests;

use App\Scene\BallsScene;
use App\Settings;
use JumPHP\Testing\GameTestCase;

/** The game side, played headless (a plain game there: no native side). */
final class HybridTest extends GameTestCase
{
    public function testTheBallsFollowTheWantedNumber(): void
    {
        $this->game->start(BallsScene::class);
        $this->game->run(2);
        self::assertSame(12, $this->game->scene()->count());
        Settings::$wanted = 30;
        $this->game->run(2);
        self::assertSame(30, $this->game->scene()->count());
    }

    public function testSpaceAddsABallAndPausesStopThem(): void
    {
        $this->game->start(BallsScene::class);
        $this->game->press('add');
        $this->game->run(1);
        self::assertSame(13, $this->game->scene()->count());
        $this->game->press('pause');
        self::assertTrue(Settings::$paused);
    }

    public function testWithoutANativeSideEscapeQuitsAtOnce(): void
    {
        $this->game->start(BallsScene::class);
        $this->game->press('back');
        self::assertFalse(\JumPHP\Kernel\Engine::get()->isRunning());
    }
}
