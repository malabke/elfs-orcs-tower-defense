# Particles

`jumphp/particles`: a particle engine for JumPHP games. Emitters (rate, bursts, lifetime, speed and angle spread, gravity,
colour and size over life), seeded so that the same frames give the same show, on the desktop and in the browser.

## In a scene

```php
use JumPHP\Particles\Emitter;
use JumPHP\Particles\ParticleSystem;

public function __construct(private ParticleSystem $particles) {}       // each scene gets its own

public function enter(array $params = []): void
{
    $this->particles->reset();                        // empty, the configured seed again
    $this->fire = $this->particles->add(new Emitter([
        'x' => 160, 'y' => 150, 'rate' => 80, 'life' => [0.6, 1.2], 'speed' => [30, 70],
        'angle' => -90, 'spread' => 20, 'gravity' => -40, 'drag' => 0,
        'colors' => ['#fff6a0', '#ff9a1f', '#c0301000'], 'size' => [5, 1], 'shape' => 'circle', 'area' => [6, 2],
    ]));
}

public function update(float $dt): void
{
    $this->fire->moveTo($x, $y);
    if ($hit) { $this->particles->burst($this->sparks->moveTo($x, $y), 40); }
    $this->particles->update($dt);
}

public function render(Renderer $r): void
{
    $this->particles->render($r, $camera->getX(), $camera->getY());
}
```

## Emitter settings

| key | what |
|---|---|
| `rate` | particles a second (0 = bursts only) |
| `duration` | seconds it emits (`null` = until `stop()`) |
| `life`, `speed` | ranges `[min, max]` |
| `angle`, `spread` | degrees (0 = right, -90 = up) |
| `gravity` | px/s², negative rises |
| `drag` | 0..1 a second |
| `colors` | over the life; `#rrggbbaa` fades |
| `size` | `[birth, death]` |
| `shape` | `circle` or `square` |
| `area` | born in a box `[w, h]` |

- Deterministic: project.yaml `particles: {seed: 7}`, or `reset(7)`.
- `particles: {max: 3000}` caps the particles alive in a system.
- Files: `assets/fx/show.particles.json` = `{"torch": {...settings...}}`, read with
  `$this->engine->extensions()->load('assets/fx/show.particles.json')`.
- Inspect: `count()`, `countOf($emitter)`, `particles()`, `emitters()`.
