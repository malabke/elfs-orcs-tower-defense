<?php

namespace App\Scene;

use App\Save\Progress;
use App\Td\Battle;
use App\Td\Bot;
use App\Td\Enemy;
use App\Td\GameData;
use App\Td\Hero;
use App\Td\Tower;
use App\Ui\FontProvider;
use App\Ui\Sfx;
use App\Ui\Sprites;
use App\Ui\Text;
use App\Ui\World3d;
use App\View3d\FlatView;
use App\View3d\MapModel;
use App\View3d\Mesh;
use App\View3d\Projection;
use App\View3d\Props;
use App\View3d\Rig;
use App\View3d\Towers;
use App\View3d\Units;
use JumPHP\Renderer\Renderer;
use JumPHP\Scene\AbstractScene;
use JumPHP\Scene\HotReloadable;
use JumPHP\Util\Lang;

/**
 * A battle on one map. The simulation is App\Td\Battle (stepped at 60 Hz, 1 to 3 steps per frame); this scene
 * reads the player's input (mouse / touch, keys / pad), draws, and plays the sounds of what happened.
 *
 * Building: a click (or confirm on the keyboard cursor) on a stone platform opens a ring of 4 choices around it
 * (up / right / down / left: the keys pick them too); the first click on a choice shows what it does, the second
 * one does it.
 *
 * Drawing: the map and everything on it are 3D (World3d, a WebGL layer under the 2D); the HUD, the ring menu, the
 * bars and the numbers are 2D over it, placed through $cam (the camera's projection). Without WebGL (or with the
 * param view: flat) the scene draws the flat 2D version of the map instead, through the same $cam interface.
 *
 * Params: map, faction; test / demo ones: demo (the bot plays), speed (1..3), gold, lives, countdown (seconds
 * before the first wave), wave (waves already done), seed, view ('flat': no 3D).
 */
final class PlayScene extends AbstractScene implements HotReloadable
{
    public const MAP_Y = 30;
    private const RING = 28;
    private const RING_HIT = 13;
    private const DIRS = ['up' => [0, -1], 'right' => [1, 0], 'down' => [0, 1], 'left' => [-1, 0]];
    /** the sound of each projectile when it is thrown (Sfx) */
    private const SHOT_SOUNDS = ['arrow' => 'arrow', 'bolt' => 'arrow', 'javelin' => 'arrow', 'stone' => 'arrow', 'orb' => 'orb',
        'pitch' => 'orb', 'spark' => 'orb', 'root' => 'root', 'rock' => 'root', 'note' => 'note', 'star' => 'star'];

    private Battle $battle;
    private ?Bot $bot = null;
    private GameData $data;
    private array $params = [];
    private array $map = [];
    private array $colors = [];
    private int $speed = 1;
    private float $accumulator = 0.0;
    private float $time = 0.0;
    /** the slot whose ring is open */
    private ?int $menuSlot = null;
    /** the chosen option of the ring (shown, done on a second press) */
    private ?string $choice = null;
    /** the keyboard / pad cursor */
    private int $cursor = 0;
    private bool $keyboard = false;
    /** @var list<array{text: string, x: float, y: float, t: float, color: array}> rising texts, screen coordinates */
    private array $floaters = [];
    /** @var list<array{x: float, y: float, kind: string, t: float, splash: float}> */
    private array $flashes = [];
    /** @var list<array{0: string, 1: string, 2: float}> banners waiting: title, line, seconds */
    private array $banners = [];
    private float $bannerTime = 0.0;
    /** @var array<string, bool> enemies already announced */
    private array $seen = [];
    private float $endTimer = -1.0;
    private float $shake = 0.0;
    /** choosing where to send the hero ('hero') or to cast the side's power ('power'); null: building */
    private ?string $aim = null;
    /** where the keyboard / pad points while aiming (play area pixels) */
    private float $reticleX = 0.0;
    private float $reticleY = 0.0;
    /** the 3D layer is on (else the flat 2D drawing) */
    private bool $use3d = false;
    private Projection $cam;
    private ?Rig $rig = null;
    /** creatures are drawn this much bigger than their models (readable from the camera's height) */
    private const UNIT_SCALE = 2.0;
    /** towers are drawn this much bigger than their models (a platform is 30 wide) */
    private const TOWER_SCALE = 1.3;

    public function __construct(private FontProvider $fonts, private Sprites $sprites, private Progress $progress, private World3d $world)
    {
        $this->cam = new FlatView(self::MAP_Y);
    }

    /** Where a point of the map is on the screen (the 3D camera, or the flat view): for tests and overlays. @return array{0: float, 1: float} */
    public function screenOf(float $x, float $y, float $height = 0.0): array
    {
        return $this->cam->screen($x, $y, $height);
    }

    public function exit(): void
    {
        if ($this->use3d) {
            $this->engine->gl()->disable();
            $this->use3d = false;
        }
    }

    /** The 3D layer, the camera fitted on the platforms, the ground built. Flat when WebGL is not there. */
    private function setUpView(string $mapId, bool $wanted): void
    {
        $gl = $this->engine->gl();
        $this->use3d = $wanted && $this->world->boot($gl);
        if (!$this->use3d) {
            $gl->disable();
            $this->cam = new FlatView(self::MAP_Y);
            $this->rig = null;
            return;
        }
        $this->world->loadMap($mapId, $this->map, $this->colors);
        $minX = $minZ = INF;
        $maxX = $maxZ = -INF;
        $grow = static function (float $x0, float $z0, float $x1, float $z1) use (&$minX, &$minZ, &$maxX, &$maxZ): void {
            $minX = min($minX, $x0);
            $minZ = min($minZ, $z0);
            $maxX = max($maxX, $x1);
            $maxZ = max($maxZ, $z1);
        };
        foreach ($this->map['slots'] as [$sx, $sy]) {
            $grow(($sx + 1) * 16.0 - 18, ($sy + 1) * 16.0 - 18, ($sx + 1) * 16.0 + 18, ($sy + 1) * 16.0 + 18);
        }
        [$bx, $by] = $this->map['bastion'];
        $grow($bx * 16.0, $by * 16.0, $bx * 16.0 + 48, $by * 16.0 + 96);
        foreach ($this->map['paths'] as $points) {
            foreach ($points as [$px, $pz]) {
                $grow(max(0.0, min(480.0, $px * 16.0)), max(0.0, min(240.0, $pz * 16.0)), max(0.0, min(480.0, $px * 16.0)), max(0.0, min(240.0, $pz * 16.0)));
            }
        }
        $this->rig = new Rig((float)$this->width(), (float)$this->height());
        $this->rig->fit($minX, $minZ, $maxX, $maxZ, 60.0, self::MAP_Y + 6.0, $this->height() - 4.0, 52.0);
        $this->cam = $this->rig;
    }

    public function enter(array $params = []): void
    {
        $this->params = $params;
        $this->fonts->apply($this->engine);
        $this->sprites->attach($this->sdl(), $this->engine->config->path());
        $this->progress->load($this->engine->storage);
        Sfx::load($this->sound(), $this->engine->config->path());

        $mapId = (string)($params['map'] ?? 'thornvale');
        $faction = (string)($params['faction'] ?? 'elves');
        $this->data = GameData::load($this->engine->config->path());
        $this->map = $this->data->map($mapId);
        $this->colors = $this->data->faction($faction)['colors'];
        $this->battle = new Battle($this->data, $mapId, $faction, (int)($params['seed'] ?? 7), !empty($params['hard']));
        if (isset($params['gold'])) {
            $this->battle->gold = (int)$params['gold'];
        }
        if (isset($params['lives'])) {
            $this->battle->lives = (int)$params['lives'];
        }
        if (isset($params['wave'])) {
            $this->battle->skipTo((int)$params['wave']);
        }
        if (isset($params['countdown'])) {
            $this->battle->countdown = (float)$params['countdown'];
        }
        $this->setUpView($mapId, ($params['view'] ?? '3d') !== 'flat');
        $demo = !empty($params['demo']);
        $this->bot = $demo ? new Bot($this->battle, true, true) : null;
        $this->aim = null;
        $this->reticleX = GameData::COLS * GameData::TILE / 2;
        $this->reticleY = GameData::ROWS * GameData::TILE / 2;
        $this->speed = max(1, min(3, (int)($params['speed'] ?? ($demo ? 3 : 1))));
        $this->accumulator = 0.0;
        $this->time = 0.0;
        $this->menuSlot = null;
        $this->choice = null;
        $this->cursor = 0;
        $this->keyboard = false;
        $this->floaters = [];
        $this->flashes = [];
        $this->seen = [];
        $this->endTimer = -1.0;
        $this->shake = 0.0;
        $this->banners = [[Lang::t($this->map['name']), Lang::t('PREPARE YOUR DEFENSES'), 3.5]];
        if (!$demo) {
            $this->banners[] = [Lang::t('BUILD ON THE STONE PLATFORMS'), '', 3.0];
        }
        $this->bannerTime = 0.0;
    }

    public function hotState(): array
    {
        return ['battle' => $this->battle->snapshot(), 'speed' => $this->speed];
    }

    public function hotRestore(array $state): void
    {
        $this->battle->restore($state['battle'] ?? []);
        $this->speed = (int)($state['speed'] ?? 1);
    }

    /** The PlayScene's parameters without the test ones: to play the same map again. */
    private function restartParams(): array
    {
        return array_intersect_key($this->params, ['map' => 1, 'faction' => 1, 'demo' => 1, 'hard' => 1]);
    }

    // ---- update -----------------------------------------------------------------------------------------------

