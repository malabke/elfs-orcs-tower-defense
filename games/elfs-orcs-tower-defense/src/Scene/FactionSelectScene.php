<?php

namespace App\Scene;

use App\Save\Progress;
use App\Td\GameData;
use App\Ui\FontProvider;
use App\Ui\Paint;
use App\Ui\Sfx;
use App\Ui\Sprites;
use App\Ui\Text;
use App\Ui\World3d;
use App\View3d\Diorama;
use App\View3d\Mesh;
use App\View3d\Rig;
use App\View3d\Towers;
use App\View3d\Units;
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

    /** the cards show living 3D vignettes (else the pictures of assets/sprites/cards/) */
    private bool $use3d = false;
    /** how much of the vignettes' colours the scene fade leaves (1: all), this frame */
    private float $shade = 1.0;
    /** the height of the cards' pictures (pictureHeight()), and of the longest name (in lines of text) */
    private float $pictureH = 28.0;
    private int $namesH = 0;

    public function __construct(private FontProvider $fonts, private Sprites $sprites, private Progress $progress, private World3d $world)
    {
    }

    public function exit(): void
    {
        if ($this->use3d) {
            $this->world->shutdown($this->engine->gl());
            $this->use3d = false;
        }
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
        $this->use3d = ($params['view'] ?? '3d') !== 'flat' && $this->world->bootOver($this->engine->gl());
        if (!$this->use3d) {
            $this->world->shutdown($this->engine->gl());
        }
        // the pictures start at the same height on every card: under the room of the longest name
        $inner = $this->card(0)[2] - 8;
        $this->namesH = 0;
        foreach ($this->factions as $id) {
            $this->namesH = max($this->namesH, Text::height($this->font(), Lang::t($this->data->faction($id)['name']), $inner));
        }
        $this->pictureH = $this->pictureHeight();
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
        Paint::backdrop($r, $w, $this->height());
        Paint::header($r, $w, 26, $this->data->faction($this->factions[$this->selected])['colors']['main']);
        $title = Lang::t('CHOOSE YOUR SIDE');
        $scale = Text::fitScale($font, $title, $w - 16, 1.3);
        $font->draw($r, $title, $w / 2, 13 - $font->height($scale) / 2, $scale, [255, 228, 150], 'center', [40, 22, 10]);

        // under another scene (the pause menu pushed when the window loses the focus) the 3D layer, over all
        // the 2D, would cover it: no vignettes then
        $this->shade = $this->scenes()->current() === $this ? 1.0 - $this->fadeLevel() : 0.0;
        foreach ($this->factions as $i => $id) {
            $this->drawCard($r, $i, $id);
        }

        [$bx, $by, $bw, $bh] = $this->backButton();
        $mouse = $this->engine->input->getMouse();
        Paint::button($r, $bx, $by, $bw, $bh, true, $mouse->x >= $bx && $mouse->x < $bx + $bw && $mouse->y >= $by && $mouse->y < $by + $bh);
        $font->draw($r, Lang::t('BACK'), $bx + $bw / 2, $by + $bh / 2 - $font->height() / 2, 1, [255, 242, 214], 'center', [40, 22, 12]);
        $hint = Lang::t('PRESS AGAIN TO CONFIRM');
        Text::draw($r, $font, $hint, $bx + $bw + 12, $by + $bh / 2 - $font->height() / 2, $w - $bw - 28, [196, 200, 216], 'left', [16, 20, 36]);
    }

    /**
     * How far the screen has faded to black (0..1). The vignettes are in a 3D layer over the 2D, which the fade
     * (drawn in 2D by the SceneManager) does not darken: they darken by it themselves. The SceneManager keeps it
     * private (no getter, in the released engine either): read it, 0 when it cannot be.
     */
    private function fadeLevel(): float
    {
        $scenes = $this->scenes();
        try {
            return (float)(new \ReflectionProperty($scenes, 'fade'))->getValue($scenes);
        } catch (\ReflectionException) {
            return 0.0;
        }
    }

    /** The foes of a side (the battle's models, the portraits' names). @return list<string> */
    private function foes(array $faction): array
    {
        return $faction['enemy'] === 'alliance' ? ['elf', 'dwarf', 'rider'] : ['grunt', 'goblin', 'warg'];
    }

    /**
     * The height of the cards' pictures. In 3D the vignette takes the room the texts leave on the most crowded card
     * (the same on every card; the foes walk in it, no row of portraits under the texts), within limits.
     */
    private function pictureHeight(): float
    {
        $inner = $this->card(0)[2] - 8;
        if (!$this->use3d) {
            return $inner * 140 / 400;
        }
        $font = $this->font();
        $texts = 0;
        foreach ($this->factions as $id) {
            $faction = $this->data->faction($id);
            $enemy = $faction['enemy'] === 'alliance' ? Lang::t('AGAINST THE ALLIANCE') : Lang::t('AGAINST THE HORDE');
            $texts = max($texts, Text::height($font, Lang::t($faction['motto']), $inner) + Text::height($font, $enemy, $inner));
        }
        // 11 above the name, 4 under it, 6 under the picture and under the motto, 17 at the bottom for the stars
        return max($inner * 0.35, min($inner * 0.8, (float)(self::CARD_H - 44 - $this->namesH - $texts)));
    }

    /**
     * The living vignette of a card, in 3D: a sky, the side's island floating in it, its four towers at their best
     * level and its foes walking round them, the camera swaying slowly (each card its own way, the picked one more).
     * @param array{0: float, 1: float, 2: float, 3: float} $frame
     */
    private function vignette(Renderer $r, int $i, string $id, array $frame): void
    {
        [$fx, $fy, $fw, $fh] = $frame;
        if ($this->shade <= 0.0) {
            // no 3D now (under the pause menu): the sky alone, in 2D
            $bands = 24;
            for ($b = 0; $b < $bands; $b++) {
                $t = $b / ($bands - 1);
                $c = $t < 0.6 ? Mesh::mix([0.47, 0.64, 0.86], [0.96, 0.88, 0.74], $t / 0.6) : Mesh::mix([0.96, 0.88, 0.74], [0.70, 0.76, 0.89], ($t - 0.6) / 0.4);
                $r->fillRect($fx, $fy + $fh * $b / $bands, $fw, $fh / $bands + 0.5, [(int)($c[0] * 255), (int)($c[1] * 255), (int)($c[2] * 255)]);
            }
            return;
        }

        $faction = $this->data->faction($id);
        $flag = (string)$faction['colors']['main'];
        $k = Diorama::SCALE;
        $rig = new Rig($fw, $fh);
        [$target, $distance] = Diorama::camera($fw / $fh);
        $turn = $this->time * ($i === $this->selected ? 0.35 : 0.12) + $i * 1.1;
        $rig->orbit($target, $distance, Diorama::PITCH, Diorama::SWAY * sin($turn));
        $w = $this->world;
        $w->vignette($rig, $frame, (float)$this->width(), (float)$this->height(), $this->shade, $this->time + $i * 37.0);
        $w->draw('diorama:island', static fn () => Diorama::island(), 0.0, 0.0, 0.0, 0.0, $k, [1.0, 1.0, 1.0], 1.0, 0.0, 0.0);
        foreach ($faction['towers'] as $n => $type) {
            $sprite = (string)$this->data->tower($type)['sprite'];
            [$tx, $tz] = Diorama::SPOTS[$n] ?? [0.0, 0.0];
            $w->shadow(($tx + 3.0) * $k, ($tz + 1.5) * $k, 12.0 * $k, 10.0 * $k, -0.4, Diorama::SHADOW, 0.3);
            $w->draw("t:$sprite:3:n:$flag", static fn () => Towers::build($sprite, 3, null, $flag), $tx * $k, 0.0, $tz * $k, 0.0, Diorama::TOWER * $k);
        }
        $foes = $this->foes($faction);
        foreach ($foes as $n => $sprite) {
            $time = $this->time + $i * 0.7;
            [$ux, $uz, $yaw] = Diorama::walker($n, count($foes), $time);
            // the legs in step with the walk round the island
            $step = Diorama::walked($time) / (Units::stride($sprite) * Units::size($sprite) * Diorama::UNIT) + $n * 0.3;
            $w->shadow($ux * $k, $uz * $k, 5.0 * $k, 4.0 * $k, 0.0, Diorama::SHADOW, 0.3);
            $w->creature($sprite, 'walk', $step, $ux * $k, 0.3, $uz * $k, $yaw, Diorama::UNIT * $k);
        }
    }

    private function drawCard(Renderer $r, int $i, string $id): void
    {
        $font = $this->font();
        $faction = $this->data->faction($id);
        $colors = $faction['colors'];
        [$x, $y, $w, $h] = $this->card($i);
        $chosen = $i === $this->selected;
        Paint::panel($r, $x, $y, $w, $h, $chosen ? Paint::GOLD : [70, 66, 90], null, 5);
        Paint::round($r, $x + 3, $y + 3, $w - 6, 4, 2, $colors['main']);
        $inner = $w - 8;
        $ty = $y + 11;
        $nameH = Text::draw($r, $font, Lang::t($faction['name']), $x + $w / 2, $ty, $inner, [255, 226, 130], 'center', [40, 22, 10]);
        // the vignettes in a row, under the longest name
        $ty += ($this->use3d ? $this->namesH : $nameH) + 4;

        // the four towers at their best level: a living 3D vignette (dark under it: see World3d::vignette()), or
        // the picture of their 3D models (or their drawings)
        $pw = $inner;
        $ph = $this->pictureH;
        Paint::round($r, $x + 3, $ty - 1, $pw + 2, $ph + 2, 2, [10, 12, 20]);
        if ($this->use3d) {
            $this->vignette($r, $i, $id, [$x + 4, $ty, $pw, $ph]);
            $ty += $ph + 6;
        } else {
            if (!$this->sprites->drawPicture($r, "side-$id", $x + 4, $ty, $pw, $ph)) {
                $towers = $faction['towers'];
                $tw = $inner / max(1, count($towers));
                foreach ($towers as $k => $tower) {
                    $scale = min(0.75, $tw / 28);
                    $this->sprites->drawTower($r, (string)$this->data->tower($tower)['sprite'], 3, null, $x + 4 + $tw * ($k + 0.5), $ty + 40 * $scale, $scale);
                }
            }
            $ty += max(34, $ph + 6);
        }

        $ty += Text::draw($r, $font, Lang::t($faction['motto']), $x + $w / 2, $ty, $inner, [234, 238, 246], 'center', [16, 20, 36]) + 6;

        // who comes (in 3D they walk round the island: no portraits)
        $enemy = $faction['enemy'] === 'alliance' ? Lang::t('AGAINST THE ALLIANCE') : Lang::t('AGAINST THE HORDE');
        $ty += Text::draw($r, $font, $enemy, $x + $w / 2, $ty, $inner, [250, 160, 140], 'center', [40, 14, 10]) + 4;
        foreach ($this->use3d ? [] : $this->foes($faction) as $k => $sprite) {
            // a little bob, each in its own time
            $bob = abs(sin($this->time * 3.0 + $k * 1.3)) * 1.5;
            if (!$this->sprites->drawPortrait($r, "unit-$sprite", $x + $w / 2 + ($k - 1) * 23, $ty + 13 - $bob, 30, true)) {
                $this->sprites->drawEnemy($r, $sprite, (int)($this->time * 4) + $k, $x + $w / 2 + ($k - 1) * 22, $ty + 8, true, 0.9);
            }
        }

        // the best stars won on the first map
        $stars = $this->progress->stars($id, 'thornvale');
        for ($s = 0; $s < 3; $s++) {
            Paint::icon($r, $s < $stars ? 'star' : 'star_off', $x + $w / 2 - 11 + $s * 11, $y + $h - 10, 10);
        }
    }
}
