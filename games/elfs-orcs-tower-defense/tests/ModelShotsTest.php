<?php

namespace Tests;

use App\Scene\PlayScene;
use App\Td\Battle;
use App\Td\GameData;
use JumPHP\Testing\GameTestCase;
use PHPUnit\Framework\Attributes\DataProvider;

/**
 * Every tower of a side in 3D, to look at them: on the 12 platforms of the first map, its 4 towers at level 1, at
 * level 3 and specialized (var/screenshots/tests/towers-<side>.png). Needs OpenGL (skipped without it).
 */
final class ModelShotsTest extends GameTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        if (!$this->game->engine->gl()->isAvailable()) {
            self::markTestSkipped('No OpenGL here: the 3D battle is not drawn.');
        }
    }

    public static function sides(): array
    {
        return ['elves' => ['elves'], 'dwarves' => ['dwarves'], 'horsefolk' => ['horsefolk'], 'littlefolk' => ['littlefolk'], 'orcs' => ['orcs']];
    }

    #[DataProvider('sides')]
    public function testEveryTowerOfASideIsBuiltUpgradedAndSpecialized(string $faction): void
    {
        $this->game->start(PlayScene::class, ['faction' => $faction, 'map' => 'thornvale', 'countdown' => 999, 'gold' => 99999]);
        /** @var Battle $battle */
        $battle = $this->game->peek('battle');
        /** @var GameData $data */
        $data = $this->game->peek('data');
        foreach ($data->faction($faction)['towers'] as $i => $type) {
            self::assertTrue($battle->build($i, $type), "$type is built");
            foreach ([$i + 4, $i + 8] as $slot) {
                $battle->build($slot, $type);
                $battle->upgrade($slot);
                $battle->upgrade($slot);
            }
            $tower = $battle->tower($i + 8);
            self::assertTrue($battle->specialize($i + 8, $tower->specNames()[$i % 2]), "$type is specialized");
        }
        $this->game->run(40);
        (new \ReflectionProperty(PlayScene::class, 'banners'))->setValue($this->game->scene(), []);
        $this->game->run(2);
        $this->game->shot("towers-$faction");
        self::assertCount(12, $battle->towers());
    }
}
