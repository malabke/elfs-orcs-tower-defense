<?php

namespace App\Ui;

use JumPHP\Renderer\Renderer;
use JumPHP\Ui\MenuScene;
use JumPHP\Util\Lang;

/**
 * The game's menus (pause, victory, defeat): a painted panel like the rest of the interface, its title in gold, its
 * lines of text, room for a picture (extraHeight() / renderExtra(): the stars of a victory), then one wooden button per
 * item. The engine's MenuScene keeps the keys (up / down, confirm, back); the mouse / touch: pointing at a button
 * picks it, a click presses it. Items are [label, null, onPress].
 */
abstract class GameMenuScene extends MenuScene
{
    /** height of the title in pixels (the scale follows the font) */
    protected int $titlePixels = 20;
    protected float $panelWidth = 220.0;
    private const BUTTON_H = 24.0;
    private const BUTTON_GAP = 6.0;

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

    /** Kept for the scenes that call it after a change of language (the layout is computed when drawn). */
    protected function layoutMenu(): void
    {
    }

    public function update(float $dt): void
    {
        $mouse = $this->engine->input->getMouse();
        foreach ($this->menu->getItems() as $i => $item) {
            [$x, $y, $w, $h] = $this->rowRect($i);
            if ($mouse->x < $x || $mouse->x > $x + $w || $mouse->y < $y || $mouse->y > $y + $h || !isset($item[2])) {
                continue;
            }
            if ($mouse->isPressed()) {
                $this->menu->selected = $i;
                $this->sound()->playSfx('menu_confirm');
                ($item[2])();
                return;
            }
        }
        if ($mouse->isPressed()) {
            // a tap outside the buttons is not a "confirm" (touch:tap is bound to it)
            return;
        }
        parent::update($dt);
    }

    /** @return list<array{0: string, 1: array}> lines under the title: [translated text, colour] */
    protected function subtitles(): array
    {
        return [];
    }

    /** The height of what the scene draws between the texts and the buttons (renderExtra()). */
    protected function extraHeight(): float
    {
        return 0.0;
    }

    /** Draws the scene's own part of the panel, $y its top, centred on $cx. */
    protected function renderExtra(Renderer $r, float $cx, float $y): void
    {
    }

    private function titleScale(): float
    {
        $font = $this->font();
        return Text::fitScale($font, Lang::t($this->title), $this->panelWidth - 24, max(1, round($this->titlePixels / max(1, $font->height()))));
    }

    /**
     * The panel's parts, from the top: [x, y, width, height of the panel, y of the subtitles, y of the extra, y of the
     * first button].
     * @return array{0: float, 1: float, 2: float, 3: float, 4: float, 5: float, 6: float}
     */
    private function layout(): array
    {
        $font = $this->font();
        $w = $this->panelWidth;
        $h = 14.0;
        if ($this->title !== '') {
            $h += $font->lineHeight($this->titleScale()) + 8;
        }
        $subs = $h;
        foreach ($this->subtitles() as [$text]) {
            $h += Text::height($font, $text, $w - 24) + 2;
        }
        $extra = $h + 4;
        $h = $extra + $this->extraHeight() + 6;
        $buttons = $h;
        $n = count($this->menu->getItems());
        $h += $n * self::BUTTON_H + max(0, $n - 1) * self::BUTTON_GAP + 14;
        $x = ($this->width() - $w) / 2;
        $y = max(4.0, ($this->height() - $h) / 2);
        return [$x, $y, $w, $h, $y + $subs, $y + $extra, $y + $buttons];
    }

    /** @return array{0: float, 1: float, 2: float, 3: float} the wooden button of item $i (x, y, w, h) */
    protected function rowRect(int $i): array
    {
        [$x, , $w, , , , $top] = $this->layout();
        return [$x + 24, $top + $i * (self::BUTTON_H + self::BUTTON_GAP), $w - 48, self::BUTTON_H];
    }

    protected function renderBackground(Renderer $r): void
    {
        if ($this->isOverlay()) {
            // black: over a 3D scene shown through a key colour, only black darkens it
            $r->fillRect(0, 0, $this->width(), $this->height(), [0, 0, 0, 150]);
        } else {
            Paint::backdrop($r, $this->width(), $this->height());
        }
    }

    public function render(Renderer $r): void
    {
        $this->renderBackground($r);
        $font = $this->font();
        [$px, $py, $pw, $ph, $subsY, $extraY] = $this->layout();
        Paint::panel($r, $px, $py, $pw, $ph, null, null, 8);
        $cx = $px + $pw / 2;
        if ($this->title !== '') {
            $title = Lang::t($this->title);
            $scale = $this->titleScale();
            $font->draw($r, $title, $cx, $py + 14, $scale, $this->titleColor, 'center', [40, 22, 10]);
            // two gold threads beside it
            $tw = $font->width($title, $scale);
            $ty = $py + 14 + $font->height($scale) / 2;
            foreach ([-1, 1] as $side) {
                $from = $cx + $side * ($tw / 2 + 8);
                $len = min(36.0, $pw / 2 - $tw / 2 - 18);
                if ($len > 4) {
                    $r->fillRect($side < 0 ? $from - $len : $from, $ty, $len, 1, [214, 176, 92, 160]);
                }
            }
        }
        $y = $subsY;
        foreach ($this->subtitles() as [$text, $color]) {
            $y += Text::draw($r, $font, $text, $cx, $y, $pw - 24, $color, 'center', [16, 20, 36]) + 2;
        }
        $this->renderExtra($r, $cx, $extraY);

        $mouse = $this->engine->input->getMouse();
        foreach ($this->menu->getItems() as $i => $item) {
            [$bx, $by, $bw, $bh] = $this->rowRect($i);
            $hover = $mouse->x >= $bx && $mouse->x < $bx + $bw && $mouse->y >= $by && $mouse->y < $by + $bh;
            Paint::button($r, $bx, $by, $bw, $bh, true, $hover, $i === $this->menu->selected);
            $label = $this->menu->text($item);
            $scale = Text::fitScale($font, $label, $bw - 12, 1);
            $font->draw($r, $label, $bx + $bw / 2, $by + $bh / 2 - $font->height($scale) / 2, $scale, [255, 242, 214], 'center', [40, 22, 12]);
        }
    }
}
