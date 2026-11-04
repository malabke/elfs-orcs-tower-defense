<?php

namespace App\Extensions\Particles;

use JumPHP\Particles\Emitter;
use JumPHP\Particles\ParticleSystem;
use JumPHP\Renderer\Renderer;
use JumPHP\Scene\AbstractScene;

/**
 * Sample of jumphp/particles: a fire that follows the cursor (the mouse, or the arrows), sparks where you click (Enter, Space, a tap).
 * Play it from the extension's documentation in the IDE, or: JUMPHP_SCENE='App\Extensions\Particles\FireSample' bin/jumphp run <game>
 */
final class FireSample extends AbstractScene
{
    public const LABEL = 'A fire that follows the cursor, sparks on click';

    private Emitter $fire;
    private Emitter $sparks;
    private float $x = 0;
    private float $y = 0;
    /** @var array{0: float, 1: float}|null where the mouse was last frame (it aims when it moves) */
    private ?array $mouse = null;

    public function __construct(private ParticleSystem $particles)
    {
    }

    public function enter(array $params = []): void
    {
        $this->particles->reset();
        $this->x = $this->width() / 2;
        $this->y = $this->height() * 0.7;
        $this->mouse = null;
        $this->fire = $this->particles->add(new Emitter([
            'rate' => 90, 'life' => [0.5, 1.1], 'speed' => [25, 70], 'angle' => -90, 'spread' => 22, 'gravity' => -45,
            'colors' => ['#fff6a0', '#ff9a1f', '#c0301000'], 'size' => [5, 1], 'shape' => 'circle', 'area' => [6, 2],
        ]));
        $this->sparks = $this->particles->add(new Emitter([
            'rate' => 0, 'life' => [0.3, 0.8], 'speed' => [40, 130], 'angle' => 0, 'spread' => 360, 'gravity' => 140, 'drag' => 1.2,
            'colors' => ['#ffffff', '#7fd4ff00'], 'size' => [3, 1], 'shape' => 'circle',
        ]));
    }

    public function update(float $dt): void
    {
        $mouse = $this->engine->input->getMouse();
        if ($this->mouse !== null && $this->mouse !== [$mouse->x, $mouse->y]) {
            [$this->x, $this->y] = [$mouse->x, $mouse->y];
        }
        $this->mouse = [$mouse->x, $mouse->y];
        $this->x = max(8, min($this->width() - 8, $this->x + $this->actions()->axis('left', 'right') * 110 * $dt));
        $this->y = max(8, min($this->height() - 8, $this->y + $this->actions()->axis('up', 'down') * 110 * $dt));
        $this->fire->moveTo($this->x, $this->y);
        if ($this->actions()->isPressed('confirm') || $mouse->isPressed(1)) {
            $this->particles->burst($this->sparks->moveTo($this->x, $this->y - 12), 50);
        }
        $this->particles->update($dt);
    }

    public function render(Renderer $r): void
    {
        $r->clear([12, 10, 20]);
        $this->particles->render($r);
        $this->font()->draw($r, 'MOVE THE MOUSE, CLICK FOR SPARKS', $this->width() / 2, 8, 1, [200, 205, 235], 'center');
    }
}
