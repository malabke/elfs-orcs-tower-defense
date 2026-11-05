<?php

namespace Tests;

use App\Scene\DemoScene;
use JumPHP\Collision\Collision;
use JumPHP\Testing\GameTestCase;

final class DemoTest extends GameTestCase
{
    public function testTheEngineFindsTheExtension(): void
    {
        $this->game->start(DemoScene::class);
        self::assertTrue($this->game->engine->extensions()->has('jumphp/collision'));
    }

    public function testThePlayerCannotWalkThroughTheWall(): void
    {
        $this->game->start(DemoScene::class);
        $player = $this->game->peek('player');
        $world = $this->game->peek('world');
        $this->game->hold('right', 150);                       // 2.5 s to the right, from x = 40: the wall is at x = 150
        self::assertLessThanOrEqual(150.0 + 1e-6, $player->x + $player->radius, 'stopped by the wall');
        self::assertGreaterThan(100, $player->x, 'and it did walk up to it');
        self::assertNull(Collision::test($player, $world->get('wall')), 'and not inside it');
        $this->game->shot('wall');
    }

    public function testBeingPushedIsCounted(): void
    {
        $this->game->start(DemoScene::class, ['demo' => true]);
        $this->game->run(600);                                 // the smoke test's ten seconds: the demo walks into things
        self::assertGreaterThan(0, $this->game->scene()->pushes);
    }
}
