<?php

namespace Tests;

use App\Scene\FactionSelectScene;
use App\Scene\PlayScene;
use App\Scene\TitleScene;
use JumPHP\Testing\GameTestCase;
use PHPUnit\Framework\Attributes\DataProvider;

/**
 * The game in other languages: the title and a battle with the build ring open, photographed
 * (var/screenshots/tests/lang-<code>-*.png) to check that the longer texts still fit.
 */
final class LanguagesTest extends GameTestCase
{
    public static function languages(): array
    {
        return [['fr'], ['de'], ['ru'], ['ja']];
    }

    #[DataProvider('languages')]
    public function testTheGameIsShownInTheLanguage(string $lang): void
    {
        $engine = $this->game->engine;
        $engine->lang->setLang($lang);
        $this->game->start(TitleScene::class);
        $this->game->run(30);
        $this->game->shot("lang-$lang-title");
        self::assertNotSame('PLAY', \JumPHP\Util\Lang::t('PLAY'));

        $this->game->start(FactionSelectScene::class, ['faction' => 'horsefolk']);
        $this->game->run(10);
        $this->game->shot("lang-$lang-sides");

        $this->game->start(PlayScene::class, ['countdown' => 20, 'gold' => 400]);
        $battle = $this->game->peek('battle');
        $battle->build(0, 'elf_archer');
        $this->game->press('right', 2);
        $this->game->press('confirm', 2);
        $this->game->press('down', 2);
        $this->game->run(240);   // the opening banners are gone
        $this->game->shot("lang-$lang-play");
        self::assertNotNull($this->game->peek('choice'));
        $engine->lang->setLang('en');
    }
}
