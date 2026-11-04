<?php

namespace Tests;

use App\Scene\PortraitScene;
use App\Td\GameData;
use App\View3d\Units;
use JumPHP\Testing\GameTestCase;

/**
 * The portraits of the interface, photographed from the 3D models (the ring menu's towers, the hero's button, the
 * next wave's preview, the creatures of the side cards): every creature and every tower alone in PortraitScene, over
 * black then over white; the difference of the two gives each pixel's transparency. Written to
 * assets/sprites/portraits/unit-<sprite>.png and tower-<sprite>.png (SIZE x SIZE, transparent), read by
 * Sprites::drawPortrait(). Needs OpenGL (skipped without it): re-run after a change of a model.
 */
final class PortraitShotsTest extends GameTestCase
{
    private const SIZE = 128;

    protected function setUp(): void
    {
        parent::setUp();
        if (!$this->game->engine->gl()->isAvailable()) {
            self::markTestSkipped('No OpenGL here: no 3D models to photograph.');
        }
    }

    public function testEveryCreatureAndTowerHasAPortraitMadeFromItsModel(): void
    {
        $this->game->start(PortraitScene::class);
        /** @var PortraitScene $scene */
        $scene = $this->game->scene();
        self::assertTrue($scene->isReady());
        $dir = $this->game->engine->config->path('assets/sprites/portraits');
        is_dir($dir) || mkdir($dir, 0777, true);

        foreach (array_keys(Units::KINDS) as $sprite) {
            $this->photograph($scene, "$dir/unit-$sprite.png", 'unit', $sprite);
        }
        $data = GameData::load($this->game->engine->config->path());
        foreach (['elves', 'dwarves', 'horsefolk', 'littlefolk', 'orcs'] as $side) {
            $faction = $data->faction($side);
            foreach ($faction['towers'] as $type) {
                $sprite = (string)$data->tower($type)['sprite'];
                $this->photograph($scene, "$dir/tower-$sprite.png", 'tower', $sprite, (string)$faction['colors']['main']);
            }
        }
        self::assertCount(count(Units::KINDS) + 20, glob("$dir/*.png") ?: []);
    }

    private function photograph(PortraitScene $scene, string $file, string $kind, string $sprite, string $flag = '#3fa860'): void
    {
        $layers = [];
        foreach ([0, 255] as $bg) {
            $scene->pose($kind, $sprite, [$bg, $bg, $bg], 1, null, $flag);
            $this->game->run(2);
            $layers[] = imagecreatefrompng($this->game->shot("portrait-$bg"));
        }
        [$black, $white] = $layers;
        $w = imagesx($black);
        $h = imagesy($black);
        // the model with its transparency: over black c = a * colour, over white c = a * colour + (1 - a) * 255
        $out = imagecreatetruecolor($w, $h);
        imagealphablending($out, false);
        imagesavealpha($out, true);
        imagefill($out, 0, 0, imagecolorallocatealpha($out, 0, 0, 0, 127));
        [$x0, $y0, $x1, $y1] = [$w, $h, -1, -1];
        for ($y = 0; $y < $h; $y++) {
            for ($x = 0; $x < $w; $x++) {
                $b = imagecolorat($black, $x, $y);
                $l = imagecolorat($white, $x, $y);
                $diff = ((($l >> 16) & 255) - (($b >> 16) & 255) + (($l >> 8) & 255) - (($b >> 8) & 255) + ($l & 255) - ($b & 255)) / 3;
                $a = max(0.0, min(1.0, 1.0 - $diff / 255));
                if ($a < 0.03) {
                    continue;
                }
                $c = static fn (int $v): int => (int)min(255, round($v / $a));
                imagesetpixel($out, $x, $y, imagecolorallocatealpha($out, $c(($b >> 16) & 255), $c(($b >> 8) & 255), $c($b & 255),
                    (int)round(127 * (1 - $a))));
                [$x0, $y0, $x1, $y1] = [min($x0, $x), min($y0, $y), max($x1, $x), max($y1, $y)];
            }
        }
        self::assertGreaterThan(0, $x1, "$kind $sprite is in the picture");
        self::assertTrue($x0 > 0 && $y0 > 0 && $x1 < $w - 1 && $y1 < $h - 1, "$kind $sprite is whole in the picture");
        // a square around it (a small margin), scaled down smoothly
        $side = (int)ceil(max($x1 - $x0, $y1 - $y0) * 1.06) + 2;
        $square = imagecreatetruecolor(self::SIZE, self::SIZE);
        imagealphablending($square, false);
        imagesavealpha($square, true);
        imagefill($square, 0, 0, imagecolorallocatealpha($square, 0, 0, 0, 127));
        $scale = self::SIZE / $side;
        $dw = (int)round(($x1 - $x0 + 1) * $scale);
        $dh = (int)round(($y1 - $y0 + 1) * $scale);
        imagecopyresampled($square, $out, (int)((self::SIZE - $dw) / 2), (int)((self::SIZE - $dh) / 2), $x0, $y0, $dw, $dh, $x1 - $x0 + 1, $y1 - $y0 + 1);
        imagepng($square, $file, 9);
    }
}