    public function update(float $dt): void
    {
        $this->time += $dt;
        $this->updateEffects($dt);
        $this->rig?->update($this->shake > 0 ? $this->shake * 5.0 : 0.0, $this->time);
        if ($this->endTimer >= 0) {
            $this->endTimer += $dt;
            if ($this->endTimer > 1.5) {
                $this->endTimer = -2.0;
                $this->finish();
            }
            return;
        }
        if ($this->endTimer < -1.5) {
            return;   // the end overlay is shown
        }

        if (!$this->handleInput($dt)) {
            return;
        }
        $this->bot?->play();
        $this->accumulator += $dt * $this->speed;
        for ($steps = 0; $this->accumulator >= Battle::STEP - 1e-9 && $steps < 12; $steps++) {
            $this->battle->step();
            $this->accumulator -= Battle::STEP;
            if ($this->bot !== null && $steps % 6 === 5) {
                $this->bot->play();
            }
        }
        $this->accumulator = min($this->accumulator, Battle::STEP);
        $this->handleEvents();
    }

    /** False when the scene changed (pause). */
    private function handleInput(float $dt): bool
    {
        $actions = $this->actions();
        $mouse = $this->engine->input->getMouse();
        if ($this->menuSlot !== null && ($actions->isPressed('back') || $mouse->isPressed(3))) {
            $this->closeMenu();
            return true;
        }
        if ($this->aim !== null) {
            $this->handleAim($dt);
            return true;
        }
        if ($actions->isPressed('pause')) {
            $this->scenes()->push(PauseScene::class, ['restart' => $this->restartParams()]);
            return false;
        }
        if ($mouse->isPressed()) {
            $this->keyboard = false;
            return $this->click($mouse->x, $mouse->y);
        }
        if ($actions->isPressed('speed')) {
            $this->cycleSpeed();
        }
        if ($actions->isPressed('call_wave')) {
            $this->callWave();
        }
        if ($actions->isPressed('hero')) {
            $this->keyboard = true;
            $this->startAim('hero');
            return true;
        }
        if ($actions->isPressed('power')) {
            $this->keyboard = true;
            $this->startAim('power');
            return true;
        }
        foreach (self::DIRS as $dir => $vector) {
            if ($actions->isPressed($dir)) {
                $this->keyboard = true;
                if ($this->menuSlot !== null) {
                    $this->chooseByDirection($dir);
                } else {
                    $this->moveCursor($vector);
                }
            }
        }
        if ($actions->isPressed('confirm')) {
            $this->keyboard = true;
            if ($this->menuSlot === null) {
                $this->openMenu($this->cursor);
            } elseif ($this->choice !== null) {
                $this->activate($this->choice);
            }
        }
        return true;
    }

    // ---- the hero and the side's power ------------------------------------------------------------------------

    /** @return list<array{id: string, x: float, y: float, r: float}> the round buttons at the bottom left of the map */
    private function mapButtons(): array
    {
        $buttons = [];
        $y = $this->height() - 17;
        if ($this->battle->hero !== null) {
            $buttons[] = ['id' => 'hero', 'x' => 17.0, 'y' => (float)$y, 'r' => 14.0];
        }
        $buttons[] = ['id' => 'power', 'x' => count($buttons) * 32 + 17.0, 'y' => (float)$y, 'r' => 14.0];
        return $buttons;
    }

    private function startAim(string $what): void
    {
        $this->closeMenu();
        if ($what === 'hero' && ($this->battle->hero === null || !$this->battle->hero->isAlive())) {
            $this->sound()->playSfx('deny');
            return;
        }
        if ($what === 'power' && !$this->battle->powerReady()) {
            $this->sound()->playSfx('deny');
            return;
        }
        $this->aim = $what;
        if ($this->keyboard) {
            // from the hero, or from the enemy nearest to the bastion
            $from = $what === 'hero' ? $this->battle->hero : null;
            if ($from === null) {
                foreach ($this->battle->enemies as $enemy) {
                    if ($enemy->isTargetable() && ($from === null || $enemy->remaining() < $from->remaining())) {
                        $from = $enemy;
                    }
                }
            }
            if ($from !== null) {
                $this->reticleX = $from->x;
                $this->reticleY = $from->y;
            }
        }
        $this->sound()->playSfx('menu_move');
    }

    /** Aiming: the mouse (or the arrows) points, a click (or confirm) sends the hero / casts the power. */
    private function handleAim(float $dt): void
    {
        $actions = $this->actions();
        $mouse = $this->engine->input->getMouse();
        if ($actions->isPressed('back') || $mouse->isPressed(3)) {
            $this->aim = null;
            return;
        }
        $dx = ($actions->isHeld('right') ? 1 : 0) - ($actions->isHeld('left') ? 1 : 0);
        $dy = ($actions->isHeld('down') ? 1 : 0) - ($actions->isHeld('up') ? 1 : 0);
        if ($dx !== 0 || $dy !== 0) {
            $this->keyboard = true;
            $this->reticleX = max(0.0, min(GameData::COLS * GameData::TILE, $this->reticleX + $dx * 150 * $dt));
            $this->reticleY = max(0.0, min(GameData::ROWS * GameData::TILE, $this->reticleY + $dy * 150 * $dt));
        } elseif (!$this->keyboard && $mouse->y > self::MAP_Y) {
            [$this->reticleX, $this->reticleY] = $this->cam->world($mouse->x, $mouse->y);
        }
        if ($mouse->isPressed()) {
            $this->keyboard = false;
            foreach ($this->mapButtons() as $b) {
                if (($mouse->x - $b['x']) ** 2 + ($mouse->y - $b['y']) ** 2 <= $b['r'] ** 2) {
                    // the same button again: cancel; the other one: aim with it
                    $this->aim = $b['id'] === $this->aim ? null : $this->aim;
                    if ($this->aim !== null) {
                        $this->startAim($b['id']);
                    }
                    return;
                }
            }
            if ($mouse->y > self::MAP_Y) {
                [$wx, $wy] = $this->cam->world($mouse->x, $mouse->y);
                $this->applyAim($wx, $wy);
            } else {
                $this->aim = null;
            }
            return;
        }
        if ($actions->isPressed('confirm')) {
            $this->applyAim($this->reticleX, $this->reticleY);
        }
    }

    private function applyAim(float $x, float $y): void
    {
        $done = $this->aim === 'hero' ? $this->battle->moveHero($x, $y) : $this->battle->castPower($x, $y);
        $this->sound()->playSfx($done ? 'menu_confirm' : 'deny');
        $this->aim = null;
    }

    private function click(float $x, float $y): bool
    {
        foreach ($this->mapButtons() as $b) {
            if (($x - $b['x']) ** 2 + ($y - $b['y']) ** 2 <= $b['r'] ** 2) {
                $this->startAim($b['id']);
                return true;
            }
        }
        foreach ($this->hudButtons() as $button) {
            if ($x >= $button['x'] && $x < $button['x'] + $button['w'] && $y >= $button['y'] && $y < $button['y'] + $button['h']) {
                if ($button['id'] === 'pause') {
                    $this->scenes()->push(PauseScene::class, ['restart' => $this->restartParams()]);
                    return false;
                }
                if ($button['id'] === 'speed') {
                    $this->cycleSpeed();
                } else {
                    $this->callWave();
                }
                return true;
            }
        }
        if ($this->menuSlot !== null) {
            foreach ($this->ringOptions($this->menuSlot) as $option) {
                if (($x - $option['x']) ** 2 + ($y - $option['y']) ** 2 <= self::RING_HIT ** 2) {
                    if ($option['id'] === $this->choice) {
                        $this->activate($option['id']);
                    } else {
                        $this->choose($option['id']);
                    }
                    return true;
                }
            }
        }
        foreach ($this->battle->slots as $i => $slot) {
            // the platform, or the tower standing on it
            [$px, $py] = $this->cam->screen($slot['x'], $slot['y']);
            [, $ty] = $this->cam->screen($slot['x'], $slot['y'], 22.0);
            $rad = max(14.0, 17.0 * $this->cam->scale($slot['x'], $slot['y']));
            if (($x - $px) ** 2 + ($y - $py) ** 2 <= $rad ** 2 || ($x - $px) ** 2 + ($y - $ty) ** 2 <= $rad ** 2) {
                $this->cursor = $i;
                if ($this->menuSlot === $i) {
                    $this->closeMenu();
                } else {
                    $this->openMenu($i);
                }
                return true;
            }
        }
        $this->closeMenu();
        return true;
    }

    private function cycleSpeed(): void
    {
        $this->speed = $this->speed % 3 + 1;
        $this->sound()->playSfx('menu_move');
    }

    private function callWave(): void
    {
        if (!$this->battle->canCallWave()) {
            $this->sound()->playSfx('deny');
            return;
        }
        $bonus = $this->battle->callNextWave();
        if ($bonus > 0) {
            $this->floaters[] = ['text' => '+' . $bonus, 'x' => 80, 'y' => 22, 't' => 0.0, 'color' => [255, 220, 90]];
            $this->sound()->playSfx('coin');
        }
    }

    private function openMenu(int $slot): void
    {
        $this->menuSlot = $slot;
        $this->choice = null;
        $this->sound()->playSfx('menu_move');
    }

    private function closeMenu(): void
    {
        $this->menuSlot = null;
        $this->choice = null;
    }

    private function choose(string $id): void
    {
        $this->choice = $id;
        $this->sound()->playSfx('menu_move');
    }

    private function chooseByDirection(string $dir): void
    {
        foreach ($this->ringOptions((int)$this->menuSlot) as $option) {
            if ($option['dir'] === $dir) {
                $this->choose($option['id']);
                return;
            }
        }
    }

    /** Jumps to the nearest slot in a direction. */
    private function moveCursor(array $vector): void
    {
        $from = $this->battle->slots[$this->cursor];
        $best = null;
        $bestScore = INF;
        foreach ($this->battle->slots as $i => $slot) {
            $dx = $slot['x'] - $from['x'];
            $dy = $slot['y'] - $from['y'];
            $along = $dx * $vector[0] + $dy * $vector[1];
            if ($i === $this->cursor || $along <= 0) {
                continue;
            }
            $score = $along + 2 * abs($dx * $vector[1] - $dy * $vector[0]);
            if ($score < $bestScore) {
                $bestScore = $score;
                $best = $i;
            }
        }
        if ($best !== null) {
            $this->cursor = $best;
            $this->sound()->playSfx('menu_move');
        }
    }

