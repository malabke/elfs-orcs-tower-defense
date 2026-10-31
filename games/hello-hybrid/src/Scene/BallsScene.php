<?php

namespace App\Scene;

use App\Settings;
use JumPHP\Audio\Synth;
use JumPHP\Native\Dialog;
use JumPHP\Native\FilePicker;
use JumPHP\Native\Native;
use JumPHP\Renderer\Renderer;
use JumPHP\Scene\AbstractScene;
use JumPHP\Tauri\App;

/**
 * Balls bouncing in the SDL window. The HTML panel (ui/, through App\Desktop) changes their number, speed, colour;
 * S saves a picture where the system's file chooser says; Escape asks before quitting (a native dialog). Without
 * the native side (bin/jumphp test / shot, the browser) it is a plain game: those features are skipped.
 */
final class BallsScene extends AbstractScene
{
    /** @var list<array{x: float, y: float, vx: float, vy: float, r: float}> */
    private array $balls = [];
    private float $time = 0.0;
    private float $statsIn = 0.0;
    private bool $demo = false;
    private bool $asking = false;
    private string $message = '';
    private float $messageFor = 0.0;
    private int $bounces = 0;

    public function enter(array $params = []): void
    {
        Settings::reset();
        $this->balls = [];
        $this->time = $this->statsIn = $this->messageFor = 0.0;
        $this->demo = !empty($params['demo']);
        $this->asking = false;
        $this->message = '';
        $this->bounces = 0;
        if (!$this->sound()->hasSfx('bounce')) {
            $this->sound()->synth('bounce', Synth::tone(520, 0.03, 'triangle'));
        }
    }

    public function update(float $dt): void
    {
        $this->time += $dt;
        $this->messageFor = max(0.0, $this->messageFor - $dt);
        if ($this->actions()->isPressed('back') && !$this->asking) {
            $this->askQuit();
        }
        if ($this->actions()->isPressed('pause')) {
            Settings::$paused = !Settings::$paused;
            App::current()?->send('settings', Settings::all());
        }
        if ($this->actions()->isPressed('add')) {
            Settings::$wanted = min(200, Settings::$wanted + 1);
            App::current()?->send('settings', Settings::all());
        }
        if ($this->actions()->isPressed('shot') || Settings::$shotRequested) {
            Settings::$shotRequested = false;
            $this->savePicture();
        }
        $this->sync();
        if (!Settings::$paused) {
            $this->move($dt * Settings::$speed);
        }
        // the panel shows the game's numbers, twice a second
        if (($this->statsIn -= $dt) <= 0) {
            $this->statsIn = 0.5;
            App::current()?->send('stats', ['balls' => count($this->balls), 'bounces' => $this->bounces, 'fps' => $this->engine->fps->fps]);
        }
    }

    public function render(Renderer $r): void
    {
        $r->clear([12, 14, 30]);
        foreach ($this->balls as $b) {
            $r->fillCircle($b['x'], $b['y'], $b['r'], Settings::$colour);
        }
        $this->font()->draw($r, count($this->balls) . ' BALLS' . (Settings::$paused ? '  PAUSED' : ''), 6, 6, 1, [170, 170, 210]);
        $this->font()->draw($r, 'NATIVE: ' . strtoupper(Native::name()), $this->width() - 6, 6, 1, [110, 110, 150], 'right');
        $help = $this->messageFor > 0 ? $this->message : 'SPACE: BALL   S: PICTURE   P: PAUSE   ESC: QUIT';
        $this->font()->draw($r, $help, $this->width() / 2, $this->height() - 12, 1, $this->messageFor > 0 ? [255, 220, 120] : [110, 110, 150], 'center');
    }

    /** @return int how many balls */
    public function count(): int
    {
        return count($this->balls);
    }

    private function sync(): void
    {
        while (count($this->balls) < Settings::$wanted) {
            $angle = mt_rand(0, 628) / 100;
            $speed = mt_rand(40, 110);
            $this->balls[] = ['x' => $this->width() / 2, 'y' => $this->height() / 2, 'vx' => cos($angle) * $speed,
                'vy' => sin($angle) * $speed, 'r' => mt_rand(3, 7)];
        }
        if (count($this->balls) > Settings::$wanted) {
            $this->balls = array_slice($this->balls, 0, Settings::$wanted);
        }
    }

    private function move(float $dt): void
    {
        $w = $this->width();
        $h = $this->height();
        foreach ($this->balls as &$b) {
            $b['x'] += $b['vx'] * $dt;
            $b['y'] += $b['vy'] * $dt;
            $hit = false;
            if ($b['x'] < $b['r'] || $b['x'] > $w - $b['r']) {
                $b['vx'] = -$b['vx'];
                $b['x'] = max($b['r'], min($w - $b['r'], $b['x']));
                $hit = true;
            }
            if ($b['y'] < $b['r'] || $b['y'] > $h - $b['r']) {
                $b['vy'] = -$b['vy'];
                $b['y'] = max($b['r'], min($h - $b['r'], $b['y']));
                $hit = true;
            }
            if ($hit) {
                $this->bounces++;
            }
        }
        unset($b);
        if ($this->demo && (int)$this->time !== (int)($this->time - $dt)) {
            Settings::$wanted = 12 + (int)$this->time % 20;
        }
    }

    /** the system's "save as" dialog, then SDL writes the picture there */
    private function savePicture(): void
    {
        if (!Native::supports('save')) {
            $this->say('NO FILE CHOOSER HERE');
            return;
        }
        FilePicker::save(['title' => 'Save a picture', 'file_name' => 'hello-hybrid.png', 'filters' => ['PNG' => ['png']]])
            ->then(function (mixed $path): void {
                if (!is_string($path)) {
                    $this->say('CANCELLED');
                    return;
                }
                $this->engine->sdl->saveScreenshot($path);
                $this->say('SAVED ' . strtoupper(basename($path)));
            }, fn (\Throwable $e) => $this->say('SAVE FAILED'));
    }

    private function askQuit(): void
    {
        if (!Native::supports('confirm')) {
            $this->engine->quit();
            return;
        }
        $this->asking = true;
        Settings::$paused = true;
        Dialog::confirm('Quit Hello Hybrid?', 'Hello Hybrid', 'Quit', 'Keep playing')
            ->then(function (mixed $yes): void {
                $this->asking = false;
                Settings::$paused = false;
                if ($yes) {
                    $this->engine->quit();
                }
            });
    }

    private function say(string $text): void
    {
        $this->message = $text;
        $this->messageFor = 2.5;
    }
}
