<?php

namespace App\Scene;

use App\Ui\FontProvider;
use App\Ui\Paint;
use App\Ui\Text;
use JumPHP\Kernel\Platform;
use JumPHP\Renderer\Renderer;
use JumPHP\Scene\AbstractScene;
use JumPHP\Util\Lang;

/**
 * The settings, saved in var/options.json (IndexedDB in the browser) by the engine's Options service: a painted panel
 * over the screen below (the side screen or the pause menu), like the rest of the interface. The sound is a bar with
 * a minus and a plus, the language turns with two arrows (each language named in itself), the full screen is a switch
 * (desktop), then wooden buttons: back, and quit on the desktop over the side screen (the game's first screen).
 * Mouse / touch: every control is pressed where it is drawn. Keys: up / down pick a row, left / right change it,
 * confirm presses it, back closes the panel.
 */
final class OptionsScene extends AbstractScene
{
    private const PANEL_W = 320.0;
    private const ROW_H = 30.0;
    private const BUTTON_W = 96.0;
    private const BUTTON_H = 24.0;
    private const LABELS = ['sound' => 'SOUND', 'language' => 'LANGUAGE', 'fullscreen' => 'FULLSCREEN'];

    /** pushed over the side screen (not over the pause menu): it has QUIT on the desktop */
    private bool $overSides = false;
    /** the row the keys are on (rows, then the buttons) */
    private int $selected = 0;

    public function __construct(private FontProvider $fonts)
    {
    }

    public function enter(array $params = []): void
    {
        // the scene under this one (already on the stack when enter() runs)
        $stack = $this->scenes()->stack();
        $this->overSides = ($stack[count($stack) - 2] ?? null) instanceof FactionSelectScene;
        $this->selected = 0;
    }

    public function isOverlay(): bool
    {
        return true;
    }

    /** @return list<string> the rows: settings, then buttons */
    private function rows(): array
    {
        $rows = ['sound', 'language'];
        if (Platform::isDesktop()) {
            $rows[] = 'fullscreen';
        }
        $rows[] = 'back';
        if (Platform::isDesktop() && $this->overSides) {
            $rows[] = 'quit';
        }
        return $rows;
    }

    /** @return list<string> the settings (the rows that are not buttons) */
    private function settings(): array
    {
        return array_values(array_filter($this->rows(), static fn (string $r) => !in_array($r, ['back', 'quit'], true)));
    }

    /** @return list<string> */
    private function buttons(): array
    {
        return array_values(array_filter($this->rows(), static fn (string $r) => in_array($r, ['back', 'quit'], true)));
    }

    // ---- geometry: the same for drawing and for the pointer -------------------------------------------------------

    /** @return array{0: float, 1: float, 2: float, 3: float} the panel */
    private function panel(): array
    {
        $h = 40.0 + count($this->settings()) * self::ROW_H + 14.0 + self::BUTTON_H + 14.0;
        return [($this->width() - self::PANEL_W) / 2, ($this->height() - $h) / 2, self::PANEL_W, $h];
    }

    /** @return array{0: float, 1: float, 2: float, 3: float} the band of setting $i */
    private function row(int $i): array
    {
        [$x, $y, $w] = $this->panel();
        return [$x + 8, $y + 40 + $i * self::ROW_H, $w - 16, self::ROW_H];
    }

    /** @return array{0: float, 1: float} the left and right ends of a row's control */
    private function control(int $i): array
    {
        [$x, , $w] = $this->row($i);
        return [$x + $w - 150, $x + $w - 8];
    }

    /** @return array{0: float, 1: float, 2: float, 3: float} the wooden button $i */
    private function button(int $i): array
    {
        [$x, $y, $w, $h] = $this->panel();
        $n = count($this->buttons());
        $gap = 12.0;
        $left = $x + ($w - $n * self::BUTTON_W - ($n - 1) * $gap) / 2;
        return [$left + $i * (self::BUTTON_W + $gap), $y + $h - 14 - self::BUTTON_H, self::BUTTON_W, self::BUTTON_H];
    }

    /** The round little buttons of a row ([cx, cy, what pressing it does: -1 / +1]). @return list<array{0: float, 1: float, 2: int}> */
    private function steppers(int $i): array
    {
        if ($this->settings()[$i] === 'fullscreen') {
            return [];
        }
        [$from, $to] = $this->control($i);
        $cy = $this->row($i)[1] + self::ROW_H / 2;
        return [[$from + 10, $cy, -1], [$to - 10, $cy, 1]];
    }

