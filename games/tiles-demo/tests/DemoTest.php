<?php

namespace Tests;

use App\Scene\DemoScene;
use JumPHP\Testing\GameTestCase;

final class DemoTest extends GameTestCase
{
    public function testTheEngineFindsTheExtension(): void
    {
        $this->game->start(DemoScene::class);
        self::assertTrue($this->game->engine->extensions()->has('jumphp/tiles'));
    }

    public function testAnotherSeedMakesAnotherCaveWithAWay(): void
    {
        $this->game->start(DemoScene::class);
        $scene = $this->game->scene();
        $before = $scene->grid->toRows();
        self::assertNotEmpty($scene->path);
        $this->game->press('regenerate');
        self::assertSame(2, $scene->seed);
        self::assertNotSame($before, $scene->grid->toRows());
    }

    public function testTabSwitchesToTheDungeon(): void
    {
        $this->game->start(DemoScene::class);
        $this->game->press('mode');
        self::assertTrue($this->game->scene()->dungeon);
        self::assertNotEmpty($this->game->scene()->path);
    }
}