    private function activate(string $id): void
    {
        $slot = (int)$this->menuSlot;
        $option = null;
        foreach ($this->ringOptions($slot) as $candidate) {
            if ($candidate['id'] === $id) {
                $option = $candidate;
            }
        }
        if ($option === null || !$option['enabled']) {
            $this->sound()->playSfx('deny');
            return;
        }
        [$what, $arg] = array_pad(explode(':', $id, 2), 2, '');
        $done = match ($what) {
            'build' => $this->battle->build($slot, $arg),
            'upgrade' => $this->battle->upgrade($slot),
            'spec' => $this->battle->specialize($slot, $arg),
            'sell' => $this->battle->sell($slot) > 0,
            'target' => (bool)$this->battle->tower($slot)?->cycleTargeting(),
            default => false,
        };
        if (!$done) {
            $this->sound()->playSfx('deny');
            return;
        }
        if ($what === 'target') {
            $this->sound()->playSfx('menu_move');   // the ring stays open: press again for the next mode
            return;
        }
        $this->closeMenu();
    }

    /**
     * The choices around a slot.
     * @return list<array{id: string, dir: string, x: float, y: float, icon: string, tower: ?string, cost: ?int, enabled: bool}>
     */
    private function ringOptions(int $slot): array
    {
        $tower = $this->battle->tower($slot);
        $options = [];
        if ($tower === null) {
            foreach (array_values($this->battle->towerTypes()) as $i => $type) {
                $cost = $this->data->upgradeCost($type, 1);
                $options[] = ['id' => "build:$type", 'dir' => array_keys(self::DIRS)[$i % 4], 'icon' => '', 'tower' => $type,
                    'cost' => $cost, 'enabled' => $this->battle->gold >= $cost];
            }
        } else {
            if ($tower->canSpecialize()) {
                foreach ($tower->specNames() as $i => $spec) {
                    $cost = (int)$tower->nextCost($spec);
                    $options[] = ['id' => "spec:$spec", 'dir' => $i === 0 ? 'up' : 'left', 'icon' => 'star', 'tower' => null,
                        'cost' => $cost, 'enabled' => $this->battle->gold >= $cost];
                }
            } elseif ($tower->nextCost() !== null) {
                $cost = (int)$tower->nextCost();
                $options[] = ['id' => 'upgrade', 'dir' => 'up', 'icon' => 'up', 'tower' => null, 'cost' => $cost, 'enabled' => $this->battle->gold >= $cost];
            }
            if ($tower->hasTargeting()) {
                $options[] = ['id' => 'target', 'dir' => 'right', 'icon' => 'target', 'tower' => null, 'cost' => null, 'enabled' => true];
            }
            $options[] = ['id' => 'sell', 'dir' => 'down', 'icon' => 'sell', 'tower' => null, 'cost' => $tower->sellValue(), 'enabled' => true];
        }
        [$cx, $cy] = $this->cam->screen($this->battle->slots[$slot]['x'], $this->battle->slots[$slot]['y']);
        foreach ($options as &$option) {
            [$dx, $dy] = self::DIRS[$option['dir']];
            $option['x'] = max(14, min($this->width() - 14, $cx + $dx * self::RING));
            $option['y'] = max(self::MAP_Y + 14, min($this->height() - 20, $cy + $dy * self::RING));
        }
        unset($option);
        return $options;
    }

    /** @return list<array{id: string, x: float, y: float, w: float, h: float}> the top bar's buttons */
    private function hudButtons(): array
    {
        $w = $this->width();
        $font = $this->font();
        $label = $this->callLabel();
        $callW = $font->width($label) + 26;
        return [
            ['id' => 'pause', 'x' => $w - 28, 'y' => 2, 'w' => 26, 'h' => 26],
            ['id' => 'speed', 'x' => $w - 62, 'y' => 2, 'w' => 32, 'h' => 26],
            ['id' => 'call', 'x' => $w - 64 - $callW, 'y' => 2, 'w' => $callW, 'h' => 26],
        ];
    }

    private function callLabel(): string
    {
        $bonus = $this->battle->earlyBonus();
        return $this->battle->canCallWave() && $bonus > 0 ? Lang::t('CALL WAVE') . ' +' . $bonus : Lang::t('CALL WAVE');
    }

    // ---- what happened ----------------------------------------------------------------------------------------

    private function handleEvents(): void
    {
        $played = [];
        $play = function (string $sfx) use (&$played): void {
            if (!isset($played[$sfx])) {
                $played[$sfx] = true;
                $this->sound()->playSfx($sfx);
            }
        };
        foreach ($this->battle->events as $event) {
            switch ($event['type']) {
                case 'shot':
                    $play(self::SHOT_SOUNDS[(string)$event['projectile']] ?? 'arrow');
                    break;
                case 'hit':
                    $this->flashes[] = ['x' => $event['x'], 'y' => $event['y'], 'kind' => $event['pulse'] ? 'pulse' : $event['kind'], 't' => 0.0,
                        'splash' => $event['splash']];
                    if ($event['splash'] > 0) {
                        $play('boom');
                    }
                    if ($event['crit']) {
                        $this->floatAt('!', $event['x'], $event['y'], 18.0, [255, 120, 80]);
                    }
                    break;
                case 'kill':
                    $play('kill');
                    if ($event['gold'] > 0) {
                        $this->floatAt('+' . $event['gold'], $event['x'], $event['y'], 16.0, [255, 220, 90]);
                    }
                    break;
                case 'leak':
                    $play('leak');
                    $this->shake = 0.3;
                    break;
                case 'wave':
                    $play('wave');
                    $this->announceWave((int)$event['wave'], (bool)$event['boss']);
                    break;
                case 'wave_cleared':
                    if ($event['gold'] > 0) {
                        $this->floaters[] = ['text' => '+' . $event['gold'], 'x' => 80, 'y' => 22, 't' => 0.0, 'color' => [255, 220, 90]];
                        $play('coin');
                    }
                    break;
                case 'dodge':
                    $this->floatAt(Lang::t('MISS'), $event['x'], $event['y'], 18.0, [200, 220, 255]);
                    break;
                case 'income':
                    $this->floatAt('+' . $event['gold'], $event['x'], $event['y'], 40.0, [255, 220, 90]);
                    $play('coin');
                    break;
                case 'heal':
                    $this->flashes[] = ['x' => $event['x'], 'y' => $event['y'], 'kind' => 'heal', 't' => 0.0, 'splash' => $event['radius']];
                    break;
                case 'trap':
                    $play('root');
                    break;
                case 'wall_down':
                    $play('boom');
                    break;
                case 'soldier_down':
                case 'hero_down':
                    $play('kill');
                    break;
                case 'hero_skill':
                    $play('boom');
                    $this->shake = max($this->shake, 0.15);
                    break;
                case 'power':
                    $play('star');
                    $this->flashes[] = ['x' => $event['x'], 'y' => $event['y'], 'kind' => 'power', 't' => 0.0, 'splash' => $event['radius']];
                    $this->shake = max($this->shake, 0.2);
                    break;
                case 'build':
                    $play('build');
                    break;
                case 'upgrade':
                    $play('upgrade');
                    break;
                case 'sell':
                    $play('sell');
                    break;
                case 'won':
                case 'lost':
                    $this->endTimer = 0.0;
                    $this->closeMenu();
                    break;
            }
        }
        $this->battle->events = [];
    }

    /** A number or a word that rises from a point of the map (above the ground by $height). */
    private function floatAt(string $text, float $x, float $y, float $height, array $color): void
    {
        [$sx, $sy] = $this->cam->screen($x, $y, $height);
        $this->floaters[] = ['text' => $text, 'x' => $sx, 'y' => $sy, 't' => 0.0, 'color' => $color];
    }

    private function announceWave(int $wave, bool $boss): void
    {
        $title = $wave === $this->battle->waveCount() ? Lang::t('FINAL WAVE') : Lang::t('WAVE {n}', ['n' => $wave]);
        $this->banners[] = [$title, $boss ? Lang::t('A BOSS IS COMING!') : '', 2.0];
        // enemies of this wave never seen before get a word (not the first ones)
        $groups = $this->data->wavesFor($this->battle->mapId, $this->battle->enemySide)[$wave - 1]['groups'] ?? [];
        foreach ($groups as $group) {
            $type = (string)$group['enemy'];
            if (isset($this->seen[$type])) {
                continue;
            }
            $this->seen[$type] = true;
            if (count($this->seen) > 1) {
                $def = $this->data->enemy($type);
                $this->banners[] = [Lang::t('NEW ENEMY') . ': ' . Lang::t($def['name']), $this->traits($def), 2.5];
            }
        }
    }

    /** What sets an enemy apart, in words. */
    private function traits(array $def): string
    {
        $words = [];
        if (!empty($def['boss'])) {
            $words[] = Lang::t('BOSS');
        }
        if (!empty($def['flying'])) {
            $words[] = Lang::t('FLYING');
        }
        if (($def['armor'] ?? '') === 'armored') {
            $words[] = Lang::t('ARMORED');
        }
        if (($def['armor'] ?? '') === 'magic_resist') {
            $words[] = Lang::t('RESISTS MAGIC');
        }
        if ((float)$def['speed'] >= 40) {
            $words[] = Lang::t('FAST');
        }
        if (!empty($def['dodge'])) {
            $words[] = Lang::t('DODGES');
        }
        if (!empty($def['stealth'])) {
            $words[] = Lang::t('HIDDEN');
        }
        if (!empty($def['tunnel'])) {
            $words[] = Lang::t('DIGS UNDER THE ROAD');
        }
        if (!empty($def['heal'])) {
            $words[] = Lang::t('HEALS');
        }
        return implode(' - ', $words);
    }

