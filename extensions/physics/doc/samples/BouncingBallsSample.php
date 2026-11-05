<?php

namespace App\Extensions\Physics;

use JumPHP\Physics2d\Ball;
use JumPHP\Physics2d\Peg;
use JumPHP\Physics2d\Wall;
use JumPHP\Physics2d\World;
use JumPHP\Renderer\Renderer;
use JumPHP\Scene\AbstractScene;

/**
 * Sample of jumphp/physics: balls falling on pegs inside a box; a new ball where you click (Enter, Space) falls from the top.
 * JUMPHP_SCENE='App\Extensions\Physics\BouncingBallsSample' bin/jumphp run <game>
 */
final class BouncingBallsSample extends AbstractScene
{
    public const LABEL = 'Balls falling on pegs in a box, a new ball on click';

    private int $dropped = 0;

    public function __construct(private World $world)
    {
    }

    public function enter(array $params = []): void
    {
        $this->world->reset(7);
        $w = $this->width();
        $h = $this->height();
        foreach ([[8, 8, 8, $h - 8], [$w - 8, 8, $w - 8, $h - 8], [8, $h - 8, $w - 8, $h - 8]] as [$a, $b, $c, $d]) {
            $this->world->addWall(new Wall($a, $b, $c, $d, '#5a5f88'));
        }
        for ($row = 0; $row < 4; $row++) {
            for ($i = 0; $i < 7; $i++) {
                $this->world->addPeg(new Peg(40 + $i * 40 + ($row % 2) * 20, 50 + $row * 28, 4, '#ffcc00'));
            }
        }
        $this->dropped = 0;
        $this->drop(80);
    }

    private function drop(float $x): void
    {
        $colors = ['#ff4fa3', '#4fd1ff', '#7dff7d', '#ffb347'];
        $this->world->addBall(new Ball($x, 14, 0, 0, 5, $colors[$this->dropped++ % 4]));
    }

    public function update(float $dt): void
    {
        $mouse = $this->engine->input->getMouse();
        if ($this->actions()->isPressed('confirm') || $mouse->isPressed(1)) {
            $this->drop($mouse->isPressed(1) ? max(20, min($this->width() - 20, $mouse->x)) : 40 + fmod($this->dropped * 53, $this->width() - 80));
        }
        $this->world->update($dt);
    }

    public function render(Renderer $r): void
    {
        $r->clear([12, 10, 20]);
        foreach ($this->world->walls() as $w) {
            $r->drawLine((int)$w->x1, (int)$w->y1, (int)$w->x2, (int)$w->y2, $w->color);
        }
        foreach ($this->world->pegs() as $p) {
            $r->fillCircle((int)$p->x, (int)$p->y, (int)$p->radius, $p->color);
        }
        foreach ($this->world->balls() as $b) {
            $r->fillCircle((int)round($b->x), (int)round($b->y), (int)max(1, round($b->radius)), $b->color);
        }
        $this->font()->draw($r, 'CLICK OR PRESS ENTER: A NEW BALL', $this->width() / 2, 2, 1, [200, 205, 235], 'center');
    }
}
