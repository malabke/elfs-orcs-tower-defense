<?php

namespace Tests;

use App\Scene\FactionSelectScene;
use App\Scene\MapSelectScene;
use App\Scene\PlayScene;
use App\Scene\TitleScene;
use App\Td\Battle;
use JumPHP\Testing\GameTestCase;
use PHPUnit\Framework\Attributes\DataProvider;

/**
 * Milestone 2 played: the side screen, the orcs against the alliance, every side's demo.
 */
final class FactionSelectTest extends GameTestCase
{
    public function testPlayOnTheTitleOpensTheSideScreen(): void
    {
        $this->game->start(TitleScene::class);
        $this->game->press('confirm', 40);   // PLAY, then the fade
        self::assertInstanceOf(FactionSelectScene::class, $this->game->scene());
        $this->game->shot('side-screen');
    }

    public function testChoosingTheOrcsBringsTheAllianceAndOtherSidesTheHorde(): void
    {
        $this->game->start(FactionSelectScene::class, ['faction' => 'elves']);
        for ($i = 0; $i < 4; $i++) {
            $this->game->press('right', 2);
        }
        self::assertSame(4, $this->game->peek('selected'));
        $this->game->press('confirm', 40);
        // then the side's maps: the first one is open
        self::assertInstanceOf(MapSelectScene::class, $this->game->scene());
        self::assertSame('orcs', $this->game->peek('faction'));
        $this->game->press('confirm', 40);
        self::assertInstanceOf(PlayScene::class, $this->game->scene());
        /** @var Battle $battle */
        $battle = $this->game->peek('battle');
        self::assertSame('orcs', $battle->faction);
        self::assertSame('alliance', $battle->enemySide);

        $this->game->start(FactionSelectScene::class, ['faction' => 'dwarves']);
        $this->game->press('confirm', 40);
        $this->game->press('confirm', 40);
        $battle = $this->game->peek('battle');
        self::assertSame('dwarves', $battle->faction);
        self::assertSame('horde', $battle->enemySide);
    }

    public function testATapPicksACardAndASecondTapPlaysIt(): void
    {
        $this->game->start(FactionSelectScene::class, ['faction' => 'elves']);
        $mouse = $this->game->engine->input->getMouse();
        $tap = function (float $x, float $y) use ($mouse): void {
            $mouse->move($x, $y);
            $mouse->down(1);
            $this->game->run(1);
            $mouse->up(1);
            $this->game->run(1);
        };
        $tap(300, 120);   // the fourth card: the little folk
        self::assertSame(3, $this->game->peek('selected'));
        $tap(300, 120);
        $this->game->run(40);
        self::assertSame('littlefolk', $this->game->peek('faction'));
    }

    public function testOrcSoldiersStopTheAllianceOnTheRoad(): void
    {
        $this->game->start(PlayScene::class, ['view' => 'flat', 'faction' => 'orcs', 'countdown' => 999, 'speed' => 2]);
        /** @var Battle $battle */
        $battle = $this->game->peek('battle');
        $battle->build(0, 'orc_barracks');
        $battle->build(1, 'orc_pitch');
        $rider = $battle->spawnEnemy('swift_rider');
        $band = $battle->spawnEnemy('barefoot_band');
        $this->game->wait(2.5);
        $this->game->shot('orc-soldiers');
        $this->game->wait(4);
        self::assertTrue($rider->dead && $band->dead, 'the soldiers and the pitch pit killed them');
        self::assertSame($battle->maxLives, $battle->lives);
    }

    public static function sides(): array
    {
        return [['dwarves'], ['horsefolk'], ['littlefolk'], ['orcs']];
    }

    #[DataProvider('sides')]
    public function testTheDemoPlaysWithEverySide(string $faction): void
    {
        $this->game->start(PlayScene::class, ['view' => 'flat', 'faction' => $faction, 'demo' => true, 'gold' => 1500]);
        $this->game->wait(25);
        $battle = $this->game->peek('battle');
        self::assertGreaterThan(3, count($battle->towers()));
        self::assertGreaterThan(0, $battle->kills);
        $this->game->shot("demo-$faction");
    }
}