    private function finish(): void
    {
        $this->banners = [];
        $restart = $this->restartParams();
        if ($this->battle->state === 'won') {
            $this->progress->record($this->battle->faction, $this->battle->mapId, $this->battle->stars(), $this->battle->hard);
            $this->scenes()->push(VictoryScene::class, ['stars' => $this->battle->stars(), 'lives' => $this->battle->lives, 'restart' => $restart]);
        } else {
            $this->scenes()->push(DefeatScene::class, ['wave' => $this->battle->waveIndex, 'restart' => $restart]);
        }
    }

    private function updateEffects(float $dt): void
    {
        foreach ($this->floaters as $k => &$floater) {
            $floater['t'] += $dt;
            $floater['y'] -= 14 * $dt;
            if ($floater['t'] > 0.9) {
                unset($this->floaters[$k]);
            }
        }
        unset($floater);
        foreach ($this->flashes as $k => &$flash) {
            $flash['t'] += $dt;
            if ($flash['t'] > 0.25) {
                unset($this->flashes[$k]);
            }
        }
        unset($flash);
        $this->shake = max(0.0, $this->shake - $dt);
        if ($this->banners !== []) {
            $this->bannerTime += $dt;
            if ($this->bannerTime > $this->banners[0][2]) {
                array_shift($this->banners);
                $this->bannerTime = 0.0;
            }
        }
    }

    // ---- render -----------------------------------------------------------------------------------------------

    public function render(Renderer $r): void
    {
        if ($this->use3d) {
            $this->render3d($r);
            return;
        }
        $r->clear([20, 30, 20]);
        $sy = self::MAP_Y + ($this->shake > 0 ? (int)round(sin($this->time * 80) * 2) : 0);
        $this->sprites->drawMap($r, $this->battle->mapId, $this->map, $this->colors, 0, $sy);

        // walls and traps lie on the road, under everything
        foreach ($this->battle->towers() as $tower) {
            if ($tower->road !== null && $tower->kind() !== 'barracks') {
                $this->drawRoadObject($r, $tower, $sy);
            }
        }
        // towers, soldiers and enemies, the lower ones in front
        $things = [];
        foreach ($this->battle->towers() as $tower) {
            $things[] = [$tower->y + 8, 'tower', $tower];
            foreach ($tower->soldiers as $soldier) {
                if ($soldier->isAlive()) {
                    $things[] = [$soldier->y, 'soldier', [$soldier, (string)$tower->stats['soldiers']['sprite']]];
                }
            }
        }
        foreach ($this->battle->enemies as $enemy) {
            $things[] = [$enemy->y + ($enemy->isFlying() ? 40 : 0), 'enemy', $enemy];
        }
        if ($this->battle->hero?->isAlive()) {
            $things[] = [$this->battle->hero->y, 'hero', $this->battle->hero];
        }
        usort($things, static fn (array $a, array $b) => $a[0] <=> $b[0]);
        foreach ($things as [, $kind, $thing]) {
            if ($kind === 'tower') {
                $this->drawTower($r, $thing, $sy);
            } elseif ($kind === 'hero') {
                $this->drawHero($r, $thing, $sy);
            } elseif ($kind === 'soldier') {
                $this->drawSoldier($r, $thing[0], $thing[1], $sy);
            } else {
                $this->drawEnemy($r, $thing, $sy);
            }
        }
        $this->drawProjectiles($r, $sy);
        foreach ($this->floaters as $floater) {
            $this->font()->draw($r, $floater['text'], $floater['x'], $floater['y'], 1, $floater['color'], 'center', [0, 0, 0]);
        }

        if ($this->keyboard && $this->menuSlot === null && $this->aim === null && isset($this->battle->slots[$this->cursor])) {
            $slot = $this->battle->slots[$this->cursor];
            $pulse = fmod($this->time, 0.8) < 0.4 ? 1 : 0;
            $r->drawRect($slot['x'] - 16 - $pulse, $slot['y'] + $sy - 14 - $pulse, 32 + 2 * $pulse, 30 + 2 * $pulse, [255, 230, 100], 1);
        }
        // the banner under the ring: it never hides what the player is choosing
        $this->drawBanner($r);
        if ($this->menuSlot !== null) {
            $this->drawRing($r, $this->menuSlot);
        }
        if ($this->aim !== null) {
            $this->drawAim($r, $sy);
        }
        $this->drawMapButtons($r);
        $this->drawHud($r);
    }

    // ---- the 3D version ---------------------------------------------------------------------------------------

    /** The 3D layer (ground, towers, creatures, shots, glows), then the 2D over it: bars, banner, ring menu, HUD. */
    private function render3d(Renderer $r): void
    {
        $r->clear(World3d::KEY);
        if ($this->rig === null) {
            return;
        }
        $w = $this->world;
        $b = $this->battle;
        $w->begin($this->rig);
        $black = [0.0, 0.0, 0.0];

        // shadows first: they lie on the ground, everything else stands over them
        foreach ($b->enemies as $e) {
            if (!$e->isUnderground()) {
                $w->disc($e->x, $e->y, ($e->isFlying() ? 3.4 : 5.2) * (float)($e->def['scale'] ?? 1.0) * self::UNIT_SCALE * 0.9, $black,
                    $e->isFlying() ? 0.2 : 0.36);
            }
        }
        foreach ($b->towers() as $t) {
            foreach ($t->soldiers as $s) {
                if ($s->isAlive()) {
                    $w->disc($s->x, $s->y, 5.4, $black, 0.36);
                }
            }
        }
        if ($b->hero?->isAlive()) {
            $w->disc($b->hero->x, $b->hero->y, 6.4, Mesh::rgb((string)($this->colors['main'] ?? '#ffffff'), 0.9), 0.85, 0.7, 1.0);
        }

        foreach ($b->towers() as $t) {
            if ($t->road !== null && $t->kind() !== 'barracks') {
                $this->roadObject3d($t);
            }
            $this->tower3d($t);
            foreach ($t->soldiers as $s) {
                if ($s->isAlive()) {
                    $sprite = (string)$t->stats['soldiers']['sprite'];
                    $this->creature3d($sprite, 1.0, $s->x, $s->y, $this->headingOf($s->x, $s->y, $s->target, $s->facingLeft), (int)($s->age * 7) % 2);
                }
            }
        }
        if ($b->hero?->isAlive()) {
            $h = $b->hero;
            $moving = $h->isWalking() || $h->isFighting();
            $this->creature3d((string)$h->def['sprite'], (float)($h->def['scale'] ?? 1.0), $h->x, $h->y,
                $this->headingOf($h->x, $h->y, $h->target, $h->facingLeft), $moving ? (int)($h->age * 7) % 2 : 0);
        }
        foreach ($b->enemies as $e) {
            $this->enemy3d($e);
        }
        foreach ($b->projectiles as $p) {
            $this->projectile3d($p);
        }
        $this->effects3d();

        // the 2D over the 3D
        $this->bars3d($r);
        foreach ($this->floaters as $floater) {
            $this->font()->draw($r, $floater['text'], $floater['x'], $floater['y'], 1, $floater['color'], 'center', [0, 0, 0]);
        }
        $this->drawBanner($r);
        if ($this->menuSlot !== null) {
            $this->drawRing($r, $this->menuSlot);
        }
        if ($this->aim !== null) {
            $this->drawAim($r, 0);
        }
        $this->drawMapButtons($r);
        $this->drawHud($r);
    }

    /** The yaw (about y) of a creature that looks at its target, or the way it walks. */
    private function headingOf(float $x, float $y, ?Enemy $target, bool $left): float
    {
        if ($target !== null) {
            return -atan2($target->y - $y, $target->x - $x);
        }
        return $left ? M_PI : 0.0;
    }

    private function creature3d(string $sprite, float $size, float $x, float $y, float $yaw, int $frame, array $tint = [1.0, 1.0, 1.0],
                                float $alpha = 1.0, float $lift = 0.3): void
    {
        $this->world->draw("u:$sprite:$frame", static fn () => Units::build($sprite, $frame), $x, $lift, $y, $yaw, $size * self::UNIT_SCALE, $tint, $alpha);
    }

    private function enemy3d(Enemy $e): void
    {
        $def = $e->def;
        if ($e->isUnderground()) {
            $this->world->draw('mound', static fn () => Props::mound(), $e->x, 0.4, $e->y, 0.0, 1.4);
            return;
        }
        $tint = [1.0, 1.0, 1.0];
        if ($e->isStunned()) {
            $tint = [0.75, 0.75, 0.82];
        }
        if ($e->isSlowed()) {
            $tint = [0.72, 0.92, 1.35];
        }
        if ($e->isBurning()) {
            $tint = [1.4, 0.8 + 0.25 * sin($this->time * 20), 0.62];
        }
        $alpha = $e->isStealthy() && !$e->revealed ? 0.28 : 1.0;
        $lift = $e->isFlying() ? 15.0 + sin($e->age * 5) * 1.5 : 0.3 + abs(sin($e->age * 8)) * 0.5;
        $this->creature3d((string)$def['sprite'], (float)($def['scale'] ?? 1.0), $e->x, $e->y, -atan2($e->dirY, $e->dirX),
            $e->isStunned() ? 0 : (int)($e->age * 7) % 2, $tint, $alpha, $lift);
    }

