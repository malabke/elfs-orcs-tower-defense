<?php

namespace App\Scene;

use App\Td\GameData;
use App\Td\Path;
use App\Ui\FontProvider;
use App\Ui\GameMenuScene;
use App\Ui\Sprites;
use App\Ui\World3d;
use App\View3d\MapModel;
use App\View3d\Rig;
use App\View3d\Towers;
use App\View3d\Units;
use JumPHP\Kernel\Platform;
use JumPHP\Renderer\Renderer;
use JumPHP\Util\Lang;

/**
 * First screen: the title and the main menu over the first map in 3D, the elves' towers on it and the horde marching
 * along the road, the camera drifting slowly over the land (the flat 2D map without WebGL, or with `view: flat`).
 */
final class TitleScene extends GameMenuScene
{
    /** the elves' towers on the title: [slot, sprite, level, specialization] */
    private const TOWERS = [[0, 'archer', 3, null], [2, 'sanctuary', 2, null], [3, 'sentinel', 3, 1], [4, 'sentinel', 2, null],
        [6, 'songmage', 3, 0], [7, 'archer', 3, 1], [10, 'sanctuary', 3, 1]];
    private const HORDE = ['troll', 'warg', 'iron_orc', 'grunt', 'goblin', 'grunt', 'warlord', 'bat', 'grunt', 'goblin', 'iron_orc'];

    protected string $title = 'ELFS & ORCS';
    protected float $menuTop = 0.5;
    protected array $titleColor = [250, 230, 150];
    private float $time = 0.0;
    private bool $use3d = false;
    private ?Rig $rig = null;
    private ?Path $road = null;
    /** @var array<string, mixed> */
    private array $map = [];

    public function __construct(FontProvider $fonts, Sprites $sprites, private World3d $world)
    {
        parent::__construct($fonts, $sprites);
    }

    public function enter(array $params = []): void
    {
        parent::enter($params);
        $this->time = 0.0;
        $data = GameData::load($this->engine->config->path());
        $this->map = $data->map('thornvale');
        $this->use3d = ($params['view'] ?? '3d') !== 'flat' && $this->world->boot($this->engine->gl());
        if ($this->use3d) {
            $this->world->loadMap('thornvale', $this->map, $data->faction('elves')['colors']);
            $this->rig = new Rig((float)$this->width(), (float)$this->height());
            $this->road = Path::fromTiles($this->map['paths'][0]);
        } else {
            $this->world->shutdown($this->engine->gl());
        }
    }

    public function exit(): void
    {
        parent::exit();
        if ($this->use3d) {
            $this->world->shutdown($this->engine->gl());
            $this->use3d = false;
        }
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

    protected function rowBand(): array
    {
        return $this->use3d ? World3d::veil([20, 26, 46, 170]) : parent::rowBand();
    }

    protected function renderBackground(Renderer $r): void
    {
        if ($this->use3d && $this->rig !== null && $this->road !== null) {
            $this->render3d($r);
            return;
        }
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

    /** The land in 3D under a slow camera, the towers, the horde; then a veil of black so that the menu reads well. */
    private function render3d(Renderer $r): void
    {
        $t = $this->time;
        $r->clear($this->world->clearColour());
        $this->rig->orbit([240.0 + 70.0 * sin($t * 0.07), 0.0, 112.0], 440.0, 43.0, 0.1 * sin($t * 0.045));
        $w = $this->world;
        $w->begin($this->rig, $t);
        [$sx, $sz, $k] = MapModel::shadowStep();
        $shadowYaw = -atan2($sz, $sx);
        $flag = '#3fa860';
        foreach (self::TOWERS as [$slot, $sprite, $level, $spec]) {
            [$tx, $ty] = $this->map['slots'][$slot];
            $x = ($tx + 1) * 16.0;
            $z = ($ty + 1) * 16.0;
            $len = Towers::height($level) * 1.3 * $k * 0.8;
            $w->shadow($x + $sx * $len * 0.5, $z + $sz * $len * 0.5, $len * 0.5 + 12.0, 12.5, $shadowYaw, 0.4);
            $w->draw("t:$sprite:$level:" . ($spec ?? 'n') . ":$flag", static fn () => Towers::build($sprite, $level, $spec, $flag),
                $x, MapModel::PLATFORM, $z, 0.0, 1.3);
        }
        $length = $this->road->length;
        foreach (self::HORDE as $i => $sprite) {
            $d = fmod($t * 13.0 + $i * $length / count(self::HORDE), $length);
            [$x, $z, $dx, $dz] = $this->road->at($d);
            $flying = $sprite === 'bat';
            $w->shadow($x + $sx * 3.0, $z + $sz * 3.0, 9.0, 7.0, $shadowYaw, $flying ? 0.2 : 0.4);
            $frame = (int)($t * 9.0 + $i) % Units::FRAMES;
            $w->draw("u:$sprite:$frame", static fn () => Units::build($sprite, $frame), $x, $flying ? 15.0 + sin($t * 5 + $i) * 1.5 : 0.3, $z,
                -atan2($dz, $dx), 2.0);
        }
        // a veil of evening blue over the land so that the menu reads well, deeper towards the title, in soft steps
        $r->fillRect(0, 0, $this->width(), $this->height(), World3d::veil([12, 16, 34, 70]));
        $band = $this->height() * 0.03;
        foreach ([70, 62, 54, 46, 39, 32, 26, 20, 15, 10, 6, 3] as $i => $alpha) {
            $r->fillRect(0, $i * $band, $this->width(), $band, World3d::veil([12, 16, 34, $alpha]));
        }
    }
}
