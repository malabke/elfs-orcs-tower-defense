<?php

namespace JumPHP\Particles;

use JumPHP\Util\Color;

/**
 * Where particles come from and how they live. Every setting has a default; ranges are [min, max]:
 *
 *   new Emitter([
 *       'x' => 160, 'y' => 150,          // where (world pixels; move() it to follow something)
 *       'rate' => 80,                    // particles a second while active (0: bursts only)
 *       'duration' => null,              // seconds it emits, null = until stop()
 *       'life' => [0.6, 1.2],            // seconds each one lives
 *       'speed' => [30, 70],             // pixels a second
 *       'angle' => -90, 'spread' => 30,  // direction in degrees (0 = right, -90 = up) and +/- spread
 *       'gravity' => 0,                  // pixels/s² downwards (negative: rises)
 *       'drag' => 0,                     // speed lost each second (0..1)
 *       'colors' => ['#fff6a0', '#ff9a1f', '#c0301000'],   // over its life (alpha in #rrggbbaa fades it)
 *       'size' => [4, 1],                // pixels, at birth and at death
 *       'shape' => 'circle',             // 'circle' or 'square'
 *       'area' => [0, 0],                // born anywhere in a box of this width / height around x, y
 *   ]);
 */
final class Emitter
{
    public const STEPS = 16;

    public float $x;
    public float $y;
    public float $rate;
    public ?float $duration;
    /** @var array{0: float, 1: float} */
    public array $life;
    /** @var array{0: float, 1: float} */
    public array $speed;
    public float $angle;
    public float $spread;
    public float $gravity;
    public float $drag;
    /** @var array{0: float, 1: float} */
    public array $size;
    public string $shape;
    /** @var array{0: float, 1: float} */
    public array $area;
    public bool $active = true;
    public float $age = 0;
    /** @internal particles owed by the rate, carried between frames */
    public float $pending = 0;
    /** @var list<Color> the colour of each step of a particle's life (STEPS of them) */
    public array $ramp = [];

    /** @param array<string, mixed> $settings */
    public function __construct(array $settings = [])
    {
        $range = fn (mixed $v, float $a, float $b): array => is_array($v) ? [(float)($v[0] ?? $a), (float)($v[1] ?? $v[0] ?? $b)]
            : ($v === null ? [$a, $b] : [(float)$v, (float)$v]);
        $this->x = (float)($settings['x'] ?? 0);
        $this->y = (float)($settings['y'] ?? 0);
        $this->rate = max(0, (float)($settings['rate'] ?? 30));
        $this->duration = isset($settings['duration']) ? (float)$settings['duration'] : null;
        $this->life = $range($settings['life'] ?? null, 0.5, 1.0);
        $this->speed = $range($settings['speed'] ?? null, 20, 60);
        $this->angle = (float)($settings['angle'] ?? -90);
        $this->spread = (float)($settings['spread'] ?? 180);
        $this->gravity = (float)($settings['gravity'] ?? 0);
        $this->drag = max(0, min(1, (float)($settings['drag'] ?? 0)));
        $this->size = $range($settings['size'] ?? null, 2, 2);
        $this->shape = ($settings['shape'] ?? 'square') === 'circle' ? 'circle' : 'square';
        $this->area = $range($settings['area'] ?? null, 0, 0);
        $this->colors((array)($settings['colors'] ?? ['#ffffff']));
    }

    /** @param list<Color|array<int, int>|string> $colors the colours over a particle's life, evenly spaced */
    public function colors(array $colors): self
    {
        $stops = array_map(Color::of(...), array_values($colors)) ?: [Color::white()];
        $this->ramp = [];
        for ($i = 0; $i < self::STEPS; $i++) {
            $t = $i / (self::STEPS - 1) * (count($stops) - 1);
            $a = $stops[(int)floor($t)];
            $b = $stops[min(count($stops) - 1, (int)floor($t) + 1)];
            $f = $t - floor($t);
            $mix = fn (int $p, int $q): int => (int)round($p + ($q - $p) * $f);
            $this->ramp[] = new Color($mix($a->r, $b->r), $mix($a->g, $b->g), $mix($a->b, $b->b), $mix($a->a, $b->a));
        }
        return $this;
    }

    public function moveTo(float $x, float $y): self
    {
        $this->x = $x;
        $this->y = $y;
        return $this;
    }

    public function stop(): void
    {
        $this->active = false;
    }

    public function start(): void
    {
        $this->active = true;
        $this->age = 0;
    }

    /** Still giving particles (active, and its duration not over). */
    public function emitting(): bool
    {
        return $this->active && ($this->duration === null || $this->age < $this->duration);
    }

    /** @param array<string, mixed> $settings */
    public static function fromArray(array $settings): self
    {
        return new self($settings);
    }
}
