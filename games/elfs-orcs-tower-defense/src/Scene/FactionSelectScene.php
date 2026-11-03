<?php

namespace App\Scene;

use App\Save\Progress;
use App\Td\GameData;
use App\Ui\FontProvider;
use App\Ui\Sfx;
use App\Ui\Sprites;
use App\Ui\Text;
use JumPHP\Renderer\Renderer;
use JumPHP\Scene\AbstractScene;
use JumPHP\Util\Lang;

/**
 * The side screen: five cards (name, towers, what sets the side apart, who attacks it, the stars won).
 * Left / right (or a tap) picks a card, confirm (or a tap on the picked card) plays it, back returns to the title.
 */
final class FactionSelectScene extends AbstractScene
{
    private const CARD_Y = 36;
    private const CARD_H = 200;
    private const GAP = 4;

    private GameData $data;
    /** @var list<string> */
    private array $factions = [];
    private int $selected = 0;
    private float $time = 0.0;

    public function __construct(private FontProvider $fonts, private Sprites $sprites, private Progress $progress)
    {
    }

    public function enter(array $params = []): void
    {
        $this->fonts->apply($this->engine);
        $this->sprites->attach($this->sdl(), $this->engine->config->path());
        $this->progress->load($this->engine->storage);
        Sfx::load($this->sound(), $this->engine->config->path());
        $this->data = GameData::load($this->engine->config->path());
        $this->factions = $this->data->factionIds();
        $last = (string)$this->engine->options->get('faction', 'elves');
        $this->selected = max(0, (int)array_search($params['faction'] ?? $last, $this->factions, true));
        $this->time = 0.0;
    }

    public function update(float $dt): void
    {
        $this->time += $dt;
        $actions = $this->actions();
        $mouse = $this->engine->input->getMouse();
        if ($mouse->isPressed()) {
            $this->click($mouse->x, $mouse->y);
            return;
        }
        if ($actions->isPressed('back')) {
            $this->scenes()->switchTo(TitleScene::class);
            return;
        }
        $n = count($this->factions);
        if ($actions->isPressed('left') || $actions->isPressed('right')) {
            $this->selected = ($this->selected + ($actions->isPressed('left') ? -1 : 1) + $n) % $n;
            $this->sound()->playSfx('menu_move');
        }
        if ($actions->isPressed('confirm')) {
            $this->play();
        }
    }

    private function click(float $x, float $y): void
    {
        [$bx, $by, $bw, $bh] = $this->backButton();
        if ($x >= $bx && $x < $bx + $bw && $y >= $by && $y < $by + $bh) {
            $this->scenes()->switchTo(TitleScene::class);
            return;
        }
        foreach ($this->factions as $i => $faction) {
            [$cx, $cy, $cw, $ch] = $this->card($i);
            if ($x >= $cx && $x < $cx + $cw && $y >= $cy && $y < $cy + $ch) {
                if ($i === $this->selected) {
                    $this->play();
                } else {
                    $this->selected = $i;
                    $this->sound()->playSfx('menu_move');
                }
                return;
            }
        }
    }

    private function play(): void
    {
        $faction = $this->factions[$this->selected];
        $this->engine->options->set('faction', $faction);
        $this->sound()->playSfx('menu_confirm');
        $this->scenes()->switchTo(MapSelectScene::class, ['faction' => $faction]);
    }

    /** @return array{0: float, 1: float, 2: float, 3: float} */
    private function card(int $i): array
    {
        $n = max(1, count($this->factions));
        $w = (int)floor(($this->width() - 16 - self::GAP * ($n - 1)) / $n);
        $lift = $i === $this->selected ? 4 : 0;
        return [8 + $i * ($w + self::GAP), self::CARD_Y - $lift, $w, self::CARD_H];
    }

    /** @return array{0: float, 1: float, 2: float, 3: float} */
    private function backButton(): array
    {
        $w = $this->font()->width(Lang::t('BACK')) + 20;
        return [8, $this->height() - 28, max(48, $w), 24];
    }

