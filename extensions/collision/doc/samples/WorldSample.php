<?php

namespace App\Extensions\Collision;

use JumPHP\Collision\Circle;
use JumPHP\Collision\CollisionWorld;
use JumPHP\Renderer\Renderer;
use JumPHP\Scene\AbstractScene;

/**
 * Sample of jumphp/collision: sixty circles in a box, found in contact by the world's spatial hash (not 1800 pairs), pushed apart by the
 * minimum translation; red while touching. Click adds one under the cursor.
 * JUMPHP_SCENE='App\Extensions\Collision\WorldSample' bin/jumphp run <game>
 */
final class WorldSample extends AbstractScene
{
    public const LABEL = 'Sixty circles bouncing and pushed apart: the world and its spatial hash';

    /** @var array<int, array{0: float, 1: float}> id => velocity */
    private array $speed = [];

    private int $contacts = 0;

    public function __construct(private CollisionWorld $world)
    {
    }

    public function enter(array $params = []): void
    {
        $this->world->reset(24);
        $this->speed = [];
        for ($i = 0; $i < 60; $i++) {
            $this->spawn(20 + ($i * 37) % ($this->width() - 40), 20 + ($i * 53) % ($this->height() - 40), $i);
        }
    }

    private function spawn(float $x, float $y, int $seed): void
    {
        $id = count($this->speed);
        $this->world->add($id, new Circle($x, $y, 6 + $seed % 4));
        $a = $seed * 2.399;
        $this->speed[$id] = [cos($a) * (30 + $seed % 40), sin($a) * (30 + $seed % 40)];
    }

    public function update(float $dt): void
    {
        $mouse = $this->engine->input->getMouse();
        if ($mouse->isPressed(1) && count($this->speed) < 200) {
            $this->spawn($mouse->x, $mouse->y, count($this->speed));
        }
        foreach ($this->speed as $id => [$vx, $vy]) {
            $c = $this->world->get($id);
            [$x, $y] = $c->center();
            $x += $vx * $dt;
            $y += $vy * $dt;
            if ($x < $c->radius || $x > $this->width() - $c->radius) {
                $vx = -$vx;
                $x = max($c->radius, min($this->width() - $c->radius, $x));
            }
            if ($y < $c->radius || $y > $this->height() - $c->radius) {
                $vy = -$vy;
                $y = max($c->radius, min($this->height() - $c->radius, $y));
            }
            $this->speed[$id] = [$vx, $vy];
            $c->moveTo($x, $y);
            $this->world->update($id);
        }
        $pairs = $this->world->collisions();
        $this->contacts = count($pairs);
        foreach ($pairs as [$a, $b, $hit]) {
            $first = $this->world->get($a);
            $second = $this->world->get($b);
            $first->translate(-$hit->nx * $hit->depth / 2, -$hit->ny * $hit->depth / 2);
            $second->translate($hit->nx * $hit->depth / 2, $hit->ny * $hit->depth / 2);
            $this->world->update($a);
            $this->world->update($b);
        }
    }

    public function render(Renderer $r): void
    {
        $r->clear([16, 18, 34]);
        $touching = [];
        foreach ($this->world->collisions() as [$a, $b]) {
            $touching[$a] = $touching[$b] = true;
        }
        foreach ($this->world->all() as $id => $shape) {
            [$x, $y] = $shape->center();
            $r->fillCircle((int)$x, (int)$y, (int)$shape->radius, isset($touching[$id]) ? '#ff5050' : '#4c8bf5');
        }
        $this->font()->draw($r, count($this->speed) . ' SHAPES  ' . $this->contacts . ' CONTACTS  CLICK: ADD ONE', 4, $this->height() - 10, 1, [230, 230, 240]);
    }
}