    private function tower3d(Tower $t): void
    {
        $sprite = (string)$this->data->tower($t->type)['sprite'];
        $lv = min(3, $t->level);
        $spec = $t->spec !== null ? (int)array_search($t->spec, $t->specNames(), true) : null;
        $flag = (string)($this->colors['main'] ?? '#3fa860');
        // a small jolt when it shoots
        $jolt = $t->sinceShot < 0.1 ? 1.0 + 0.05 * (1 - $t->sinceShot / 0.1) : 1.0;
        $this->world->draw("t:$sprite:$lv:" . ($spec ?? 'n') . ":$flag", static fn () => Towers::build($sprite, $lv, $spec, $flag),
            $t->x, MapModel::PLATFORM, $t->y, 0.0, [self::TOWER_SCALE, self::TOWER_SCALE * $jolt, self::TOWER_SCALE]);
    }

    /** A wall across the road (broken: stumps), or a trap (open jaws when armed). */
    private function roadObject3d(Tower $t): void
    {
        $road = (array)$t->road;
        [, , $dx, $dz] = $this->battle->paths[$road['path']]->at($road['distance']);
        $yaw = -atan2($dz, $dx);
        if ($t->kind() === 'barrier') {
            $stone = $t->spec === 'stone_rampart';
            $spiked = $t->spec === 'spiked_palisade';
            $standing = $road['hp'] > 0;
            $this->world->draw('wall:' . (int)$stone . (int)$standing . (int)$spiked, static fn () => Props::wall($stone, $standing, $spiked),
                $road['x'], 0.3, $road['y'], $yaw);
            return;
        }
        $armed = $road['timer'] <= 0;
        $this->world->draw('trap:' . (int)$armed, static fn () => Props::trap($armed), $road['x'], 0.3, $road['y'], $yaw);
    }

    private function projectile3d(\App\Td\Projectile $p): void
    {
        $kind = $p->kind();
        $total = hypot($p->targetX - $p->fromX, $p->targetY - $p->fromY);
        $frac = $total > 0 ? max(0.0, min(1.0, 1 - hypot($p->targetX - $p->x, $p->targetY - $p->y) / $total)) : 1.0;
        $arc = in_array($kind, ['rock', 'pitch'], true) ? 16.0 : 3.0;
        $height = 27.0 + (9.0 - 27.0) * $frac + sin(M_PI * $frac) * $arc;
        $yaw = $kind === 'star' ? $this->time * 9.0 : -atan2($p->targetY - $p->y, $p->targetX - $p->x);
        $glow = in_array($kind, ['orb', 'note', 'star', 'spark', 'pitch'], true) ? 1.0 : 0.0;
        $this->world->draw("p:$kind", static fn () => Props::projectile($kind), $p->x, $height, $p->y, $yaw, 1.3, [1.0, 1.0, 1.0], 1.0, $glow);
    }

    /** Blasts, glows and the markers of what the player is choosing: translucent, so drawn last. */
    private function effects3d(): void
    {
        $w = $this->world;
        foreach ($this->flashes as $f) {
            $k = max(0.0, 1 - $f['t'] / 0.25);
            $rgb = match ($f['kind']) {
                'star' => [1.0, 0.94, 0.55],
                'rock' => [0.67, 0.55, 0.4],
                'pitch', 'spark' => [1.0, 0.55, 0.16],
                'heal' => [0.47, 1.0, 0.55],
                'pulse' => [1.0, 1.0, 1.0],
                'power' => [1.0, 0.94, 0.78],
                default => [0.78, 0.55, 1.0],
            };
            if ($f['splash'] > 0) {
                $radius = $f['splash'] * (0.5 + 0.5 * (1 - $k));
                $w->disc($f['x'], $f['y'], $radius, $rgb, 0.12 + 0.32 * $k, 0.9, 1.0);
                $w->ring($f['x'], $f['y'], $radius, $rgb, 0.4 + 0.6 * $k, 1.0);
            } elseif ($f['kind'] === 'root') {
                foreach ([[-3.0, 1.0], [3.0, -1.5], [0.0, 3.0]] as [$dx, $dz]) {
                    $w->ball($f['x'] + $dx, 2.0 + (1 - $k) * 4.0, $f['y'] + $dz, 2.2, [0.36, 0.5, 0.2], max(0.3, $k), 0.0);
                }
            } elseif ($k > 0.05) {
                $w->ball($f['x'], 8.0, $f['y'], 1.2 + (1 - $k) * 2.4, [1.0, 1.0, 0.86], $k);
            }
        }

        // the platform in focus, its range
        $slot = $this->menuSlot ?? ($this->keyboard && $this->aim === null ? $this->cursor : null);
        if ($slot !== null && isset($this->battle->slots[$slot])) {
            $s = $this->battle->slots[$slot];
            $pulse = $this->menuSlot === null ? 0.65 + 0.35 * sin($this->time * 8) : 1.0;
            $w->ring($s['x'], $s['y'], 19.0, [1.0, 0.88, 0.35], $pulse * 0.9, MapModel::PLATFORM + 0.3);
            $range = $this->menuSlot !== null ? $this->rangeFor($slot) : null;
            if ($range !== null) {
                $w->disc($s['x'], $s['y'], $range, [1.0, 1.0, 1.0], 0.13, MapModel::PLATFORM + 0.2);
                $w->ring($s['x'], $s['y'], $range, [1.0, 1.0, 1.0], 0.7, MapModel::PLATFORM + 0.3);
            }
        }
        // where the hero would go, where the power would fall
        if ($this->aim === 'power') {
            $radius = (float)$this->battle->power()['radius'];
            $w->disc($this->reticleX, $this->reticleY, $radius, [1.0, 0.9, 0.5], 0.22, 1.2, 1.0);
            $w->ring($this->reticleX, $this->reticleY, $radius, [1.0, 0.95, 0.7], 0.9, 1.3);
        } elseif ($this->aim === 'hero') {
            $point = $this->battle->nearestRoad($this->reticleX, $this->reticleY);
            $w->disc($point['x'], $point['y'], 9.0, [0.5, 0.85, 1.0], 0.35, 1.2, 1.0);
            $w->ring($point['x'], $point['y'], 9.0, [0.8, 0.95, 1.0], 0.9, 1.3);
        }
    }

    /** Health bars of what is hurt, over the 3D (2D: crisp at any size). */
    private function bars3d(Renderer $r): void
    {
        $bar = function (float $x, float $y, float $height, float $ratio, float $width, array $fill) use ($r): void {
            [$sx, $sy] = $this->cam->screen($x, $y, $height);
            $r->fillRect($sx - $width / 2 - 1, $sy - 1, $width + 2, 4, [20, 10, 10]);
            $r->fillRect($sx - $width / 2, $sy, $width * max(0.0, min(1.0, $ratio)), 2, $fill);
        };
        foreach ($this->battle->enemies as $e) {
            if ($e->hp < $e->maxHp && !$e->isUnderground() && ($e->isTargetable() || $e->isStealthy())) {
                $ratio = $e->hp / $e->maxHp;
                $height = Units::height((string)$e->def['sprite']) * (float)($e->def['scale'] ?? 1.0) * self::UNIT_SCALE + ($e->isFlying() ? 18.0 : 5.0);
                $bar($e->x, $e->y, $height, $ratio, !empty($e->def['boss']) ? 24.0 : 13.0,
                    $ratio > 0.5 ? [90, 220, 80] : ($ratio > 0.25 ? [240, 200, 60] : [230, 70, 50]));
            }
        }
        foreach ($this->battle->towers() as $t) {
            foreach ($t->soldiers as $s) {
                if ($s->isAlive() && $s->hp < $s->maxHp) {
                    $bar($s->x, $s->y, 24.0, $s->hp / $s->maxHp, 12.0, [110, 190, 255]);
                }
            }
            if ($t->kind() === 'barrier' && $t->road !== null && $t->road['hp'] > 0 && $t->road['hp'] < $t->road['maxHp']) {
                $bar($t->road['x'], $t->road['y'], 18.0, $t->road['hp'] / max(1.0, $t->road['maxHp']), 18.0, [240, 200, 60]);
            }
        }
        $h = $this->battle->hero;
        if ($h?->isAlive()) {
            $bar($h->x, $h->y, Units::height((string)$h->def['sprite']) * (float)($h->def['scale'] ?? 1.0) * self::UNIT_SCALE + 6.0,
                $h->hp / $h->maxHp, 18.0, [110, 190, 255]);
        }
    }

    private function drawTower(Renderer $r, Tower $tower, int $sy): void
    {
        $def = $this->data->tower($tower->type);
        // a small jump when it shoots
        $kick = $tower->sinceShot < 0.08 ? 1 : 0;
        $specIndex = $tower->spec !== null ? (int)array_search($tower->spec, $tower->specNames(), true) : null;
        $this->sprites->drawTower($r, (string)$def['sprite'], $tower->level, $tower->spec, $tower->x, $tower->y + $sy + 8 - $kick, 1.0, $specIndex);
        // one pip per level above the first
        for ($i = 0; $i < min(3, $tower->level - 1); $i++) {
            $r->fillRect($tower->x - 5 + $i * 4, $tower->y + $sy + 10, 3, 2, [255, 210, 80]);
        }
    }

    /** A wall across the road (broken: stumps), or a trap (open jaws when armed). */
    private function drawRoadObject(Renderer $r, Tower $tower, int $sy): void
    {
        $road = (array)$tower->road;
        $x = $road['x'];
        $y = $road['y'] + $sy;
        [, , $dx, $dy] = $this->battle->paths[$road['path']]->at($road['distance']);
        // across the road: perpendicular to its direction
        $px = -$dy;
        $py = $dx;
        if ($tower->kind() === 'barrier') {
            $standing = $road['hp'] > 0;
            $stone = $tower->spec === 'stone_rampart';
            for ($i = -2; $i <= 2; $i++) {
                $sx = $x + $px * $i * 5;
                $sy2 = $y + $py * $i * 5;
                $h = $standing ? 12 : 3;
                $r->fillRect($sx - 2, $sy2 - $h + 3, 5, $h, [26, 20, 36]);
                $r->fillRect($sx - 1, $sy2 - $h + 4, 3, $h - 1, $stone ? [150, 150, 140] : [154, 112, 64]);
            }
            if ($standing && $road['hp'] < $road['maxHp']) {
                $ratio = $road['hp'] / max(1.0, $road['maxHp']);
                $r->fillRect($x - 9, $y - 14, 18, 3, [20, 10, 10]);
                $r->fillRect($x - 8, $y - 13, 16 * $ratio, 1, [240, 200, 60]);
            }
            return;
        }
        // a trap
        $armed = $road['timer'] <= 0;
        $r->fillRect($x - 6, $y - 2, 12, 5, [26, 20, 36]);
        $r->fillRect($x - 5, $y - 1, 10, 3, $armed ? [170, 170, 180] : [90, 80, 70]);
        if ($armed) {
            for ($i = -4; $i <= 4; $i += 2) {
                $r->fillRect($x + $i, $y - 3, 1, 2, [220, 220, 230]);
            }
        }
    }

