<?php

namespace App\Scene;

use App\Save\Progress;
use App\Td\GameData;
use App\Ui\FontProvider;
use App\Ui\Paint;
use App\Ui\Sfx;
use App\Ui\Sprites;
use App\Ui\Text;
use JumPHP\Renderer\Renderer;
use JumPHP\Scene\AbstractScene;
use JumPHP\Util\Lang;

/**
 * The campaign of a side: six maps (a picture, the name, the stars won in normal and hard mode). A map opens when
 * the one before it is won; hard mode opens on a map once it is won. Arrows / a tap pick a map, confirm / a second
 * tap plays it; below the maps, the hard mode switch.
 */
final class MapSelectScene extends AbstractScene
{
    private const COLS = 3;
    private const CARD_W = 148;
    private const CARD_H = 96;

    private GameData $data;
    private string $faction = 'elves';
    /** @var list<string> */
    private array $maps = [];
    /** a map (0..5), or the hard mode switch (6) */
    private int $selected = 0;
    private bool $hard = false;
    /** the map picked before the hard mode switch got the focus */
    private ?int $lastMap = null;
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
        $this->faction = (string)($params['faction'] ?? 'elves');
        $this->maps = $this->data->mapIds();
        // the last map open, or the one asked for
        $this->selected = 0;
        foreach ($this->maps as $i => $map) {
            if ($this->progress->isOpen($this->faction, $this->maps, $map)) {
                $this->selected = $i;
            }
        }
        if (isset($params['map']) && in_array($params['map'], $this->maps, true)) {
            $this->selected = (int)array_search($params['map'], $this->maps, true);
        }
        $this->lastMap = null;
        $this->hard = !empty($params['hard']) && $this->wonNormal($this->selected);
        $this->time = 0.0;
    }

    private function isOpen(int $i): bool
    {
        return isset($this->maps[$i]) && $this->progress->isOpen($this->faction, $this->maps, $this->maps[$i]);
    }

    /** Map $i was won in normal mode: it can be played in hard mode. */
    private function wonNormal(int $i): bool
    {
        return isset($this->maps[$i]) && $this->progress->stars($this->faction, $this->maps[$i]) > 0;
    }

    /** The hard mode switch works once a map is won. */
    private function hardOpen(): bool
    {
        foreach (array_keys($this->maps) as $i) {
            if ($this->wonNormal($i)) {
                return true;
            }
        }
        return false;
    }

    /** Map $i can be played now (in hard mode: only the maps won in normal mode). */
    private function playable(int $i): bool
    {
        return $this->hard ? $this->wonNormal($i) : $this->isOpen($i);
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
            $this->scenes()->switchTo(FactionSelectScene::class, ['faction' => $this->faction]);
            return;
        }
        $n = count($this->maps);
        if ($this->selected < $n) {
            if ($actions->isPressed('left')) {
                $this->move(-1);
            } elseif ($actions->isPressed('right')) {
                $this->move(1);
            } elseif ($actions->isPressed('up')) {
                $this->move(-self::COLS);
            } elseif ($actions->isPressed('down') && $this->selected + self::COLS < $n) {
                $this->move(self::COLS);
            } elseif ($actions->isPressed('down')) {
                $this->focusHard();
            }
        } elseif ($actions->isPressed('up')) {
            $this->selected = $this->lastMap ?? 0;
            $this->sound()->playSfx('menu_move');
        }
        if ($actions->isPressed('confirm') && $this->selected >= $n) {
            $this->toggleHard();
        } elseif ($actions->isPressed('confirm')) {
            $this->play();
        }
    }

    private function move(int $by): void
    {
        $next = $this->selected + $by;
        if ($next >= 0 && $next < count($this->maps)) {
            $this->selected = $next;
            $this->sound()->playSfx('menu_move');
        }
    }

    private function focusHard(): void
    {
        $this->lastMap = $this->selected;
        $this->selected = count($this->maps);
        $this->sound()->playSfx('menu_move');
    }

    private function toggleHard(): void
    {
        if (!$this->hardOpen()) {
            $this->sound()->playSfx('deny');
            return;
        }
        $this->hard = !$this->hard;
        $this->sound()->playSfx('menu_confirm');
    }

    private function play(): void
    {
        if (!$this->playable($this->selected)) {
            $this->sound()->playSfx('deny');
            return;
        }
        $this->sound()->playSfx('menu_confirm');
        $this->scenes()->switchTo(PlayScene::class, ['map' => $this->maps[$this->selected], 'faction' => $this->faction,
            'hard' => $this->hard]);
    }

    private function click(float $x, float $y): void
    {
        [$bx, $by, $bw, $bh] = $this->backButton();
        if ($x >= $bx && $x < $bx + $bw && $y >= $by && $y < $by + $bh) {
            $this->scenes()->switchTo(FactionSelectScene::class, ['faction' => $this->faction]);
            return;
        }
        [$hx, $hy, $hw, $hh] = $this->hardButton();
        if ($x >= $hx && $x < $hx + $hw && $y >= $hy && $y < $hy + $hh) {
            $this->lastMap = $this->selected < count($this->maps) ? $this->selected : $this->lastMap;
            $this->toggleHard();
            return;
        }
        foreach ($this->maps as $i => $map) {
            [$cx, $cy] = $this->card($i);
            if ($x >= $cx && $x < $cx + self::CARD_W && $y >= $cy && $y < $cy + self::CARD_H) {
                if ($i === $this->selected) {
                    $this->play();
                } else {
                    $this->selected = $i;
                    $this->lastMap = $i;
                    $this->sound()->playSfx('menu_move');
                }
                return;
            }
        }
    }

    /** @return array{0: float, 1: float} */
    private function card(int $i): array
    {
        $gap = (int)(($this->width() - self::COLS * self::CARD_W) / (self::COLS + 1));
        return [$gap + ($i % self::COLS) * (self::CARD_W + $gap), 32 + intdiv($i, self::COLS) * (self::CARD_H + 6)];
    }

    /** @return array{0: float, 1: float, 2: float, 3: float} */
    private function backButton(): array
    {
        return [8, $this->height() - 30, max(48, $this->font()->width(Lang::t('BACK')) + 20), 26];
    }

    /** @return array{0: float, 1: float, 2: float, 3: float} */
    private function hardButton(): array
    {
        $w = $this->font()->width($this->hardLabel()) + 30;
        return [$this->width() / 2 - $w / 2, $this->height() - 30, $w, 26];
    }

    private function hardLabel(): string
    {
        return Lang::t('HARD MODE') . ': ' . ($this->hard ? Lang::t('ON') : Lang::t('OFF'));
    }

    public function render(Renderer $r): void
    {
        $font = $this->font();
        $w = $this->width();
        $colors = $this->data->faction($this->faction)['colors'];
        Paint::backdrop($r, $w, $this->height());
        Paint::header($r, $w, 26, $colors['main']);
        $title = Lang::t($this->data->faction($this->faction)['name']) . ' - ' . Lang::t('CHOOSE A MAP');
        $scale = Text::fitScale($font, $title, $w - 16, 1.2);
        $font->draw($r, $title, $w / 2, 13 - $font->height($scale) / 2, $scale, [255, 228, 150], 'center', [40, 22, 10]);

        foreach ($this->maps as $i => $id) {
            $this->drawCard($r, $i, $id, $colors);
        }

        $mouse = $this->engine->input->getMouse();
        $buttons = [[$this->backButton(), Lang::t('BACK'), true, false], [$this->hardButton(), $this->hardLabel(), $this->hardOpen(), $this->selected >= count($this->maps)]];
        foreach ($buttons as [[$bx, $by, $bw, $bh], $label, $enabled, $focus]) {
            $hover = $mouse->x >= $bx && $mouse->x < $bx + $bw && $mouse->y >= $by && $mouse->y < $by + $bh;
            Paint::button($r, $bx, $by, $bw, $bh, $enabled, $enabled && $hover, $focus);
            $font->draw($r, $label, $bx + $bw / 2, $by + $bh / 2 - $font->height() / 2, 1, $enabled ? [255, 242, 214] : [200, 190, 182], 'center', [40, 22, 12]);
        }
        if ($this->hard) {
            Paint::icon($r, 'skull', $this->hardButton()[0] - 10, $this->height() - 17, 12);
        }
    }

    private function drawCard(Renderer $r, int $i, string $id, array $colors): void
    {
        $font = $this->font();
        $map = $this->data->map($id);
        [$x, $y] = $this->card($i);
        $open = $this->playable($i);
        $chosen = $i === $this->selected || ($this->selected >= count($this->maps) && $i === $this->lastMap);
        Paint::panel($r, $x, $y, self::CARD_W, self::CARD_H, $chosen ? Paint::GOLD : [70, 66, 90], null, 5);
        // the map, small: a picture of it in 3D (or its drawing)
        Paint::round($r, $x + 13, $y + 3, 122, 62, 2, [10, 12, 20]);
        if (!$this->sprites->drawPicture($r, "map-$id", $x + 14, $y + 4, 120, 60)) {
            $this->sprites->drawMap($r, $id, $map, $colors, $x + 14, $y + 4, 0.25);
        }
        Paint::round($r, $x + 4, $y + 4, 13, 13, 6.5, [20, 26, 46, 220]);
        $font->draw($r, ($i + 1) . '', $x + 10.5, $y + 10.5 - $font->height() / 2, 1, [255, 255, 255], 'center', [16, 20, 36]);
        Text::draw($r, $font, Lang::t($map['name']), $x + self::CARD_W / 2, $y + 68, self::CARD_W - 8, $open ? [255, 226, 130] : [160, 150, 150],
            'center', [40, 22, 10]);
        if (!$open) {
            $r->fillRect($x + 14, $y + 4, 120, 60, [16, 18, 32, 190]);
            Paint::icon($r, 'lock', $x + self::CARD_W / 2, $y + 34, 22);
            return;
        }
        $stars = $this->progress->stars($this->faction, $id);
        $hard = $this->progress->stars($this->faction, $id, true);
        Paint::round($r, $x + self::CARD_W - 47, $y + 51, 36, 12, 6, [20, 26, 46, 200]);
        for ($s = 0; $s < 3; $s++) {
            Paint::icon($r, $s < $stars ? 'star' : 'star_off', $x + self::CARD_W - 40 + $s * 11, $y + 57, 10);
        }
        if ($hard > 0) {
            Paint::icon($r, 'skull', $x + 24, $y + 57, 11);
        }
    }
}
