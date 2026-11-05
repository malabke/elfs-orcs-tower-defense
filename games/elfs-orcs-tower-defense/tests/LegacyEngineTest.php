<?php

namespace Tests;

use App\Scene\FactionSelectScene;
use App\Scene\PlayScene;
use App\Scene\TitleScene;
use App\Td\Battle;
use App\Ui\Hd;
use App\Ui\Paint;
use JumPHP\Testing\GameTestCase;

/**
 * The playground runs the game on its shared engine, the official release of the repository's VERSION, which may not
 * have this project's engine changes yet (HD 2D, alpha composing, TtfFont's options): the game must still start and
 * play there, the retro way (pixel font, 3D shown through a key colour). Hd::$legacy makes it behave so here.
 */
final class LegacyEngineTest extends GameTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        if (!$this->game->engine->gl()->isAvailable()) {
            self::markTestSkipped('No OpenGL here: the 3D battle is not drawn.');
        }
        Hd::$legacy = true;
    }

    protected function tearDown(): void
    {
        parent::tearDown();
        Hd::$legacy = false;
    }

    public function testOnAnEngineWithoutHdTheTitleAndTheBattleUseTheKeyColour(): void
    {
        $this->game->start(TitleScene::class);
        $this->game->run(20);
        self::assertTrue(Paint::$keyed, 'the 3D is shown through the key colour');
        $this->game->shot('legacy-title');

        $this->game->start(PlayScene::class, ['faction' => 'elves', 'countdown' => 999, 'gold' => 600]);
        self::assertTrue($this->game->peek('use3d'));
        /** @var Battle $battle */
        $battle = $this->game->peek('battle');
        $battle->build(0, 'elf_archer');
        $battle->spawnEnemy('grunt')->distance = 120;
        $this->game->run(40);
        $this->game->shot('legacy-battle');
        self::assertTrue(Paint::$keyed);
        self::assertSame([0, 0, 0, 90], \App\Ui\World3d::veil([20, 26, 46, 90]), 'only black is translucent over the 3D');
    }

    public function testOnAnEngineWithoutHdTheSideCardsStillShowTheirVignettes(): void
    {
        // their 3D layer is over the 2D ('above'): no alpha composing needed; the pixel font's texts leave their room
        $this->game->start(FactionSelectScene::class, ['faction' => 'dwarves']);
        $this->game->run(20);
        self::assertTrue($this->game->peek('use3d'));
        self::assertFalse(Paint::$keyed);
        $this->game->shot('legacy-sides');
    }
}
