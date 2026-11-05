<?php

namespace App\Extensions\Collision;

use JumPHP\Collision\Box;
use JumPHP\Collision\Circle;
use JumPHP\Collision\Collision;
use JumPHP\Collision\CollisionWorld;
use JumPHP\Collision\Polygon;
use JumPHP\Renderer\Renderer;
use JumPHP\Scene\AbstractScene;

/**
 * Sample of jumphp/collision: a circle (arrows) is pushed out of a wall and a hexagon, and a ray from it to the mouse stops where it meets them.
 * JUMPHP_SCENE='App\Extensions\Collision\CollisionSample' bin/jumphp run <game>
 */
final class CollisionSample extends AbstractScene
{
    public const LABEL = 'A circle pushed out of a wall and a hexagon, a ray to the cursor';

    private Circle $player;

    public function __construct(private CollisionWorld $world)
    {
    }

    public function enter(array $params = []): void
    {
        $this->world->reset(48);
        $this->world->add('wall', new Box($this->width() / 2, 20, 16, $this->height() - 40));
        $this->world->add('hexagon', Polygon::regular($this->width() * 0.25, 60, 26, 6, 15));
        $this->player = new Circle(30, $this->height() - 30, 10);
        $this->world->add('player', $this->player);
    }

    public function update(float $dt): void
    {
        $this->player->translate($this->actions()->axis('left', 'right') * 90 * $dt, $this->actions()->axis('up', 'down') * 90 * $dt);
        $this->world->update('player');
        foreach ($this->world->collisions() as [$a, $b, $hit]) {
            if ($a === 'player' || $b === 'player') {
                [$dx, $dy] = $a === 'player' ? $hit->pushOutOfFirst() : $hit->pushOutOfSecond();
                $this->player->translate($dx, $dy);
                $this->world->update('player');
            }
        }
    }

    public function render(Renderer $r): void
    {
        $r->clear([14, 16, 30]);
        foreach ($this->world->all() as $id => $shape) {
            if ($shape instanceof Circle) {
                $r->fillCircle((int)$shape->x, (int)$shape->y, (int)$shape->radius, '#5fd0ff');
            } else {
                $points = $shape instanceof Box ? $shape->toPolygon()->points : ($shape instanceof Polygon ? $shape->points : []);
                foreach ($points as $i => $p) {
                    $q = $points[($i + 1) % count($points)];
                    $r->drawLine((int)$p[0], (int)$p[1], (int)$q[0], (int)$q[1], '#c8a0ff');
                }
            }
        }
        $mouse = $this->engine->input->getMouse();
        $others = array_filter($this->world->all(), fn ($id) => $id !== 'player', ARRAY_FILTER_USE_KEY);
        $hit = Collision::raycast($this->player->x, $this->player->y, $mouse->x - $this->player->x, $mouse->y - $this->player->y, $others);
        $r->drawLine((int)$this->player->x, (int)$this->player->y, (int)($hit->x ?? $mouse->x), (int)($hit->y ?? $mouse->y), $hit ? '#ffcc00' : '#557755');
    }
}
