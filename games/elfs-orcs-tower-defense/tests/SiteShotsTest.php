<?php

namespace Tests;

use App\Save\Progress;
use App\Scene\FactionSelectScene;
use App\Scene\MapSelectScene;
use App\Scene\PlayScene;
use App\Scene\TitleScene;
use JumPHP\Testing\GameTestCase;
use PHPUnit\Framework\Attributes\DataProvider;

/**
 * The pictures of the game's web site (site/): real frames of the game, in var/screenshots/tests/site-*.png.
 * tools/site/make.php then cuts them into site/images/ and assets/thumbnail.png. Run after a change of look:
 *   bin/jumphp test elfs-orcs-tower-defense   then   bin/jumphp php games/elfs-orcs-tower-defense tools/site/make.php
 */
final class SiteShotsTest extends GameTestCase
{
    public function testTheTitleAndTheMenusAreShot(): void
    {
        @unlink($this->game->engine->storage->path(Progress::FILE));
        $this->game->start(TitleScene::class);
        $this->game->run(90);
        $this->game->shot('site-title');

        $this->game->start(FactionSelectScene::class, ['faction' => 'horsefolk']);
        $this->game->run(20);
        $this->game->shot('site-sides');

        $progress = (new Progress())->load($this->game->engine->storage);
        foreach (['thornvale' => 3, 'mistfen' => 2, 'greystone' => 3, 'emberwood' => 1] as $map => $stars) {
            $progress->record('elves', $map, $stars);
        }
        $this->game->start(MapSelectScene::class, ['faction' => 'elves']);
        $this->game->run(20);
        $this->game->shot('site-maps');
        @unlink($this->game->engine->storage->path(Progress::FILE));
        self::assertTrue(true);
    }

    public static function battles(): array
    {
        return [
            'elves' => ['elves', 'thornvale', 4],
            'dwarves' => ['dwarves', 'greystone', 5],
            'horsefolk' => ['horsefolk', 'mistfen', 5],
            'littlefolk' => ['littlefolk', 'emberwood', 5],
            'orcs' => ['orcs', 'frostpeak', 4],
            'lastgate' => ['elves', 'lastgate', 8],
        ];
    }

    #[DataProvider('battles')]
    public function testABattleIsShot(string $faction, string $map, int $wave): void
    {
        $this->game->start(PlayScene::class, ['faction' => $faction, 'map' => $map, 'demo' => true, 'wave' => $wave, 'countdown' => 2, 'gold' => 1400]);
        $battle = $this->game->peek('battle');
        $bot = $this->game->peek('bot');
        // 25 s of battle without drawing it (the 3D frames are slow without a graphics card), then a few real frames
        for ($t = 0; $t < 60 * 25; $t++) {
            if ($t % 30 === 0) {
                $bot->play();
            }
            $battle->step();
            $battle->events = [];
        }
        $this->game->run(30);
        // the wave banner would hide the middle of the picture
        $banners = new \ReflectionProperty(PlayScene::class, 'banners');
        $banners->setValue($this->game->scene(), []);
        $this->game->run(2);
        $this->game->shot("site-battle-$map");
        self::assertGreaterThan(3, count($battle->towers()));
    }
}
