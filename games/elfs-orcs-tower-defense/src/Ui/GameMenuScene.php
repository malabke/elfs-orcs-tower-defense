<?php

namespace App\Ui;

use JumPHP\Renderer\Renderer;
use JumPHP\Ui\MenuScene;
use JumPHP\Util\Lang;

/**
 * The game's menus: the engine's MenuScene, with the font of the language, a title that fits the screen, rows
 * tall enough to be touched (24 px), and the mouse / touch: pointing at a row selects it, a click presses it
 * (on a value row: the left half lowers it, the right half raises it).
 */
abstract class GameMenuScene extends MenuScene
{
    /** height of the title in pixels (the scale follows the font) */
    protected int $titlePixels = 24;
    protected int $rowHeight = 22;

    public function __construct(protected FontProvider $fonts, protected Sprites $sprites)
    {
    }

    public function enter(array $params = []): void
    {
        $this->fonts->apply($this->engine);
        $this->sprites->attach($this->sdl(), $this->engine->config->path());
        Sfx::load($this->sound(), $this->engine->config->path());
        parent::enter($params);
        $this->layoutMenu();
    }

    /** The rows' spacing for the current font. */
    protected function layoutMenu(): void
    {
        $this->menu->spacing = max(4, $this->rowHeight - $this->font()->lineHeight());
        $this->menu->selectedColor = [255, 220, 90];
    }

    public function update(float $dt): void
    {
        $mouse = $this->engine->input->getMouse();
        foreach ($this->menu->getItems() as $i => $item) {
            [$x, $y, $w, $h] = $this->rowRect($i);
            if ($mouse->x < $x || $mouse->x > $x + $w || $mouse->y < $y || $mouse->y > $y + $h || (!isset($item[2]) && !isset($item[3]))) {
                continue;
            }
            if ($mouse->isPressed()) {
                $this->menu->selected = $i;
                if (isset($item[2])) {
                    $this->sound()->playSfx('menu_confirm');
                    ($item[2])();
                } else {
                    ($item[3])($mouse->x < $x + $w / 2 ? -1 : 1);
                    $this->sound()->playSfx('menu_move');
                }
                return;
            }
        }
        if ($mouse->isPressed()) {
            // a tap outside the rows is not a "confirm" (touch:tap is bound to it)
            return;
        }
        parent::update($dt);
    }

    /** @return list<array{0: string, 1: array}> lines under the title: [translated text, colour] */
    protected function subtitles(): array
    {
        return [];
    }

    /** @return array{0: float, 1: float, 2: float, 3: float} the area of row $i (x, y, w, h) */
    protected function rowRect(int $i): array
    {
        $line = $this->font()->lineHeight($this->menu->scale) + $this->menu->spacing;
        $item = $this->menu->getItems()[$i] ?? [''];
        $w = max(120, $this->font()->width('< ' . $this->menu->text($item) . ' >', $this->menu->scale) + 16);
        $y = $this->height() * $this->menuTop + $i * $line - ($line - $this->font()->lineHeight($this->menu->scale)) / 2;
        return [$this->width() / 2 - $w / 2, $y, $w, $line];
    }

    protected function renderBackground(Renderer $r): void
    {
        if ($this->isOverlay()) {
            // pure black: over a 3D scene (keyed by magenta) only black darkens it, any other colour tints it
            $r->fillRect(0, 0, $this->width(), $this->height(), [0, 0, 0, 215]);
        } else {
            $r->clear($this->background);
        }
    }

    public function render(Renderer $r): void
    {
        $this->renderBackground($r);
        $font = $this->font();
        $w = $this->width();
        $y = $this->height() * 0.12;
        if ($this->title !== '') {
            $title = Lang::t($this->title);
            $scale = Text::fitScale($font, $title, $w - 16, max(1, round($this->titlePixels / max(1, $font->height()))));
            $font->draw($r, $title, $w / 2, $y, $scale, $this->titleColor, 'center', [20, 10, 20]);
            $y += $font->lineHeight($scale) + 4;
        }
        foreach ($this->subtitles() as [$text, $color]) {
            $y += Text::draw($r, $font, $text, $w / 2, $y, $w - 32, $color, 'center') + 2;
        }
        // the selected row gets a soft pill behind it
        [$rx, $ry, $rw, $rh] = $this->rowRect($this->menu->selected);
        Paint::round($r, $rx, $ry + 1, $rw, $rh - 2, ($rh - 2) / 2, $this->rowBand());
        $this->menu->render($r, $font, $w / 2, $this->height() * $this->menuTop);
    }

    /** The colour of the pill behind the selected row. */
    protected function rowBand(): array
    {
        return [255, 236, 190, 34];
    }
}
