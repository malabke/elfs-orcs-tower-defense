<?php

namespace Tests;

use App\Scene\DemoScene;
use JumPHP\Testing\GameTestCase;

final class DemoTest extends GameTestCase
{
    public function testTheEngineFindsTheExtension(): void
    {
        $this->game->start(DemoScene::class);
        self::assertTrue($this->game->engine->extensions()->has('jumphp/physics'));
    }

    public function testABallFallsWhenYouPressConfirm(): void
    {
        $this->game->start(DemoScene::class);
        $before = $this->game->scene()->dropped;
        $this->game->press('confirm');
        $this->game->run(30);
        self::assertGreaterThan($before, $this->game->scene()->dropped);
    }
}
