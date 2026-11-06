<?php

namespace JumPHP\Particles;

use JumPHP\Kernel\GameConfig;
use JumPHP\Renderer\Renderer;

/**
 * The particles of a scene: ask for one in the scene's constructor (each scene gets its own), add() emitters,
 * update($dt) in update(), render($r, $camX, $camY) in render(). Deterministic: the same seed (project.yaml
 * particles.seed, or reset($seed)) and the same frames give the same particles.
 */
final class ParticleSystem
{
    /** @var list<Emitter> */
    private array $emitters = [];
    /**
     * Particles as plain arrays (fast): x, y, vx, vy, age, life, emitter index.
     *
     * @var list<array{0: float, 1: float, 2: float, 3: float, 4: float, 5: float, 6: int}>
     */
    private array $particles = [];
    private Random $random;
    public int $max;
    private int $seed;

    public function __construct(?GameConfig $config = null)
    {
        $settings = (array)($config?->get('particles') ?? []);
        $this->max = max(1, (int)($settings['max'] ?? 4000));
        $this->seed = (int)($settings['seed'] ?? 1);
        $this->random = new Random($this->seed);
    }

    /** Removes everything; a new seed (null: the configured one) makes it start the same way again. */
    public function reset(?int $seed = null): void
    {
        $this->emitters = [];
        $this->particles = [];
        $this->random->seed($seed ?? $this->seed);
    }

    public function add(Emitter $emitter): Emitter
    {
        $this->emitters[] = $emitter;
        return $emitter;
    }

    public function remove(Emitter $emitter): void
    {
        $index = array_search($emitter, $this->emitters, true);
        if ($index === false) {
            return;
        }
        $this->particles = array_values(array_filter($this->particles, fn (array $p): bool => $p[6] !== $index));
        foreach ($this->particles as &$p) {
            if ($p[6] > $index) {
                $p[6]--;
            }
        }
        unset($p);
        array_splice($this->emitters, (int)$index, 1);
    }

    /** $count particles at once from $emitter (an explosion, a hit), added first if needed. */
    public function burst(Emitter $emitter, int $count): void
    {
        $index = array_search($emitter, $this->emitters, true);
        if ($index === false) {
            $this->emitters[] = $emitter;
            $index = count($this->emitters) - 1;
        }
        for ($i = 0; $i < $count; $i++) {
            $this->spawn($emitter, (int)$index);
        }
    }

    public function update(float $dt): void
    {
        foreach ($this->emitters as $index => $e) {
            if ($e->emitting()) {
                $e->age += $dt;
                $e->pending += $e->rate * $dt;
                while ($e->pending >= 1) {
                    $e->pending--;
                    $this->spawn($e, $index);
                }
            }
        }
        $alive = [];
        foreach ($this->particles as $p) {
            $p[4] += $dt;
            if ($p[4] >= $p[5]) {
                continue;
            }
            $e = $this->emitters[$p[6]];
            $p[3] += $e->gravity * $dt;
            if ($e->drag > 0) {
                $keep = max(0, 1 - $e->drag * $dt);
                $p[2] *= $keep;
                $p[3] *= $keep;
            }
            $p[0] += $p[2] * $dt;
            $p[1] += $p[3] * $dt;
            $alive[] = $p;
        }
        $this->particles = $alive;
    }

    public function render(Renderer $r, float $camX = 0, float $camY = 0): void
    {
        $last = Emitter::STEPS - 1;
        foreach ($this->particles as $p) {
            $e = $this->emitters[$p[6]];
            $t = $p[4] / $p[5];
            $color = $e->ramp[(int)($t * $last + 0.5)];
            if ($color->a <= 0) {
                continue;
            }
            $size = $e->size[0] + ($e->size[1] - $e->size[0]) * $t;
            if ($size < 0.5) {
                continue;
            }
            $x = $p[0] - $camX;
            $y = $p[1] - $camY;
            if ($e->shape === 'circle' && $size >= 3) {
                $r->fillCircle($x, $y, $size / 2, $color);
            } else {
                $r->fillRect($x - $size / 2, $y - $size / 2, $size, $size, $color);
            }
        }
    }

    public function count(): int
    {
        return count($this->particles);
    }

    /** Particles alive from one emitter. */
    public function countOf(Emitter $emitter): int
    {
        $index = array_search($emitter, $this->emitters, true);
        return $index === false ? 0 : count(array_filter($this->particles, fn (array $p): bool => $p[6] === $index));
    }

    /**
     * Where the particles are (tests, effects that react to them).
     *
     * @return list<array{x: float, y: float, vx: float, vy: float, age: float, life: float}>
     */
    public function particles(): array
    {
        return array_map(fn (array $p): array => ['x' => $p[0], 'y' => $p[1], 'vx' => $p[2], 'vy' => $p[3], 'age' => $p[4], 'life' => $p[5]], $this->particles);
    }

    /** @return list<Emitter> */
    public function emitters(): array
    {
        return $this->emitters;
    }

    private function spawn(Emitter $e, int $index): void
    {
        if (count($this->particles) >= $this->max) {
            return;
        }
        $rnd = $this->random;
        $angle = deg2rad($e->angle + $rnd->between(-$e->spread, $e->spread));
        $speed = $rnd->between($e->speed[0], $e->speed[1]);
        $this->particles[] = [
            $e->x + ($e->area[0] > 0 ? $rnd->between(-$e->area[0] / 2, $e->area[0] / 2) : 0),
            $e->y + ($e->area[1] > 0 ? $rnd->between(-$e->area[1] / 2, $e->area[1] / 2) : 0),
            cos($angle) * $speed,
            sin($angle) * $speed,
            0.0,
            max(0.01, $rnd->between($e->life[0], $e->life[1])),
            $index,
        ];
    }
}
