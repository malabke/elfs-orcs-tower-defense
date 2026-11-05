<?php

namespace App\Scene;

use JumPHP\Effects\Flash;
use JumPHP\Effects\Transition;
use JumPHP\Effects\Vignette;
use JumPHP\Renderer\Renderer;
use JumPHP\Scene\AbstractScene;

/**
 * The extension at work: Enter / Space / a click changes "scene" with the next transition (cover, the colours swap under it, reveal);
 * F flashes. The demo (the smoke test, the playground's title screen) does it by itself.
 */
final class DemoScene extends AbstractScene
{
    private const SCENES = [[[28, 60, 110], 'BLUE SIDE'], [[110, 40, 40], 'RED SIDE'], [[30, 90, 50], 'GREEN SIDE']];

    private float $time = 0;
    private bool $demo = false;
    private int $scene = 0;
    private int $type = 0;
    public int $flashes = 0;
    public int $changes = 0;

    public function __construct(private Flash $flash, private Transition $transition)
    {
    }

    public function enter(array $params = []): void
    {
        $this->demo = (bool)($params['demo'] ?? false);
        $this->time = 0;
        $this->scene = 0;
        $this->type = 0;
        $this->flashes = 0;
        $this->changes = 0;
        $this->transition->seed(7);
        $this->transition->reveal('fade', 0.01);
        $this->transition->update(1);
    }

    public function update(float $dt): void
    {
        $this->time += $dt;
        $go = $this->actions()->isPressed('confirm') || $this->engine->input->getMouse()->isPressed(1) || ($this->demo && fmod($this->time, 1.6) < $dt);
        if ($go && !$this->transition->isActive()) {
            $type = Transition::TYPES[$this->type++ % count(Transition::TYPES)];
            $this->transition->focus(0.5, 0.5);
            $this->transition->cover($type, 0.45, '#000000', function () use ($type): void {
                $this->scene = ($this->scene + 1) % count(self::SCENES);        // the change happens under the cover
                $this->changes++;
                $this->transition->reveal($type, 0.45, '#000000');
            });
        }
        if ($this->actions()->isPressed('flash') || ($this->demo && fmod($this->time, 1.6) > 1.4 && fmod($this->time - $dt, 1.6) <= 1.4)) {
            $this->flash->flash();
            $this->flashes++;
        }
        $this->transition->update($dt);
        $this->flash->update($dt);
    }

    public function render(Renderer $r): void
    {
        [$color, $name] = self::SCENES[$this->scene];
        $r->clear($color);
        $this->font()->draw($r, $name, $this->width() / 2, 70, 2, [255, 255, 255], 'center');
        $this->font()->draw($r, 'ENTER: NEXT TRANSITION   F: FLASH', $this->width() / 2, 104, 1, [200, 205, 235], 'center');
        Vignette::render($r, 0.45);
        $this->flash->render($r);
        $this->transition->render($r);
    }
}