    private function drawHero(Renderer $r, Hero $hero, int $sy): void
    {
        $def = $hero->def;
        $scale = (float)($def['scale'] ?? 1.0);
        $x = $hero->x;
        $y = $hero->y + $sy;
        [$w, $h] = $this->sprites->enemySize((string)$def['sprite']);
        $h *= $scale;
        // a ring of the side's colour under its feet: the one the player commands
        $r->fillRect($x - 8, $y + $h / 2 - 2, 16, 3, $this->colors['main'] ?? [200, 200, 200]);
        $this->sprites->drawEnemy($r, (string)$def['sprite'], (int)($hero->age * 6), $x, $y - 2, $hero->facingLeft, $scale);
        if ($hero->sinceSkill < 0.3) {
            $r->fillCircle($x, $y, (float)$def['skill']['radius'] * (0.4 + $hero->sinceSkill * 2), [255, 255, 200, 60]);
        }
        $ratio = max(0.0, $hero->hp / $hero->maxHp);
        $r->fillRect($x - 9, $y - $h / 2 - 7, 18, 4, [10, 10, 20]);
        $r->fillRect($x - 8, $y - $h / 2 - 6, 16 * $ratio, 2, [110, 190, 255]);
    }

    /** The round hero and power buttons, with what is left of their wait. */
    private function drawMapButtons(Renderer $r): void
    {
        foreach ($this->mapButtons() as $b) {
            $x = $b['x'];
            $y = $b['y'];
            $active = $this->aim === $b['id'];
            $r->fillCircle($x, $y, $b['r'], $active ? [255, 220, 90] : [20, 16, 24]);
            $r->fillCircle($x, $y, $b['r'] - 2, [70, 60, 50]);
            $hero = $this->battle->hero;
            if ($b['id'] === 'hero' && $hero !== null) {
                $def = $hero->def;
                $this->sprites->drawEnemy($r, (string)$def['sprite'], 0, $x, $y, false);
                $wait = $hero->isAlive() ? $hero->skillTimer / max(1.0, (float)$def['skill']['cooldown']) : 1.0;
                if (!$hero->isAlive()) {
                    $this->font()->draw($r, (string)(int)ceil($hero->respawn), $x, $y - $this->font()->height() / 2, 1, [255, 120, 100], 'center', [0, 0, 0]);
                }
            } else {
                [$iw, $ih] = $this->sprites->iconSize('star');
                $this->sprites->drawIcon($r, 'star', $x - $iw, $y - $ih, 2);
                $wait = $this->battle->powerTimer / max(1.0, (float)$this->battle->power()['cooldown']);
            }
            if ($wait > 0) {
                // the part still to wait, darkened from the top
                $size = 2 * ($b['r'] - 2);
                $r->fillRect($x - $size / 2, $y - $size / 2, $size, $size * min(1.0, $wait), [0, 0, 0, 140]);
            }
        }
    }

    /** Where the hero goes / the power falls: a circle at the pointer, and what it is. */
    private function drawAim(Renderer $r, int $sy): void
    {
        [$x, $y] = $this->cam->screen($this->reticleX, $this->reticleY);
        if ($this->aim === 'hero') {
            $point = $this->battle->nearestRoad($this->reticleX, $this->reticleY);
            if (!$this->use3d) {
                [$rx, $ry] = $this->cam->screen($point['x'], $point['y']);
                $this->drawRange($r, $rx, $ry, 10);
                $r->drawLine($x, $y, $rx, $ry, [255, 255, 255, 120]);
            }
            $def = (array)$this->battle->hero?->def;
            $lines = [[Lang::t((string)($def['name'] ?? '')), [255, 220, 90]], [Lang::t('CHOOSE WHERE'), [240, 240, 230]],
                [Lang::t((string)($def['skill']['name'] ?? '')) . ': ' . Lang::t((string)($def['skill']['desc'] ?? '')), [180, 200, 220]]];
        } else {
            $power = $this->battle->power();
            if (!$this->use3d) {
                $this->drawRange($r, $x, $y, (float)$power['radius']);
            }
            $lines = [[Lang::t($power['name']), [255, 220, 90]], [Lang::t($power['desc']), [240, 240, 230]], [Lang::t('CHOOSE WHERE'), [180, 200, 220]]];
        }
        $font = $this->font();
        $panelW = 196;
        $height = 8;
        foreach ($lines as [$text]) {
            $height += Text::height($font, $text, $panelW - 12) + 2;
        }
        $px = $x < $this->width() / 2 ? $this->width() - $panelW - 4 : 4;
        $r->fillRect($px, self::MAP_Y + 4, $panelW, $height, [14, 12, 20]);
        $r->drawRect($px, self::MAP_Y + 4, $panelW, $height, $this->colors['main'] ?? [80, 160, 90]);
        $ty = self::MAP_Y + 9;
        foreach ($lines as [$text, $color]) {
            $ty += Text::draw($r, $font, $text, $px + 6, $ty, $panelW - 12, $color) + 2;
        }
    }

    private function drawSoldier(Renderer $r, \App\Td\Soldier $soldier, string $sprite, int $sy): void
    {
        $x = $soldier->x;
        $y = $soldier->y + $sy;
        [$w, $h] = $this->sprites->enemySize($sprite);
        $r->fillRect($x - $w / 2 + 2, $y + $h / 2 - 2, $w - 4, 2, [0, 0, 0, 60]);
        $this->sprites->drawEnemy($r, $sprite, (int)($soldier->age * 6), $x, $y - 2, $soldier->facingLeft);
        // a pennant of the side over its head
        $r->fillRect($x - 1, $y - $h / 2 - 5, 3, 2, $this->colors['main'] ?? [200, 200, 200]);
        if ($soldier->hp < $soldier->maxHp) {
            $ratio = max(0.0, $soldier->hp / $soldier->maxHp);
            $r->fillRect($x - 7, $y - $h / 2 - 8, 14, 3, [10, 10, 20]);
            $r->fillRect($x - 6, $y - $h / 2 - 7, 12 * $ratio, 1, [110, 190, 255]);
        }
    }

    private function drawEnemy(Renderer $r, Enemy $enemy, int $sy): void
    {
        $def = $enemy->def;
        $frame = $enemy->isStunned() ? 0 : (int)($enemy->age * 6);
        $x = $enemy->x;
        $y = $enemy->y + $sy;
        if ($enemy->isUnderground()) {
            // a mound of earth moving under the road
            $r->fillRect($x - 5, $y + 1, 10, 3, [110, 80, 50]);
            $r->fillRect($x - 3, $y - 1, 6, 2, [140, 104, 66]);
            return;
        }
        $scale = (float)($def['scale'] ?? 1.0);
        [$w, $h] = $this->sprites->enemySize((string)$def['sprite']);
        $w *= $scale;
        $h *= $scale;
        if ($enemy->isStealthy() && !$enemy->revealed && (int)($this->time * 8) % 4 !== 0) {
            // hidden: only a shimmer, now and then
            $r->fillRect($x - 3, $y - 3, 6, 6, [255, 255, 255, 30]);
            return;
        }
        if ($enemy->isFlying()) {
            $r->fillRect($x - 5, $y + 6, 10, 2, [0, 0, 0, 70]);
            $y -= 10 + sin($enemy->age * 5) * 2;
        } else {
            $r->fillRect($x - $w / 2 + 2, $y + $h / 2 - 2, $w - 4, 2, [0, 0, 0, 60]);
        }
        $this->sprites->drawEnemy($r, (string)$def['sprite'], $frame, $x, $y - 2, $enemy->dirX < -0.1, $scale);
        if ($enemy->isStunned()) {
            $r->fillRect($x - 6, $y + $h / 2 - 3, 2, 4, [110, 70, 30]);
            $r->fillRect($x + 4, $y + $h / 2 - 4, 2, 5, [110, 70, 30]);
            $r->fillRect($x - 1, $y + $h / 2 - 5, 2, 4, [90, 160, 60]);
        }
        if ($enemy->isSlowed()) {
            $r->fillRect($x - $w / 2, $y - $h / 2, $w, $h, [120, 170, 255, 60]);
        }
        if ($enemy->isBurning() && (int)($this->time * 10) % 2 === 0) {
            $r->fillRect($x - 2, $y - $h / 2 - 2, 2, 2, [255, 160, 40]);
            $r->fillRect($x + 2, $y - $h / 2, 2, 2, [255, 90, 30]);
        }
        if ($enemy->hp < $enemy->maxHp) {
            $bw = !empty($def['boss']) ? 22 : 12;
            $ratio = max(0.0, $enemy->hp / $enemy->maxHp);
            $by = $y - $h / 2 - 5;
            $r->fillRect($x - $bw / 2 - 1, $by - 1, $bw + 2, 4, [20, 10, 10]);
            $r->fillRect($x - $bw / 2, $by, $bw * $ratio, 2, $ratio > 0.5 ? [90, 220, 80] : ($ratio > 0.25 ? [240, 200, 60] : [230, 70, 50]));
        }
    }

