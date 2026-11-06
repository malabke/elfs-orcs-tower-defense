<?php

namespace JumPHP\Physics2d;

use JumPHP\Kernel\GameConfig;

/**
 * The simulation: balls bouncing inside rings, on pegs and walls, under gravity, at a fixed step ($hz a second, the
 * same run for the same seed and the same calls). update($dt) advances by whole steps; hits() hands over what was hit
 * since the last call, oldest first, each one:
 *   ['type' => 'ring'|'peg'|'wall'|'ball'|'escape', 'time' => s, 'x', 'y' (where), 'speed' (the ball's, before),
 *    'ball' => Ball, 'body' => Ring|Peg|Wall|Ball]
 * ('escape': a ball left a ring through its gap). on(fn (array $hit) => ...) hears them as they happen.
 */
final class World
{
    public float $gravity = 600;
    public float $restitution = 1.0;
    public int $hz = 240;
    public float $maxSpeed = 1800;
    /** balls bounce on each other */
    public bool $ballsCollide = false;
    public float $time = 0;
    public Random $random;

    /** @var list<Ball> */
    private array $balls = [];
    /** @var list<Ring> */
    private array $rings = [];
    /** @var list<Peg> */
    private array $pegs = [];
    /** @var list<Wall> */
    private array $walls = [];
    /** @var list<array<string, mixed>> */
    private array $hits = [];
    /** @var list<callable> */
    private array $listeners = [];
    /** @var array<string, true> "ring id:ball id" of the balls that left a ring */
    private array $out = [];
    private float $carry = 0;
    private int $seed = 1;

    public function __construct(?GameConfig $config = null)
    {
        $c = (array)($config?->get('physics', []) ?? []);
        $this->gravity = (float)($c['gravity'] ?? 600);
        $this->restitution = (float)($c['restitution'] ?? 1.0);
        $this->hz = max(30, (int)($c['hz'] ?? 240));
        $this->seed = (int)($c['seed'] ?? 1);
        $this->random = new Random($this->seed);
    }

    /** Empty, time 0, the configured seed again (or $seed). */
    public function reset(?int $seed = null): void
    {
        $this->balls = $this->rings = $this->pegs = $this->walls = $this->hits = [];
        $this->listeners = [];
        $this->out = [];
        $this->time = 0;
        $this->carry = 0;
        $this->random->seed($seed ?? $this->seed);
    }

    public function on(callable $listener): void
    {
        $this->listeners[] = $listener;
    }

    public function addBall(Ball $ball): Ball
    {
        $this->balls[] = $ball;
        return $ball;
    }

    public function addRing(Ring $ring): Ring
    {
        $this->rings[] = $ring;
        return $ring;
    }

    public function addPeg(Peg $peg): Peg
    {
        $this->pegs[] = $peg;
        return $peg;
    }

    public function addWall(Wall $wall): Wall
    {
        $this->walls[] = $wall;
        return $wall;
    }

    public function removeRing(Ring $ring): void
    {
        $this->rings = array_values(array_filter($this->rings, fn (Ring $r) => $r !== $ring));
    }

    /** @return list<Ball> */
    public function balls(): array
    {
        return $this->balls;
    }

    /** @return list<Ring> */
    public function rings(): array
    {
        return $this->rings;
    }

    /** @return list<Peg> */
    public function pegs(): array
    {
        return $this->pegs;
    }

    /** @return list<Wall> */
    public function walls(): array
    {
        return $this->walls;
    }

    /** What was hit since the last call (and forgets it). @return list<array<string, mixed>> */
    public function hits(): array
    {
        $hits = $this->hits;
        $this->hits = [];
        return $hits;
    }

    public function update(float $dt): void
    {
        $h = 1 / $this->hz;
        $this->carry += $dt;
        $steps = 0;
        while ($this->carry >= $h - 1e-9 && $steps < $this->hz) {
            $this->carry -= $h;
            $this->step($h);
            $steps++;
        }
    }

    /** One fixed step of $h seconds. */
    public function step(float $h): void
    {
        $this->time += $h;
        foreach ($this->rings as $ring) {
            $ring->angle = fmod($ring->angle + $ring->spin * $h, 2 * M_PI);
            if ($ring->grow != 0) {
                $ring->radius = max($ring->minRadius, min($ring->maxRadius, $ring->radius + $ring->grow * $h));
            }
        }
        foreach ($this->balls as $ball) {
            $ball->vy += $this->gravity * $h;
            $speed = $ball->speed();
            if ($speed > $this->maxSpeed) {
                $ball->vx *= $this->maxSpeed / $speed;
                $ball->vy *= $this->maxSpeed / $speed;
            }
            $ball->x += $ball->vx * $h;
            $ball->y += $ball->vy * $h;
            $this->collideRings($ball);
            $this->collidePegs($ball);
            $this->collideWalls($ball);
            if ($ball->trailLength > 0) {
                $ball->trail[] = [$ball->x, $ball->y];
                if (count($ball->trail) > $ball->trailLength) {
                    array_shift($ball->trail);
                }
            }
        }
        if ($this->ballsCollide) {
            $this->collideBalls();
        }
    }

