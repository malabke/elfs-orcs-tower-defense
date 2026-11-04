<?php

namespace Tests;

use App\Scene\DemoScene;
use JumPHP\Testing\GameTestCase;

final class FlashTest extends GameTestCase
{
    public function testTheEngineFindsTheExtension(): void
    {
        $this->game->start(DemoScene::class);
        self::assertTrue($this->game->engine->extensions()->has('jumphp-extensions/skeleton-extension'));
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
}
