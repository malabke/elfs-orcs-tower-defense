<?php

namespace App\Scene;

use JumPHP\Kernel\Platform;
use JumPHP\Particles\Emitter;
use JumPHP\Particles\ParticleSystem;
use JumPHP\Renderer\Renderer;
use JumPHP\Scene\AbstractScene;

/**
 * Five emitters of the jumphp/particles extension, read from assets/fx/show.particles.json: two torches, a fountain,
 * falling snow, smoke; boom (Space, a click, a tap) sets off a firework where the cursor is (arrows / mouse).
 * The demo (smoke test, title screen of the playground) fires by itself.
 */
final class ShowScene extends AbstractScene
{
    /** @var array<string, Emitter> */
    private array $fx = [];
    private float $cursorX = 160;
    private float $cursorY = 70;
    private float $time = 0;
    private float $nextAuto = 0;
    private bool $demo = false;
    private int $fireworks = 0;
    /** @var array{0: float, 1: float}|null where the mouse was last frame (it aims when it moves) */
    private ?array $mouse = null;

    public function __construct(private ParticleSystem $particles)
    {
    }

    public function enter(array $params = []): void
    {
        $this->demo = (bool)($params['demo'] ?? false);
        $this->particles->reset(isset($params['seed']) ? (int)$params['seed'] : null);
        $this->fx = $this->engine->extensions()->load('assets/fx/show.particles.json');
        $this->particles->add($this->fx['torch']->moveTo(40, 150));
        $this->particles->add((clone $this->fx['torch'])->moveTo(280, 150));
        $this->particles->add($this->fx['fountain']->moveTo(160, 172));
        $this->particles->add($this->fx['snow']->moveTo(160, -4));
        $this->particles->add($this->fx['smoke']->moveTo(40, 132));
        $this->cursorX = 160;
        $this->cursorY = 70;
        $this->time = 0;
        $this->nextAuto = 0.5;
        $this->fireworks = 0;
        $this->mouse = null;
    }

    public function update(float $dt): void
    {
        $this->time += $dt;
        if ($this->actions()->isPressed('quit') && Platform::isDesktop() && !$this->demo) {
            $this->engine->quit();
            return;
        }
        $mouse = $this->engine->input->getMouse();
        if ($this->mouse !== null && $this->mouse !== [$mouse->x, $mouse->y]) {
            [$this->cursorX, $this->cursorY] = [$mouse->x, $mouse->y];
        }
        $this->mouse = [$mouse->x, $mouse->y];
        $this->cursorX = max(8, min($this->width() - 8, $this->cursorX + $this->actions()->axis('left', 'right') * 120 * $dt));
        $this->cursorY = max(8, min($this->height() - 40, $this->cursorY + $this->actions()->axis('up', 'down') * 120 * $dt));
        if ($this->actions()->isPressed('boom')) {
            $this->firework($this->cursorX, $this->cursorY);
        }
        if ($this->demo && $this->time >= $this->nextAuto) {
            $this->firework(60 + fmod($this->time * 97, 200), 30 + fmod($this->time * 53, 60));
            $this->nextAuto = $this->time + 0.9;
        }
        $this->particles->update($dt);
    }

    public function firework(float $x, float $y): void
    {
        $this->particles->burst($this->fx['firework']->moveTo($x, $y), 70);
        $this->fireworks++;
    }

    public function render(Renderer $r): void
    {
        $r->clear([8, 8, 20]);
        $r->fillRect(0, 168, $this->width(), 12, '#1b1b2e');
        foreach ([40, 280] as $x) {
            $r->fillRect($x - 3, 150, 6, 18, '#5a3a22');
        }
        $r->fillRect(150, 168, 20, 4, '#3a4a6a');
        $this->particles->render($r);
        $r->drawRect($this->cursorX - 3, $this->cursorY - 3, 7, 7, '#ffffff80');
        $font = $this->font();
        $font->draw($r, 'PARTICLES', 4, 4, 1, [255, 255, 255], 'left', [0, 0, 0]);
        $font->draw($r, $this->particles->count() . ' ALIVE', $this->width() - 4, 4, 1, [180, 200, 255], 'right', [0, 0, 0]);
        $font->draw($r, 'ARROWS / MOUSE: AIM   ' . strtoupper($this->actions()->label('boom')) . ': FIREWORK', $this->width() / 2, 172, 1, [150, 150, 190], 'center');
    }
}