    private function drawProjectiles(Renderer $r, int $sy): void
    {
        foreach ($this->battle->projectiles as $p) {
            $x = $p->x;
            $y = $p->y + $sy;
            switch ($p->kind()) {
                case 'arrow':
                case 'bolt':
                case 'javelin':
                    $dx = $p->targetX - $p->fromX;
                    $dy = $p->targetY - $p->fromY;
                    $len = max(1.0, hypot($dx, $dy));
                    $long = $p->kind() === 'arrow' ? 5 : 8;
                    $color = $p->crit ? [255, 120, 80] : ($p->kind() === 'bolt' ? [160, 170, 190] : [230, 210, 160]);
                    $r->drawLine($x - $dx / $len * $long, $y - $dy / $len * $long, $x, $y, $color);
                    break;
                case 'stone':
                    $r->fillRect($x - 1, $y - 1, 2, 2, [190, 190, 180]);
                    break;
                case 'rock':
                    $r->fillCircle($x, $y, 3, [60, 56, 50]);
                    $r->fillCircle($x - 0.5, $y - 0.5, 2, [150, 146, 136]);
                    break;
                case 'pitch':
                    $r->fillCircle($x, $y, 2.5, [30, 24, 30]);
                    $r->fillRect($x - 1, $y - 3, 2, 2, [255, 140, 40]);
                    break;
                case 'spark':
                    $r->fillRect($x - 1, $y - 1, 3, 3, [255, 160, 40]);
                    $r->fillRect($x, $y, 1, 1, [255, 240, 160]);
                    break;
                case 'orb':
                    $r->fillCircle($x, $y, 3, [140, 180, 255, 160]);
                    $r->fillCircle($x, $y, 1.5, [230, 240, 255]);
                    break;
                case 'note':
                    $r->fillRect($x - 1, $y, 3, 2, [255, 220, 90]);
                    $r->fillRect($x + 1, $y - 4, 1, 4, [255, 220, 90]);
                    break;
                case 'star':
                    $r->fillRect($x - 2, $y, 5, 1, [255, 240, 140]);
                    $r->fillRect($x, $y - 2, 1, 5, [255, 240, 140]);
                    break;
                default:
                    $r->fillRect($x - 1, $y - 1, 3, 3, [255, 255, 255]);
            }
        }
        foreach ($this->flashes as $flash) {
            $x = $flash['x'];
            $y = $flash['y'] + $sy;
            $k = 1 - $flash['t'] / 0.25;
            if ($flash['kind'] === 'root') {
                $r->fillRect($x - 7, $y + 1, 3, 5, [120, 80, 40]);
                $r->fillRect($x + 5, $y, 3, 6, [120, 80, 40]);
                $r->fillRect($x - 1, $y + 3, 3, 4, [80, 150, 60]);
            } elseif ($flash['splash'] > 0) {
                $color = match ($flash['kind']) {
                    'star' => [255, 240, 140],
                    'rock' => [170, 140, 100],
                    'pitch', 'spark' => [255, 140, 40],
                    'heal' => [120, 255, 140],
                    'power' => [255, 240, 200],
                    'pulse' => [255, 255, 255],
                    default => [200, 140, 255],
                };
                $color[] = (int)(($flash['kind'] === 'pulse' || $flash['kind'] === 'heal' ? 50 : 110) * $k);
                $r->fillCircle($x, $y, $flash['splash'] * (0.5 + 0.5 * (1 - $k)), $color);
            } else {
                $r->fillRect($x - 1, $y - 1, 3, 3, [255, 255, 220, (int)(255 * $k)]);
            }
        }
    }

    /** The range to show for a platform: of its tower, or of the tower about to be built / upgraded. */
    private function rangeFor(int $slot): ?float
    {
        $tower = $this->battle->tower($slot);
        if ($this->choice !== null && str_starts_with($this->choice, 'build:')) {
            return (float)$this->data->towerStats(substr($this->choice, 6), 1)['range'];
        }
        if ($tower === null) {
            return null;
        }
        if ($this->choice === 'upgrade') {
            return (float)$this->data->towerStats($tower->type, $tower->level + 1)['range'];
        }
        if ($this->choice !== null && str_starts_with($this->choice, 'spec:')) {
            return (float)$this->data->towerStats($tower->type, Tower::MAX_LEVEL, substr($this->choice, 5))['range'];
        }
        return $tower->range();
    }

    private function drawRing(Renderer $r, int $slot): void
    {
        // in 3D the range and the frame of the platform are drawn on the ground (indicators3d)
        if (!$this->use3d) {
            [$sx, $sy] = $this->cam->screen($this->battle->slots[$slot]['x'], $this->battle->slots[$slot]['y']);
            $range = $this->rangeFor($slot);
            if ($range !== null) {
                $this->drawRange($r, $sx, $sy, $range);
            }
            $r->drawRect($sx - 16, $sy - 14, 32, 30, [255, 255, 255, 120]);
        }
        foreach ($this->ringOptions($slot) as $option) {
            $x = $option['x'];
            $y = $option['y'];
            $chosen = $option['id'] === $this->choice;
            $r->fillCircle($x, $y, 12, $chosen ? [255, 220, 90] : [20, 16, 24]);
            $r->fillCircle($x, $y, 10, $option['enabled'] ? [70, 60, 50] : [50, 44, 44]);
            if ($option['tower'] !== null) {
                $def = $this->data->tower($option['tower']);
                $this->sprites->drawTower($r, (string)$def['sprite'], 1, null, $x, $y + 9, 0.5);
            } else {
                [$iw, $ih] = $this->sprites->iconSize($option['icon']);
                $this->sprites->drawIcon($r, $option['icon'], $x - $iw, $y - $ih, 2);
            }
            if ($option['cost'] !== null) {
                $text = ($option['id'] === 'sell' ? '+' : '') . $option['cost'];
                $color = $option['id'] === 'sell' ? [160, 240, 140] : ($option['enabled'] ? [255, 220, 90] : [220, 80, 70]);
                $ty = $y + 9;
                $tw = $this->font()->width($text);
                $r->fillRect($x - $tw / 2 - 2, $ty - 1, $tw + 4, $this->font()->lineHeight() + 1, [10, 8, 12]);
                $this->font()->draw($r, $text, $x, $ty, 1, $color, 'center');
            }
        }
        $this->drawInfo($r, $slot);
    }

    /** A dotted circle. */
    private function drawRange(Renderer $r, float $cx, float $cy, float $radius): void
    {
        $r->fillCircle($cx, $cy, $radius, [255, 255, 255, 22]);
        $n = (int)max(24, $radius * 0.8);
        for ($i = 0; $i < $n; $i++) {
            $a = $i / $n * 2 * M_PI + $this->time * 0.4;
            $r->fillRect($cx + cos($a) * $radius, $cy + sin($a) * $radius, 1, 1, [255, 255, 255, 200]);
        }
    }

    /** The box that says what the chosen option (or the tower) does. */
    private function drawInfo(Renderer $r, int $slot): void
    {
        $lines = $this->infoLines($slot);
        if ($lines === []) {
            return;
        }
        $font = $this->font();
        $panelW = 196;
        $inner = $panelW - 12;
        $height = 8;
        foreach ($lines as [$text]) {
            $height += Text::height($font, $text, $inner) + 2;
        }
        // on the other side of the map from the slot, so that it never hides the ring
        [$sx, $sy] = $this->cam->screen($this->battle->slots[$slot]['x'], $this->battle->slots[$slot]['y']);
        $x = $sx < $this->width() / 2 ? $this->width() - $panelW - 4 : 4;
        $y = $sy < $this->height() / 2 ? $this->height() - $height - 4 : self::MAP_Y + 4;
        $r->fillRect($x, $y, $panelW, $height, [14, 12, 20]);
        $r->drawRect($x, $y, $panelW, $height, $this->colors['main'] ?? [80, 160, 90]);
        $ty = $y + 5;
        foreach ($lines as [$text, $color]) {
            $ty += Text::draw($r, $font, $text, $x + 6, $ty, $inner, $color) + 2;
        }
    }

    /** @return list<array{0: string, 1: array}> */
    private function infoLines(int $slot): array
    {
        $gold = [255, 220, 90];
        $white = [240, 240, 230];
        $grey = [180, 190, 200];
        $tower = $this->battle->tower($slot);
        $choice = $this->choice;
        if ($choice === null) {
            if ($tower === null) {
                return [[Lang::t('CHOOSE A TOWER'), $white]];
            }
            $def = $this->data->tower($tower->type);
            $name = Lang::t($def['name']) . ($tower->spec !== null ? ' - ' . Lang::t($def['specs'][$tower->spec]['name']) : '');
            $lines = [[$name, $gold], [Lang::t('LEVEL {n}', ['n' => $tower->level]), $white], ...$this->statLines($tower->stats)];
            if ($tower->hasTargeting()) {
                $lines[] = [Lang::t('TARGET') . ': ' . self::targetingLabel($tower->targeting), $grey];
            }
            return $lines;
        }
        [$what, $arg] = array_pad(explode(':', $choice, 2), 2, '');
        $again = [Lang::t('PRESS AGAIN TO CONFIRM'), [150, 150, 160]];
        switch ($what) {
            case 'build':
                $def = $this->data->tower($arg);
                return [[Lang::t($def['name']), $gold], [Lang::t($def['desc']), $white], ...$this->statLines($this->data->towerStats($arg, 1)), $again];
            case 'upgrade':
                $next = $this->data->towerStats($tower->type, $tower->level + 1);
                return [[Lang::t('UPGRADE') . ' - ' . Lang::t('LEVEL {n}', ['n' => $tower->level + 1]), $gold], ...$this->statLines($next), $again];
            case 'spec':
                $spec = $this->data->tower($tower->type)['specs'][$arg];
                return [[Lang::t($spec['name']), $gold], [Lang::t($spec['desc']), $white],
                    ...$this->statLines($this->data->towerStats($tower->type, Tower::MAX_LEVEL, $arg)), $again];
            case 'target':
                $modes = array_map(static fn (string $m) => self::targetingLabel($m), Tower::TARGETING);
                return [[Lang::t('TARGET') . ': ' . self::targetingLabel($tower->targeting), $gold], [implode(' / ', $modes), $grey], $again];
            case 'sell':
                return [[Lang::t('SELL') . ' +' . $tower->sellValue(), $gold], $again];
        }
        return [];
    }