    /** @return array{0: float, 1: float, 2: float, 3: float} the switch of the full screen */
    private function toggle(int $i): array
    {
        [, $to] = $this->control($i);
        $cy = $this->row($i)[1] + self::ROW_H / 2;
        return [$to - 34, $cy - 8, 34, 16];
    }

    // ---- what the settings do -------------------------------------------------------------------------------------

    private function change(string $setting, int $dir): void
    {
        $sound = $this->sound();
        $options = $this->engine->options;
        if ($setting === 'sound') {
            $sound->setSfxVolume(max(0.0, min(1.0, round($sound->getSfxVolume() * 10 + $dir) / 10)));
            $options->set('sfx_volume', $sound->getSfxVolume());
        } elseif ($setting === 'language') {
            $langs = array_keys(FontProvider::LANGUAGES);
            $i = array_search($this->engine->lang->getLang(), $langs, true);
            $lang = $langs[((int)$i + $dir + count($langs)) % count($langs)];
            $this->engine->lang->setLang($lang);
            $options->set('lang', $lang);
            $this->fonts->apply($this->engine);
        } elseif ($setting === 'fullscreen') {
            $this->engine->toggleFullscreen();
        }
        $this->sound()->playSfx('menu_move');
    }

    private function press(string $row): void
    {
        if ($row === 'back') {
            $this->sound()->playSfx('menu_confirm');
            $this->scenes()->pop();
        } elseif ($row === 'quit') {
            $this->engine->quit();
        } elseif ($row === 'fullscreen') {
            $this->change($row, 1);
        }
    }

    public function update(float $dt): void
    {
        $actions = $this->actions();
        $rows = $this->rows();
        if ($actions->isPressed('back')) {
            $this->press('back');
            return;
        }
        $mouse = $this->engine->input->getMouse();
        if ($mouse->isPressed()) {
            $this->click($mouse->x, $mouse->y);
            return;
        }
        if ($actions->isPressed('up') || $actions->isPressed('down')) {
            $this->selected = ($this->selected + ($actions->isPressed('up') ? -1 : 1) + count($rows)) % count($rows);
            $this->sound()->playSfx('menu_move');
        }
        $row = $rows[$this->selected] ?? 'back';
        $isSetting = in_array($row, $this->settings(), true);
        if ($isSetting && ($actions->isPressed('left') || $actions->isPressed('right'))) {
            $this->change($row, $actions->isPressed('left') ? -1 : 1);
        } elseif ($actions->isPressed('confirm')) {
            $this->press($row);
        }
    }

    private function click(float $x, float $y): void
    {
        $inside = static fn (array $r): bool => $x >= $r[0] && $x < $r[0] + $r[2] && $y >= $r[1] && $y < $r[1] + $r[3];
        foreach ($this->settings() as $i => $setting) {
            foreach ($this->steppers($i) as [$cx, $cy, $dir]) {
                if (($x - $cx) ** 2 + ($y - $cy) ** 2 <= 13 ** 2) {
                    $this->selected = $i;
                    $this->change($setting, $dir);
                    return;
                }
            }
            if ($setting === 'fullscreen' && $inside($this->row($i))) {
                $this->selected = $i;
                $this->change($setting, 1);
                return;
            }
        }
        foreach ($this->buttons() as $i => $button) {
            if ($inside($this->button($i))) {
                $this->selected = count($this->settings()) + $i;
                $this->press($button);
                return;
            }
        }
    }

    // ---- drawing ---------------------------------------------------------------------------------------------------

