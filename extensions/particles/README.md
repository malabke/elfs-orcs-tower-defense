# jumphp/particles

A particle engine for JumPHP games, and the first JumPHP **engine extension** (docs/extensions.md): a Composer package
of type `jumphp-extension` the engine finds by itself.

```php
public function __construct(private ParticleSystem $particles) {}          // a scene: its own system

public function enter(array $params = []): void
{
    $this->particles->reset();
    $this->fire = $this->particles->add(new Emitter(['x' => 160, 'y' => 150, 'rate' => 80, 'life' => [0.6, 1.2],
        'speed' => [30, 70], 'angle' => -90, 'spread' => 20, 'gravity' => -40,
        'colors' => ['#fff6a0', '#ff9a1f', '#c0301000'], 'size' => [5, 1], 'shape' => 'circle']));
}

public function update(float $dt): void { $this->particles->update($dt); }
public function render(Renderer $r): void { $this->particles->render($r); }
```

`burst($emitter, 40)` for explosions, `moveTo()` to follow an entity, `.particles.json` files read with
`$this->engine->extensions()->load('assets/fx/torch.particles.json')`, `particles: {max: 4000, seed: 1}` in
config/project.yaml. Example: games/particles-demo.
