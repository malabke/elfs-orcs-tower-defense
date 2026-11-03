<?php

namespace App\Scene;

use App\Td\GameData;
use App\Ui\GameMenuScene;
use JumPHP\Kernel\Platform;
use JumPHP\Renderer\Renderer;
use JumPHP\Util\Lang;

/**
 * First screen: the title over the first map, the two armies, and the main menu.
 */
final class TitleScene extends GameMenuScene
{
    protected string $title = 'ELFS & ORCS';
    protected float $menuTop = 0.5;
    protected array $titleColor = [250, 230, 150];
    private float $time = 0.0;

    public function enter(array $params = []): void
    {
        parent::enter($params);
        $this->time = 0.0;
    }

    protected function items(): array
    {
        $items = [
            [Lang::t('PLAY'), null, fn () => $this->scenes()->switchTo(FactionSelectScene::class)],
            [Lang::t('OPTIONS'), null, fn () => $this->scenes()->push(OptionsScene::class)],
        ];
        if (Platform::isDesktop()) {
            $items[] = [Lang::t('QUIT'), null, fn () => $this->engine->quit()];
        }
        return $items;
    }

    protected function subtitles(): array
    {
        return [[Lang::t('TOWER DEFENSE'), [170, 220, 255]], [Lang::t('CHOOSE YOUR SIDE. HOLD YOUR GROUND.'), [200, 220, 170]]];
    }

    public function resume(mixed $result = null): void
    {
        // back from the options: the language may have changed
        $this->fonts->apply($this->engine);
        $this->refresh();
        $this->layoutMenu();
    }

    public function update(float $dt): void
    {
        $this->time += $dt;
        parent::update($dt);
    }

    protected function renderBackground(Renderer $r): void
    {
        $data = GameData::load($this->engine->config->path());
        $this->sprites->drawMap($r, 'thornvale', $data->map('thornvale'), $data->faction('elves')['colors'], 0, 30);
        $r->fillRect(0, 0, $this->width(), 30, [12, 18, 14]);
        $r->fillRect(0, 0, $this->width(), $this->height(), [8, 10, 20, 160]);
        // the horde marches on the elves' towers
        $w = $this->width();
        $march = fmod($this->time * 6, 30);
        foreach (['troll', 'warg', 'iron_orc', 'grunt', 'goblin', 'grunt'] as $i => $sprite) {
            $this->sprites->drawEnemy($r, $sprite, (int)($this->time * 5) + $i, $w - 30 - $i * 26 - $march, 250, true);
        }
        foreach (['archer', 'sanctuary', 'sentinel', 'songmage'] as $i => $sprite) {
            $this->sprites->drawTower($r, $sprite, 3, null, 26 + $i * 32, 264);
        }
    }
}
