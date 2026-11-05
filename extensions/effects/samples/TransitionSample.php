<?php

namespace App\Extensions\Effects;

use JumPHP\Effects\Transition;
use JumPHP\Renderer\Renderer;
use JumPHP\Scene\AbstractScene;

/**
 * Sample of jumphp/effects: Enter, Space or a click covers the screen with the next transition (fade, wipes, iris, blinds, dissolve), the
 * colour changes under the cover, then it is revealed the same way. JUMPHP_SCENE='App\Extensions\Effects\TransitionSample' bin/jumphp run <game>
 */
final class TransitionSample extends AbstractScene
{
    public const LABEL = 'Enter cycles the transitions: fade, wipes, iris, blinds, dissolve';

    private const COLORS = [[28, 60, 110], [110, 40, 40], [30, 90, 50]];

    private int $color = 0;
    private int $type = 0;
    private string $name = '';

    public function __construct(private Transition $transition)
    {
    }

    public function enter(array $params = []): void
    {
        $this->color = 0;
        $this->type = 0;
        $this->name = '';
        $this->transition->seed(7);
        $this->transition->reveal('fade', 0.01);
        $this->transition->update(1);
    }

    public function update(float $dt): void
    {
        if (($this->actions()->isPressed('confirm') || $this->engine->input->getMouse()->isPressed(1)) && !$this->transition->isActive()) {
            $type = $this->name = Transition::TYPES[$this->type++ % count(Transition::TYPES)];
            $this->transition->cover($type, 0.45, '#000000', function () use ($type): void {
                $this->color = ($this->color + 1) % count(self::COLORS);
                $this->transition->reveal($type, 0.45, '#000000');
            });
        }
        $this->transition->update($dt);
    }

    public function render(Renderer $r): void
    {
        $r->clear(self::COLORS[$this->color]);
        $this->font()->draw($r, strtoupper($this->name ?: 'PRESS ENTER'), $this->width() / 2, $this->height() / 2 - 8, 2, [255, 255, 255], 'center');
        $this->transition->render($r);
    }
}
