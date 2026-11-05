<?php

namespace App\Scene;

use JumPHP\Collision\Box;
use JumPHP\Collision\Circle;
use JumPHP\Collision\Collision;
use JumPHP\Collision\CollisionWorld;
use JumPHP\Collision\Polygon;
use JumPHP\Collision\Shape;
use JumPHP\Renderer\Renderer;
use JumPHP\Scene\AbstractScene;

/**
 * The extension at work: a circle (arrows, or the mouse) is pushed out of a wall, a hexagon and a triangle by their minimum translation,
 * and a ray from it towards the cursor stops where it meets them. The demo (smoke test, the playground's title screen) moves by itself.
 */
final class DemoScene extends AbstractScene
{
    private Circle $player;
    private float $time = 0;
    private bool $demo = false;
    private float $aimX = 0;
    private float $aimY = 0;
    public int $pushes = 0;

    public function __construct(private CollisionWorld $world)
    {
    }

    public function enter(array $params = []): void
    {
        $this->demo = (bool)($params['demo'] ?? false);
        $this->time = 0;
        $this->pushes = 0;
        $this->world->reset(48);
        $this->world->add('wall', new Box(150, 20, 16, 140));
        $this->world->add('hexagon', Polygon::regular(80, 60, 26, 6, 15));
        $this->world->add('triangle', new Polygon([[230, 130], [290, 150], [250, 100]]));
        $this->world->add('rock', new Circle(240, 50, 20));
        $this->player = new Circle(40, 140, 10);
        $this->world->add('player', $this->player);
        [$this->aimX, $this->aimY] = [300, 20];
    }

    public function update(float $dt): void
    {
        $this->time += $dt;
        $speed = 90;
        $dx = $this->actions()->axis('left', 'right') * $speed * $dt;
        $dy = $this->actions()->axis('up', 'down') * $speed * $dt;
        if ($this->demo) {
            $dx = cos($this->time * 0.9) * $speed * $dt;
            $dy = sin($this->time * 0.7) * $speed * $dt;
            $this->aimX = 160 + cos($this->time) * 140;
            $this->aimY = 90 + sin($this->time * 1.3) * 80;
        } else {
            $mouse = $this->engine->input->getMouse();
            if ($mouse->x > 0 || $mouse->y > 0) {
                [$this->aimX, $this->aimY] = [$mouse->x, $mouse->y];
            }
        }
        $this->player->translate($dx, $dy);
        $this->player->x = max(10, min($this->width() - 10, $this->player->x));
        $this->player->y = max(10, min($this->height() - 10, $this->player->y));
        $this->world->update('player');
        // out of whatever it is in: the minimum translation, found for each pair that touches
        foreach ($this->world->collisions() as [$a, $b, $hit]) {
            if ($a === 'player' || $b === 'player') {
                [$px, $py] = $a === 'player' ? $hit->pushOutOfFirst() : $hit->pushOutOfSecond();
                $this->player->translate($px, $py);
                $this->world->update('player');
                $this->pushes++;
            }
        }
    }

    public function render(Renderer $r): void
    {
        $r->clear([14, 16, 30]);
        foreach ($this->world->all() as $id => $shape) {
            $this->draw($r, $shape, $id === 'player' ? '#5fd0ff' : '#c8a0ff');
        }
        // the ray from the player to the cursor, up to what it meets (never the player itself)
        $others = array_filter($this->world->all(), fn ($id) => $id !== 'player', ARRAY_FILTER_USE_KEY);
        $dx = $this->aimX - $this->player->x;
        $dy = $this->aimY - $this->player->y;
        $hit = Collision::raycast($this->player->x, $this->player->y, $dx, $dy, $others, hypot($dx, $dy));
        $ex = $hit->x ?? $this->aimX;
        $ey = $hit->y ?? $this->aimY;
        $r->drawLine((int)$this->player->x, (int)$this->player->y, (int)$ex, (int)$ey, $hit ? '#ffcc00' : '#557755');
        if ($hit) {
            $r->fillCircle((int)$hit->x, (int)$hit->y, 2, '#ff5050');
        }
        $this->font()->draw($r, 'ARROWS: MOVE   MOUSE: AIM THE RAY', $this->width() / 2, 4, 1, [200, 205, 235], 'center');
    }

    private function draw(Renderer $r, Shape $shape, string $color): void
    {
        if ($shape instanceof Circle) {
            for ($a = 0; $a < 32; $a++) {
                $r->drawLine((int)($shape->x + cos($a / 32 * 2 * M_PI) * $shape->radius), (int)($shape->y + sin($a / 32 * 2 * M_PI) * $shape->radius),
                    (int)($shape->x + cos(($a + 1) / 32 * 2 * M_PI) * $shape->radius), (int)($shape->y + sin(($a + 1) / 32 * 2 * M_PI) * $shape->radius), $color);
            }
            return;
        }
        $points = $shape instanceof Box ? $shape->toPolygon()->points : ($shape instanceof Polygon ? $shape->points : []);
        for ($i = 0, $n = count($points); $i < $n; $i++) {
            $b = $points[($i + 1) % $n];
            $r->drawLine((int)$points[$i][0], (int)$points[$i][1], (int)$b[0], (int)$b[1], $color);
        }
    }
}