    public function render(Renderer $r): void
    {
        $font = $this->font();
        $w = $this->width();
        $this->sprites->drawMap($r, 'thornvale', $this->data->map('thornvale'), $this->data->faction($this->factions[$this->selected])['colors'], 0, 30);
        $r->fillRect(0, 0, $w, $this->height(), [8, 8, 16, 200]);
        $title = Lang::t('CHOOSE YOUR SIDE');
        $font->draw($r, $title, $w / 2, 8, Text::fitScale($font, $title, $w - 16, $font->height() <= 8 ? 2 : 1), [250, 230, 150], 'center', [0, 0, 0]);

        foreach ($this->factions as $i => $id) {
            $this->drawCard($r, $i, $id);
        }

        [$bx, $by, $bw, $bh] = $this->backButton();
        $r->fillRect($bx, $by, $bw, $bh, [20, 16, 16]);
        $r->fillRect($bx + 1, $by + 1, $bw - 2, $bh - 2, [70, 56, 40]);
        $font->draw($r, Lang::t('BACK'), $bx + $bw / 2, $by + $bh / 2 - $font->height() / 2, 1, [240, 230, 210], 'center');
        $hint = Lang::t('PRESS AGAIN TO CONFIRM');
        Text::draw($r, $font, $hint, $bx + $bw + 12, $by + $bh / 2 - $font->height() / 2, $w - $bw - 28, [160, 160, 170]);
    }

    private function drawCard(Renderer $r, int $i, string $id): void
    {
        $font = $this->font();
        $faction = $this->data->faction($id);
        $colors = $faction['colors'];
        [$x, $y, $w, $h] = $this->card($i);
        $chosen = $i === $this->selected;
        $r->fillRect($x, $y, $w, $h, $chosen ? $colors['light'] : [20, 18, 26]);
        $r->fillRect($x + 2, $y + 2, $w - 4, $h - 4, [14, 12, 20, 235]);
        $r->fillRect($x + 2, $y + 2, $w - 4, 4, $colors['main']);
        $inner = $w - 8;
        $ty = $y + 10;
        $ty += Text::draw($r, $font, Lang::t($faction['name']), $x + $w / 2, $ty, $inner, [255, 225, 120], 'center') + 4;

        // the four towers, at their best level
        $towers = $faction['towers'];
        $tw = $inner / max(1, count($towers));
        foreach ($towers as $k => $tower) {
            $scale = min(0.75, $tw / 28);
            $this->sprites->drawTower($r, (string)$this->data->tower($tower)['sprite'], 3, null, $x + 4 + $tw * ($k + 0.5), $ty + 40 * $scale, $scale);
        }
        $ty += 34;

        $ty += Text::draw($r, $font, Lang::t($faction['motto']), $x + $w / 2, $ty, $inner, [220, 230, 240], 'center') + 6;

        // who comes
        $enemy = $faction['enemy'] === 'alliance' ? Lang::t('AGAINST THE ALLIANCE') : Lang::t('AGAINST THE HORDE');
        $ty += Text::draw($r, $font, $enemy, $x + $w / 2, $ty, $inner, [240, 150, 130], 'center') + 4;
        $sprites = $faction['enemy'] === 'alliance' ? ['elf', 'dwarf', 'rider'] : ['grunt', 'goblin', 'warg'];
        foreach ($sprites as $k => $sprite) {
            $this->sprites->drawEnemy($r, $sprite, (int)($this->time * 4) + $k, $x + $w / 2 + ($k - 1) * 22, $ty + 8, true, 0.9);
        }

        // the best stars won on the map
        $stars = $this->progress->stars($id, 'thornvale');
        for ($s = 0; $s < 3; $s++) {
            $this->sprites->drawIcon($r, $s < $stars ? 'star' : 'star_off', $x + $w / 2 - 15 + $s * 11, $y + $h - 14);
        }
    }
}
