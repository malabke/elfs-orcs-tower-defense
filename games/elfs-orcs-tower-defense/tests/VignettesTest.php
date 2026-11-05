<?php

namespace Tests;

use App\Scene\FactionSelectScene;
use App\Scene\MapSelectScene;
use App\Scene\PauseScene;
use App\Scene\TitleScene;
use App\Td\GameData;
use App\View3d\Diorama;
use App\View3d\Mesh;
use App\View3d\Rig;
use App\View3d\Towers;
use JumPHP\Testing\GameTestCase;

/**
 * The living 3D vignettes of the side cards: each side's floating island, its four towers and its foes walking
 * round them, in a 3D layer over the 2D screen; the cards' pictures without WebGL.
 */
final class VignettesTest extends GameTestCase
{
    public function testEverySideCardShowsItsIslandIn3d(): void
    {
        $this->game->start(FactionSelectScene::class, ['faction' => 'elves']);
        if (!$this->game->engine->gl()->isAvailable()) {
            self::markTestSkipped('no OpenGL here');
        }
        self::assertTrue($this->game->peek('use3d'));
        self::assertSame('above', $this->game->peek('layer', $this->game->engine->gl())['position']);
        $this->game->wait(0.5);
        $this->game->shot('vignettes');
        // the picked card's camera turns faster, the foes walk on
        $this->game->press('right', 2);
        self::assertSame(1, $this->game->peek('selected'));
        $this->game->wait(2.5);
        $this->game->shot('vignettes-later');
    }

    public function testTheVignettesDarkenWithTheFadeAndGoAwayWithTheScreen(): void
    {
        $gl = $this->game->engine->gl();
        $this->game->start(FactionSelectScene::class, ['faction' => 'elves']);
        if (!$gl->isAvailable()) {
            self::markTestSkipped('no OpenGL here');
        }
        $this->game->wait(0.5);
        $this->game->press('confirm', 8);       // to the side's maps: the screen goes dark
        self::assertLessThan(0.8, $this->game->peek('shade'));
        $this->game->shot('vignettes-fading');
        $this->game->run(40);
        self::assertInstanceOf(MapSelectScene::class, $this->game->scene());
        self::assertNull($this->game->peek('layer', $gl), 'no 3D layer left over the maps');

        // back to the title: its own 3D, under the 2D
        $this->game->start(FactionSelectScene::class, ['faction' => 'elves']);
        $this->game->press('back', 40);
        self::assertInstanceOf(TitleScene::class, $this->game->scene());
        self::assertSame('below', $this->game->peek('layer', $gl)['position']);
    }

    public function testTheVignettesHideUnderThePauseMenu(): void
    {
        // the pause menu pushed when the window loses the focus: the 3D layer, over all the 2D, would cover it
        $side = $this->game->start(FactionSelectScene::class, ['faction' => 'elves']);
        $this->game->engine->scenes->push(PauseScene::class);
        $this->game->run(2);
        self::assertSame(0.0, $this->game->peek('shade', $side));
        $this->game->shot('vignettes-paused');
        $this->game->engine->scenes->pop();
        $this->game->run(2);
        self::assertSame(1.0, $this->game->peek('shade', $side));
    }

    public function testTheCameraKeepsTheTallestTowerAndTheIslandsTipInTheFrame(): void
    {
        // Diorama::TALLEST is the top of the tallest tower of all
        $data = GameData::load(dirname(__DIR__));
        $tallest = 0.0;
        foreach ($data->factionIds() as $id) {
            $faction = $data->faction($id);
            foreach ($faction['towers'] as $type) {
                $mesh = Towers::build((string)$data->tower($type)['sprite'], 3, null, (string)$faction['colors']['main']);
                for ($i = 1, $n = count($mesh->v); $i < $n; $i += Mesh::STRIDE) {
                    $tallest = max($tallest, $mesh->v[$i]);
                }
            }
        }
        self::assertLessThanOrEqual(Diorama::TALLEST, $tallest);

        // whatever the frame's height (the texts' room) and the camera's sway: the tallest tower on every spot,
        // the island's rim and the tip of its underside are seen whole
        $k = Diorama::SCALE;
        foreach ([[81.0, 65.0], [81.0, 50.0], [81.0, 36.0]] as [$w, $h]) {
            [$target, $distance] = Diorama::camera($w / $h);
            foreach ([-Diorama::SWAY, 0.0, Diorama::SWAY] as $yaw) {
                $rig = new Rig($w, $h);
                $rig->orbit($target, $distance, Diorama::PITCH, $yaw);
                $points = [[0.0, 0.0, -Diorama::DEPTH * $k]];
                foreach (Diorama::SPOTS as [$x, $z]) {
                    $points[] = [$x * $k, $z * $k, Diorama::TALLEST * Diorama::TOWER * $k];
                }
                for ($a = 0; $a < 16; $a++) {
                    $points[] = [cos($a * M_PI / 8) * Diorama::RADIUS * $k, sin($a * M_PI / 8) * Diorama::RADIUS * $k, 0.0];
                }
                foreach ($points as [$x, $z, $y]) {
                    [$sx, $sy] = $rig->screen($x, $z, $y);
                    self::assertTrue($sx >= 0 && $sx <= $w && $sy >= 0 && $sy <= $h,
                        sprintf('(%.0f, %.0f, %.0f) in a %dx%d frame, sway %.2f: at (%.1f, %.1f)', $x, $y, $z, $w, $h, $yaw, $sx, $sy));
                }
            }
        }
    }

    public function testWithoutWebGlTheCardsShowTheirPictures(): void
    {
        $this->game->start(FactionSelectScene::class, ['faction' => 'orcs', 'view' => 'flat']);
        self::assertFalse($this->game->peek('use3d'));
        self::assertNull($this->game->peek('layer', $this->game->engine->gl()));
        $this->game->shot('vignettes-flat');
    }
}
