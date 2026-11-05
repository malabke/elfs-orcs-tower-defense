<?php

namespace Tests;

use App\Scene\PortraitScene;
use App\View3d\Units;
use JumPHP\Testing\GameTestCase;

/**
 * Every creature in 3D, close, to look at them: at rest, walking and striking, in the photo studio
 * (PortraitScene), put together on contact sheets: var/screenshots/tests/creatures-<pose>.png (and one picture each:
 * creature-<sprite>-<pose>.png). Needs OpenGL (skipped without it).
 */
final class CreatureShotsTest extends GameTestCase
{
    private const CELL = 256;
    private const COLUMNS = 6;

    protected function setUp(): void
    {
        parent::setUp();
        if (!$this->game->engine->gl()->isAvailable()) {
            self::markTestSkipped('No OpenGL here: no 3D models to photograph.');
        }
    }

    public function testEveryCreatureIsPhotographedAtRestWalkingAndStriking(): void
    {
        $this->game->start(PortraitScene::class);
        /** @var PortraitScene $scene */
        $scene = $this->game->scene();
        self::assertTrue($scene->isReady());
        $sprites = array_keys(Units::KINDS);
        foreach (['idle' => 0.4, 'walk' => 0.25, 'fight' => 0.12] as $anim => $t) {
            $rows = (int)ceil(count($sprites) / self::COLUMNS);
            $sheet = imagecreatetruecolor(self::COLUMNS * self::CELL, $rows * self::CELL);
            imagefill($sheet, 0, 0, imagecolorallocate($sheet, 96, 112, 132));
            foreach ($sprites as $i => $sprite) {
                $scene->pose('unit', $sprite, [124, 142, 164], 1, null, '#3fa860', $anim === 'walk' && Units::KINDS[$sprite]['k'] === 'wings' ? 'fly' : $anim, $t);
                $this->game->run(2);
                $shot = imagecreatefrompng($this->game->shot("creature-$sprite-$anim"));
                // the middle of the picture, where the model stands
                $h = imagesy($shot);
                $w = imagesx($shot);
                $side = (int)($h * 0.92);
                imagecopyresampled($sheet, $shot, ($i % self::COLUMNS) * self::CELL, intdiv($i, self::COLUMNS) * self::CELL,
                    (int)(($w - $side) / 2), (int)(($h - $side) / 2), self::CELL, self::CELL, $side, $side);
            }
            $file = dirname($this->game->shot('creature-last')) . "/creatures-$anim.png";
            imagepng($sheet, $file);
            self::assertFileExists($file);
        }
    }

    public static function battles(): array
    {
        return [
            'horde' => ['horsefolk', 'horse_stables', ['grunt', 'goblin_runner', 'iron_orc', 'warg_rider', 'mountain_troll', 'giant_bat', 'orc_warlord', 'war_ram']],
            'alliance' => ['orcs', 'orc_barracks', ['elven_skirmisher', 'blade_dancer', 'iron_shield', 'swift_rider', 'mounted_archer', 'giant_eagle', 'barefoot_band', 'dwarf_thane']],
        ];
    }

    /** The creatures at the battle's own scale: a side's soldiers fighting, foes of every kind walking the road. */
    #[\PHPUnit\Framework\Attributes\DataProvider('battles')]
    public function testTheCreaturesWalkAndFightInTheBattle(string $faction, string $barracks, array $foes): void
    {
        $this->game->start(\App\Scene\PlayScene::class, ['faction' => $faction, 'countdown' => 999, 'gold' => 2000]);
        /** @var \App\Td\Battle $battle */
        $battle = $this->game->peek('battle');
        if ($battle->tower(0) === null) {
            $battle->build(0, $barracks);
        }
        foreach ($foes as $i => $type) {
            $battle->spawnEnemy($type)->distance = 40 + $i * 34;
        }
        $this->game->run(70);
        (new \ReflectionProperty(\App\Scene\PlayScene::class, 'banners'))->setValue($this->game->scene(), []);
        $this->game->run(2);
        $this->game->shot("creatures-battle-$faction");
        self::assertNotEmpty($battle->enemies);
    }
}