    private static function targetingLabel(string $mode): string
    {
        return match ($mode) {
            'last' => Lang::t('LAST'),
            'strongest' => Lang::t('STRONGEST'),
            'closest' => Lang::t('CLOSEST'),
            default => Lang::t('FIRST'),
        };
    }

    /** @return list<array{0: string, 1: array}> what the tower does, in numbers: soldiers, wall, aura, gold, attack */
    private function statLines(array $stats): array
    {
        $blue = [200, 230, 255];
        $green = [170, 200, 170];
        $kind = (string)$stats['kind'];
        $lines = [];
        if ($kind === 'barracks') {
            $s = $stats['soldiers'];
            $lines[] = [Lang::t('SOLDIERS {n}', ['n' => (int)$s['count']]) . '  ' . Lang::t('HEALTH {n}', ['n' => (int)$s['hp']]), $blue];
            $lines[] = [Lang::t('DAMAGE {n}', ['n' => (int)$s['damage']]), $green];
        }
        if ($kind === 'barrier') {
            $lines[] = [Lang::t('HEALTH {n}', ['n' => (int)$stats['barrier']['hp']]), $blue];
        }
        if (isset($stats['aura'])) {
            $damage = (int)round(((float)$stats['aura']['damage'] - 1) * 100);
            $speed = (int)round((1 / max(0.1, (float)$stats['aura']['rate']) - 1) * 100);
            if ($damage > 0) {
                $lines[] = [Lang::t('NEARBY TOWERS: DAMAGE +{n}%', ['n' => $damage]), $blue];
            }
            if ($speed > 0) {
                $lines[] = [Lang::t('NEARBY TOWERS: SPEED +{n}%', ['n' => $speed]), $blue];
            }
        }
        if (isset($stats['income'])) {
            $lines[] = [Lang::t('+{n} GOLD EVERY {s} S', ['n' => (int)$stats['income']['gold'], 's' => (int)$stats['income']['every']]), [255, 220, 90]];
        }
        if (!empty($stats['detect'])) {
            $lines[] = [Lang::t('REVEALS HIDDEN ENEMIES'), $green];
        }
        if ((float)$stats['damage'] <= 0 || in_array($kind, ['barracks', 'barrier'], true)) {
            return $lines;
        }
        $type = match ((string)$stats['damage_type']) {
            'physical' => Lang::t('PHYSICAL'),
            'pierce' => Lang::t('PIERCING'),
            'magic' => Lang::t('MAGIC'),
            'fire' => Lang::t('FIRE'),
            default => Lang::t('SIEGE'),
        };
        $targets = match ((string)$stats['targets']) {
            'ground' => Lang::t('GROUND ONLY'),
            'air' => Lang::t('AIR ONLY'),
            default => Lang::t('GROUND AND AIR'),
        };
        // per second for towers that shoot, per snap for traps
        $damage = $kind === 'trap' ? (float)$stats['damage'] : (float)$stats['damage'] / max(0.1, (float)$stats['rate']);
        $lines[] = [Lang::t('DAMAGE {n}', ['n' => round($damage)]) . '  ' . Lang::t('RANGE {n}', ['n' => (int)$stats['range']]), $blue];
        $lines[] = [$type . ' - ' . $targets, $green];
        return $lines;
    }

    private function drawHud(Renderer $r): void
    {
        $w = $this->width();
        $font = $this->font();
        $r->fillRect(0, 0, $w, self::MAP_Y, [30, 24, 20]);
        $r->fillRect(0, self::MAP_Y - 2, $w, 2, $this->colors['dark'] ?? [30, 80, 50]);
        $ty = 15 - $font->height() / 2;
        $x = 6;
        $this->sprites->drawIcon($r, 'heart', $x, 8, 2);
        $x += 17;
        $font->draw($r, (string)$this->battle->lives, $x, $ty, 1, [255, 170, 180], 'left', [0, 0, 0]);
        $x += max(18, $font->width((string)$this->battle->lives)) + 8;
        $this->sprites->drawIcon($r, 'coin', $x, 9, 2);
        $x += 15;
        $font->draw($r, (string)$this->battle->gold, $x, $ty, 1, [255, 220, 90], 'left', [0, 0, 0]);
        $x += max(26, $font->width((string)$this->battle->gold)) + 8;
        $this->sprites->drawIcon($r, 'flag', $x, 8, 2);
        $x += 17;
        $wave = Lang::t('WAVE {n}', ['n' => $this->battle->waveIndex . '/' . $this->battle->waveCount()]);
        $font->draw($r, $wave, $x, $ty, 1, [230, 230, 230], 'left', [0, 0, 0]);
        $left = $x + $font->width($wave) + 8;
        if ($this->battle->hard) {
            $this->sprites->drawIcon($r, 'skull', $left, 9, 2);
            $left += 16;
        }

        $mouse = $this->engine->input->getMouse();
        foreach ($this->hudButtons() as $b) {
            $enabled = $b['id'] !== 'call' || $this->battle->canCallWave();
            $hover = $mouse->x >= $b['x'] && $mouse->x < $b['x'] + $b['w'] && $mouse->y >= $b['y'] && $mouse->y < $b['y'] + $b['h'];
            $r->fillRect($b['x'], $b['y'], $b['w'], $b['h'], [12, 10, 10]);
            $r->fillRect($b['x'] + 1, $b['y'] + 1, $b['w'] - 2, $b['h'] - 2, !$enabled ? [50, 46, 44] : ($hover ? [110, 90, 60] : [80, 64, 44]));
            $cy = $b['y'] + $b['h'] / 2;
            if ($b['id'] === 'pause') {
                $this->sprites->drawIcon($r, 'pause', $b['x'] + 8, $cy - 5, 2);
            } elseif ($b['id'] === 'speed') {
                $font->draw($r, 'x' . $this->speed, $b['x'] + $b['w'] / 2, $cy - $font->height() / 2, 1, $this->speed > 1 ? [255, 220, 90] : [230, 230, 230], 'center');
            } else {
                $this->sprites->drawIcon($r, 'play', $b['x'] + 6, $cy - 5, 2);
                $font->draw($r, $this->callLabel(), $b['x'] + 20, $cy - $font->height() / 2, 1, $enabled ? [255, 240, 200] : [140, 130, 120]);
                $left = $this->drawPreview($r, $left, $b['x'] - 4);
                if ($enabled && $this->battle->countdown !== null) {
                    // the time left, as a bar under the button
                    $total = $this->battle->waveIndex === 0 ? (float)($this->params['countdown'] ?? $this->map['first_wave']) : 25.0;
                    $r->fillRect($b['x'] + 1, $b['y'] + $b['h'] - 3, ($b['w'] - 2) * min(1.0, $this->battle->countdown / max(1.0, $total)), 2, [255, 220, 90]);
                }
            }
        }
    }

    /** The next wave's enemies before the call button, when there is room. Returns the x used up to. */
    private function drawPreview(Renderer $r, float $left, float $right): float
    {
        $preview = $this->battle->nextWavePreview();
        if ($preview === []) {
            return $left;
        }
        $font = $this->font();
        $width = 0;
        foreach ($preview as $count) {
            $width += 14 + $font->width('x' . $count) + 4;
        }
        if ($right - $width < $left) {
            return $left;
        }
        $x = $right - $width;
        foreach ($preview as $type => $count) {
            $sprite = (string)$this->data->enemy($type)['sprite'];
            [$w, $h] = $this->sprites->enemySize($sprite);
            $scale = min(1.0, 14 / max($w, $h));
            $this->sprites->drawEnemy($r, $sprite, 0, $x + 7, 15, false, $scale);
            $font->draw($r, 'x' . $count, $x + 15, 15 - $font->height() / 2, 1, [220, 220, 220], 'left', [0, 0, 0]);
            $x += 14 + $font->width('x' . $count) + 4;
        }
        return $x;
    }

    private function drawBanner(Renderer $r): void
    {
        if ($this->banners === []) {
            return;
        }
        [$title, $line, $seconds] = $this->banners[0];
        $t = $this->bannerTime;
        // 4 steps of fading: each colour of text is a texture of its own
        $alpha = round(min(1.0, $t * 4, ($seconds - $t) * 4) * 4) / 4;
        if ($alpha <= 0) {
            return;
        }
        $font = $this->font();
        $w = $this->width();
        $scale = Text::fitScale($font, $title, $w - 40, max(1, round(16 / max(1, $font->height()))));
        $height = $font->lineHeight($scale) + ($line !== '' ? Text::height($font, $line, $w - 60) + 4 : 0) + 12;
        $y = self::MAP_Y + 56;
        $r->fillRect(0, $y, $w, $height, [0, 0, 0, (int)(190 * $alpha)]);   // black: it darkens the 3D under it
        // opaque text: over the 3D (keyed by a colour) a translucent colour would be tinted by it
        $a = 255;
        $font->draw($r, $title, $w / 2, $y + 6, $scale, [255, 225, 120, $a], 'center', [0, 0, 0, $a]);
        if ($line !== '') {
            Text::draw($r, $font, $line, $w / 2, $y + 8 + $font->lineHeight($scale), $w - 60, [220, 230, 240, $a], 'center', [0, 0, 0, $a]);
        }
    }
}