    public function render(Renderer $r): void
    {
        $font = $this->font();
        $r->fillRect(0, 0, $this->width(), $this->height(), [0, 0, 0, 150]);
        [$px, $py, $pw, $ph] = $this->panel();
        Paint::panel($r, $px, $py, $pw, $ph, null, null, 8);

        // the title, between two gold threads
        $title = Lang::t('OPTIONS');
        $scale = Text::fitScale($font, $title, $pw - 60, 1.3);
        $tw = $font->width($title, $scale);
        $font->draw($r, $title, $px + $pw / 2, $py + 18 - $font->height($scale) / 2, $scale, Paint::GOLD, 'center', [40, 22, 10]);
        foreach ([-1, 1] as $side) {
            $from = $px + $pw / 2 + $side * ($tw / 2 + 8);
            $r->fillRect($side < 0 ? $from - 40 : $from, $py + 18, 40, 1, [214, 176, 92, 160]);
        }

        $mouse = $this->engine->input->getMouse();
        foreach ($this->settings() as $i => $setting) {
            [$x, $y, $w, $h] = $this->row($i);
            if ($i === $this->selected) {
                Paint::round($r, $x, $y + 2, $w, $h - 4, 6, [255, 236, 190, 26]);
            }
            $label = Lang::t(self::LABELS[$setting]);
            [$from] = $this->control($i);
            $ls = Text::fitScale($font, $label, $from - $x - 18, 1);
            $font->draw($r, $label, $x + 8, $y + $h / 2 - $font->height($ls) / 2, $ls, $i === $this->selected ? [255, 228, 150] : Paint::CREAM, 'left', [16, 20, 36]);
            $this->drawControl($r, $i, $setting, $mouse->x, $mouse->y);
        }

        foreach ($this->buttons() as $i => $button) {
            [$bx, $by, $bw, $bh] = $this->button($i);
            $hover = $mouse->x >= $bx && $mouse->x < $bx + $bw && $mouse->y >= $by && $mouse->y < $by + $bh;
            $chosen = $this->selected === count($this->settings()) + $i;
            Paint::button($r, $bx, $by, $bw, $bh, true, $hover, $chosen);
            $text = Lang::t($button === 'back' ? 'BACK' : 'QUIT');
            $bs = Text::fitScale($font, $text, $bw - 12, 1);
            $font->draw($r, $text, $bx + $bw / 2, $by + $bh / 2 - $font->height($bs) / 2, $bs, [255, 242, 214], 'center', [40, 22, 12]);
        }
    }

    private function drawControl(Renderer $r, int $i, string $setting, float $mx, float $my): void
    {
        $font = $this->font();
        [$from, $to] = $this->control($i);
        $cy = $this->row($i)[1] + self::ROW_H / 2;
        if ($setting === 'fullscreen') {
            [$sx, $sy, $sw, $sh] = $this->toggle($i);
            $on = (bool)$this->engine->options->get('fullscreen');
            Paint::round($r, $sx, $sy, $sw, $sh, $sh / 2, Paint::WOOD_DARK);
            Paint::round($r, $sx + 1, $sy + 1, $sw - 2, $sh - 2, ($sh - 2) / 2, $on ? [92, 168, 96] : [62, 66, 88]);
            $knob = $on ? $sx + $sw - $sh / 2 : $sx + $sh / 2;
            $r->fillCircle($knob, $sy + $sh / 2, $sh / 2 - 1.5, Paint::CREAM);
            $text = Lang::t($on ? 'ON' : 'OFF');
            $font->draw($r, $text, $sx - 8, $cy - $font->height() / 2, 1, [196, 200, 216], 'right', [16, 20, 36]);
            return;
        }
        foreach ($this->steppers($i) as [$bx, $by, $dir]) {
            $hover = ($mx - $bx) ** 2 + ($my - $by) ** 2 <= 13 ** 2;
            Paint::medallion($r, $bx, $by, 9, $hover);
            if ($setting === 'sound') {
                $r->fillRect($bx - 3.5, $by - 0.75, 7, 1.5, Paint::CREAM);
                if ($dir > 0) {
                    $r->fillRect($bx - 0.75, $by - 3.5, 1.5, 7, Paint::CREAM);
                }
            } else {
                $d = (float)$dir;
                Paint::triangle($r, [$bx + 3.2 * $d, $by], [$bx - 2.2 * $d, $by - 3.6], [$bx - 2.2 * $d, $by + 3.6], Paint::CREAM);
            }
        }
        $left = $from + 24;
        $right = $to - 24;
        if ($setting === 'sound') {
            $volume = $this->sound()->getSfxVolume();
            Paint::bar($r, $left, $cy + 3, $right - $left, 4, $volume, Paint::GOLD);
            $font->draw($r, round($volume * 100) . '%', ($left + $right) / 2, $cy - 3 - $font->height(), 1, [196, 200, 216], 'center', [16, 20, 36]);
        } else {
            $code = $this->engine->lang->getLang();
            $name = FontProvider::LANGUAGES[$code] ?? $code;
            $ns = Text::fitScale($font, $name, $right - $left, 1);
            $font->draw($r, $name, ($left + $right) / 2, $cy - $font->height($ns) / 2, $ns, Paint::CREAM, 'center', [16, 20, 36]);
        }
    }
}
