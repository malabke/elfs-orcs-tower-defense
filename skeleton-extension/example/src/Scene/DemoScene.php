<?php

namespace App\Scene;

use JumPHP\Renderer\Renderer;
use JumPHP\Scene\AbstractScene;
use JumphpExtensions\SkeletonExtension\Flash;

/** The extension at work: flash (Space, a click, a tap) lights the screen; the demo flashes by itself. */
final class DemoScene extends AbstractScene
{
    private float $time = 0;
    private bool $demo = false;
    public int $flashes = 0;

    public function __construct(private Flash $flash)
    {
    }

    public function enter(array $params = []): void
    {
        $this->demo = (bool)($params['demo'] ?? false);
        $this->time = 0;
        $this->flashes = 0;
    }

    public function update(float $dt): void
    {
        $this->time += $dt;
        if ($this->actions()->isPressed('flash') || ($this->demo && fmod($this->time, 1.0) < $dt)) {
            $this->flash->flash();
            $this->flashes++;
        }
        $this->flash->update($dt);
    }

    public function render(Renderer $r): void
    {
        $r->clear([12, 12, 28]);
        $this->font()->draw($r, strtoupper($this->engine->config->title()), $this->width() / 2, 70, 2, [255, 255, 255], 'center');
        $this->font()->draw($r, 'PRESS ' . strtoupper($this->actions()->label('flash')), $this->width() / 2, 100, 1, [160, 160, 200], 'center');
        $this->flash->render($r);
    }
}
