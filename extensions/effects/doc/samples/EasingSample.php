<?php

namespace App\Extensions\Effects;

use JumPHP\Effects\Ease;
use JumPHP\Effects\Vignette;
use JumPHP\Renderer\Renderer;
use JumPHP\Scene\AbstractScene;

/**
 * Sample of jumphp/effects: every easing curve moves a dot over the same two seconds, under a vignette (the dark corners of the screen).
 * JUMPHP_SCENE='App\Extensions\Effects\EasingSample' bin/jumphp run <game>
 */
final class EasingSample extends AbstractScene
{
    public const LABEL = 'Each easing curve moves a dot from side to side, under a vignette';

    private float $time = 0;

    public function enter(array $params = []): void
    {
        $this->time = 0;
    }

    public function update(float $dt): void
    {
        $this->time += $dt;
    }

    public function render(Renderer $r): void
    {
        $r->clear([20, 22, 40]);
        $names = Ease::NAMES;
        $rowHeight = ($this->height() - 16) / max(1, count($names));
        $t = fmod($this->time, 2.4) / 2.0;                       // 0 -> 1 in two seconds, then it waits a little
        $t = min(1.0, $t);
        foreach ($names as $i => $name) {
            $y = 8 + $i * $rowHeight + $rowHeight / 2;
            $r->drawLine(60, (int)$y, $this->width() - 12, (int)$y, [60, 64, 96]);
            $x = Ease::lerp(60, $this->width() - 12, Ease::get($name)($t));
            $r->fillCircle((int)$x, (int)$y, 3, '#ffd040');
            $this->font()->draw($r, strtoupper($name), 4, (int)$y - 3, 1, [200, 205, 235]);
        }
        Vignette::render($r, 0.6);
    }
}
