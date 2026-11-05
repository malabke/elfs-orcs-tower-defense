<?php

namespace App\Extensions\Effects;

use JumPHP\Renderer\Renderer;
use JumPHP\Scene\AbstractScene;
use JumPHP\Effects\Flash;

/**
 * Sample of the extension (extension:add copies it into the game, src/Extensions/<Name>/): Enter, Space or a click flashes the screen.
 * JUMPHP_SCENE='App\Extensions\Effects\FlashSample' bin/jumphp run <game>
 */
final class FlashSample extends AbstractScene
{
    /** what the IDE writes by its Play button */
    public const LABEL = 'Enter, Space or a click flashes the screen';

    public function __construct(private Flash $flash)
    {
    }

    public function enter(array $params = []): void
    {
        $this->flash->flash('#ff4040');
    }

    public function update(float $dt): void
    {
        if ($this->actions()->isPressed('confirm') || $this->engine->input->getMouse()->isPressed(1)) {
            $this->flash->flash('#40a0ff');
        }
        $this->flash->update($dt);
    }

    public function render(Renderer $r): void
    {
        $r->clear([16, 14, 28]);
        $this->font()->draw($r, 'PRESS ENTER', $this->width() / 2, $this->height() / 2 - 4, 1, [200, 205, 235], 'center');
        $this->flash->render($r);
    }
}
