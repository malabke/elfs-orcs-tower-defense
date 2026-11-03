<?php

namespace Tests;

use App\Save\Progress;
use App\Scene\MapSelectScene;
use App\Scene\PlayScene;
use App\Td\Battle;
use JumPHP\Testing\GameTestCase;

/**
 * The campaign screen, and the hero and the power played with the mouse and the keys.
 */
final class MapSelectTest extends GameTestCase
{
    private function progress(): Progress
    {
        return (new Progress())->load($this->game->engine->storage);
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

    public function testALockedMapCannotBePlayedAndAVictoryOpensIt(): void
    {
        @unlink($this->game->engine->storage->path(Progress::FILE));
        $this->game->start(MapSelectScene::class, ['faction' => 'horsefolk']);
        $this->game->press('right', 2);   // the marsh, not open yet
        self::assertSame(1, $this->game->peek('selected'));
        $this->game->press('confirm', 30);
        self::assertInstanceOf(MapSelectScene::class, $this->game->scene());
        $this->game->shot('campaign-locked');

        $this->progress()->record('horsefolk', 'thornvale', 3);
        $this->game->start(MapSelectScene::class, ['faction' => 'horsefolk']);
        self::assertSame(1, $this->game->peek('selected'), 'the last open map is picked');
        $this->game->press('confirm', 40);
        self::assertInstanceOf(PlayScene::class, $this->game->scene());
        self::assertSame('mistfen', $this->game->peek('battle')->mapId);
        $this->game->run(60);
        $this->game->shot('mistfen');
    }

    public function testHardModeOpensOnAWonMap(): void
    {
        @unlink($this->game->engine->storage->path(Progress::FILE));
        $this->game->start(MapSelectScene::class, ['faction' => 'elves']);
        $this->game->press('down', 2);
        $this->game->press('down', 2);     // the hard mode switch
        $this->game->press('confirm', 2);
        self::assertFalse($this->game->peek('hard'), 'not before the map is won');

        $this->progress()->record('elves', 'thornvale', 1);
        $this->game->start(MapSelectScene::class, ['faction' => 'elves', 'map' => 'thornvale']);
        $this->game->press('down', 2);
        $this->game->press('down', 2);
        $this->game->press('confirm', 2);
        self::assertTrue($this->game->peek('hard'));
        $this->game->press('up', 2);
        $this->game->press('up', 2);       // back to the first map: the one won
        self::assertSame(0, $this->game->peek('selected'));
        $this->game->shot('campaign-hard');
        $this->game->press('confirm', 40);
        /** @var Battle $battle */
        $battle = $this->game->peek('battle');
        self::assertTrue($battle->hard);
    }

    public function testTheHeroButtonThenAClickOnTheRoadSendsTheHero(): void
    {
        $this->game->start(PlayScene::class, ['faction' => 'elves', 'countdown' => 999]);
        /** @var Battle $battle */
        $battle = $this->game->peek('battle');
        $this->tap(17, $this->game->engine->sdl->getRenderer()->getHeight() - 17);
        self::assertSame('hero', $this->game->peek('aim'));
        $this->tap(40, PlayScene::MAP_Y + 48);   // the start of the road
        self::assertNull($this->game->peek('aim'));
        $this->game->wait(8);
        self::assertLessThan(30, hypot($battle->hero->x - 40, $battle->hero->y - 48));
        $this->game->shot('hero-sent');
    }

    public function testThePowerIsCastWithTheKeys(): void
    {
        $this->game->start(PlayScene::class, ['faction' => 'dwarves', 'countdown' => 999]);
        /** @var Battle $battle */
        $battle = $this->game->peek('battle');
        $battle->hero = null;
        $battle->powerTimer = 0.0;
        $orc = $battle->spawnEnemy('iron_orc');
        $this->game->run(30);
        $this->game->press('power', 2);
        self::assertSame('power', $this->game->peek('aim'));
        $this->game->shot('power-aim');
        $this->game->press('confirm', 2);
        self::assertLessThan($orc->maxHp, $orc->hp, 'the rockslide fell on it');
        self::assertFalse($battle->powerReady());
        $this->game->run(20);
    }
}