    private function collideRings(Ball $ball): void
    {
        foreach ($this->rings as $ring) {
            $key = spl_object_id($ring) . ':' . spl_object_id($ball);
            if (!$ring->solid || isset($this->out[$key])) {
                continue;
            }
            $dx = $ball->x - $ring->x;
            $dy = $ball->y - $ring->y;
            $d = sqrt($dx * $dx + $dy * $dy);
            if ($d - $ball->radius > $ring->radius) {
                $this->out[$key] = true;           // through the gap: out for good
                $this->hit('escape', $ball, $ring, $ball->x, $ball->y, $ball->speed());
                continue;
            }
            if ($d + $ball->radius < $ring->radius || $d < 1e-6) {
                continue;
            }
            if ($ring->inGap(atan2($dy, $dx))) {
                continue;
            }
            $nx = $dx / $d;
            $ny = $dy / $d;
            $vn = $ball->vx * $nx + $ball->vy * $ny;
            $speed = $ball->speed();
            $ball->x = $ring->x + $nx * ($ring->radius - $ball->radius);
            $ball->y = $ring->y + $ny * ($ring->radius - $ball->radius);
            if ($vn > 0) {
                $ball->vx -= (1 + $this->restitution) * $vn * $nx;
                $ball->vy -= (1 + $this->restitution) * $vn * $ny;
                $ball->hits++;
                $ring->hits++;
                if ($ring->growOnHit != 0) {
                    $ring->radius = max($ring->minRadius, min($ring->maxRadius, $ring->radius + $ring->growOnHit));
                }
                if ($ring->openOnHit != 0) {
                    $ring->gap = min(2 * M_PI, $ring->gap + $ring->openOnHit);
                }
                $this->hit('ring', $ball, $ring, $ring->x + $nx * $ring->radius, $ring->y + $ny * $ring->radius, $speed);
            }
        }
    }

    private function collidePegs(Ball $ball): void
    {
        foreach ($this->pegs as $peg) {
            $dx = $ball->x - $peg->x;
            $dy = $ball->y - $peg->y;
            $min = $ball->radius + $peg->radius;
            $d2 = $dx * $dx + $dy * $dy;
            if ($d2 >= $min * $min || $d2 < 1e-9) {
                continue;
            }
            $d = sqrt($d2);
            $nx = $dx / $d;
            $ny = $dy / $d;
            $speed = $ball->speed();
            $ball->x = $peg->x + $nx * $min;
            $ball->y = $peg->y + $ny * $min;
            $vn = $ball->vx * $nx + $ball->vy * $ny;
            if ($vn < 0) {
                $ball->vx -= (1 + $this->restitution) * $vn * $nx;
                $ball->vy -= (1 + $this->restitution) * $vn * $ny;
                $ball->hits++;
                $peg->hits++;
                $this->hit('peg', $ball, $peg, $peg->x + $nx * $peg->radius, $peg->y + $ny * $peg->radius, $speed);
            }
        }
    }

    private function collideWalls(Ball $ball): void
    {
        foreach ($this->walls as $wall) {
            $wx = $wall->x2 - $wall->x1;
            $wy = $wall->y2 - $wall->y1;
            $len2 = $wx * $wx + $wy * $wy;
            $t = $len2 > 0 ? max(0.0, min(1.0, (($ball->x - $wall->x1) * $wx + ($ball->y - $wall->y1) * $wy) / $len2)) : 0.0;
            $px = $wall->x1 + $wx * $t;
            $py = $wall->y1 + $wy * $t;
            $dx = $ball->x - $px;
            $dy = $ball->y - $py;
            $d2 = $dx * $dx + $dy * $dy;
            if ($d2 >= $ball->radius * $ball->radius || $d2 < 1e-9) {
                continue;
            }
            $d = sqrt($d2);
            $nx = $dx / $d;
            $ny = $dy / $d;
            $speed = $ball->speed();
            $ball->x = $px + $nx * $ball->radius;
            $ball->y = $py + $ny * $ball->radius;
            $vn = $ball->vx * $nx + $ball->vy * $ny;
            if ($vn < 0) {
                $ball->vx -= (1 + $this->restitution) * $vn * $nx;
                $ball->vy -= (1 + $this->restitution) * $vn * $ny;
                $ball->hits++;
                $this->hit('wall', $ball, $wall, $px, $py, $speed);
            }
        }
    }

    private function collideBalls(): void
    {
        $n = count($this->balls);
        for ($i = 0; $i < $n; $i++) {
            for ($j = $i + 1; $j < $n; $j++) {
                $a = $this->balls[$i];
                $b = $this->balls[$j];
                $dx = $b->x - $a->x;
                $dy = $b->y - $a->y;
                $min = $a->radius + $b->radius;
                $d2 = $dx * $dx + $dy * $dy;
                if ($d2 >= $min * $min || $d2 < 1e-9) {
                    continue;
                }
                $d = sqrt($d2);
                $nx = $dx / $d;
                $ny = $dy / $d;
                $push = ($min - $d) / 2;
                $a->x -= $nx * $push;
                $a->y -= $ny * $push;
                $b->x += $nx * $push;
                $b->y += $ny * $push;
                $rel = ($a->vx - $b->vx) * $nx + ($a->vy - $b->vy) * $ny;
                if ($rel > 0) {
                    $k = (1 + $this->restitution) * $rel / 2;
                    $a->vx -= $k * $nx;
                    $a->vy -= $k * $ny;
                    $b->vx += $k * $nx;
                    $b->vy += $k * $ny;
                    $this->hit('ball', $a, $b, $a->x + $nx * $a->radius, $a->y + $ny * $a->radius, $rel);
                }
            }
        }
    }

    private function hit(string $type, Ball $ball, object $body, float $x, float $y, float $speed): void
    {
        $hit = ['type' => $type, 'time' => $this->time, 'x' => $x, 'y' => $y, 'speed' => $speed, 'ball' => $ball, 'body' => $body];
        $this->hits[] = $hit;
        foreach ($this->listeners as $listener) {
            $listener($hit);
        }
    }
}
