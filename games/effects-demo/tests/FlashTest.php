<?php

namespace Tests;

use App\Scene\DemoScene;
use JumPHP\Testing\GameTestCase;

final class FlashTest extends GameTestCase
{
    public function testTheEngineFindsTheExtension(): void
    {
        $this->game->start(DemoScene::class);
        self::assertTrue($this->game->engine->extensions()->has('jumphp/effects'));
    }

    public function testAFlashLightsTheScreenThenFades(): void
    {
        $this->game->start(DemoScene::class);
        $flash = $this->game->peek('flash');
        self::assertSame(0.0, $flash->strength());
        $this->game->press('flash');
        self::assertGreaterThan(0.9, $flash->strength());
        $this->game->shot('flash');
        $this->game->run(30);
        self::assertSame(0.0, $flash->strength());
    }

    public function testAnotherSceneIsChosenUnderTheCoverThenRevealed(): void
    {
        $this->game->start(DemoScene::class);
        $transition = $this->game->peek('transition');
        self::assertFalse($transition->isActive());
        $this->game->press('confirm');
        $this->game->run(5);
        self::assertTrue($transition->isRunning(), 'covering');
        $this->game->run(30);                                  // 0.5 s: covered, the scene changed, now revealing
        $scene = $this->game->scene();
        self::assertSame(1, $scene->changes);
        $this->game->shot('transition');
        $this->game->run(60);
        self::assertFalse($transition->isActive(), 'revealed: nothing left on the screen');
    }
}
