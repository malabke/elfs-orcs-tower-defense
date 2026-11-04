# Engine extensions

An engine extension is a Composer package that adds a subsystem to JumPHP: particles, a physics engine, a level
format with its loader... A game requires it like any package; the engine finds it by itself.

## The contract

`composer.json` of the extension:

```json
{
    "name": "jumphp/particles",
    "type": "jumphp-extension",
    "require": { "jumphp/engine": "*" },
    "autoload": { "psr-4": { "JumPHP\\Particles\\": "src/" } },
    "extra": {
        "jumphp": {
            "name": "Particles",
            "services": ["JumPHP\\Particles\\ParticleSystem"],
            "loaders": { ".particles.json": "JumPHP\\Particles\\EmitterLoader" },
            "config": {
                "particles": { "default": { "max": 4000, "seed": 1 }, "doc": "max particles alive, random seed" }
            }
        }
    }
}
```

| Key | What the engine does |
|---|---|
| `type: jumphp-extension` | at boot, `JumPHP\Extension\Extensions` reads the game's `vendor/composer/installed.json` and keeps the packages of this type |
| `services` | each class is autowired and public in the game's container (before the game's own `config/services.yaml`, which can override them): a scene asks for one in its constructor |
| `services_file` | a Symfony services file of the package (path inside it), for anything `services` cannot say |
| `boot` | a class implementing `JumPHP\Extension\Extension`, made by the container: `boot(Engine $engine)` runs once SDL, options and language are ready, before the first scene |
| `loaders` | `{"<end of a file name>": "Class"}`, classes implementing `JumPHP\Extension\AssetLoader`: `$this->engine->extensions()->load('assets/fx/torch.particles.json')` picks the loader by the file's end |
| `config` | project.yaml keys of the extension with their `default`: `$engine->config->get('particles')` gives the game's values over the defaults (arrays merged key by key) |

`$this->engine->extensions()`: `all()`, `has('jumphp/particles')`, `load($file)`.

Rules: pure PHP, as in a game (AGENTS.md §2): the browser build packs the game's `vendor/` and compiles the container
with the extensions' services, so an extension works in the browser without anything more. Not on jumphp.net yet: its
shared engine does not load a game's own Composer packages.

## Making one

```bash
bin/jumphp extension:new star-field "Star Field"
bin/jumphp install star-field-demo
bin/jumphp test star-field-demo        # the example game's tests: the extension's tests
bin/jumphp shot star-field-demo
```

- `extensions/star-field/`: the package (`JumphpExtensions\StarField\`), a service (`Flash`), its boot class, a
  config key, README.md, AGENTS.md: replace them with your subsystem.
- `games/star-field-demo/`: a game requiring it through a path repository (`../../extensions/star-field`): it shows
  the extension and its `tests/` play it (GameTestCase). Develop both together.
- Publish: a GitHub repository of its own holding the package, then Packagist (rename `name` in composer.json first:
  `<your-vendor>/<package>`); a game then `bin/jumphp composer <game> require <your-vendor>/<package>`.

## The particles extension

`extensions/particles` (`jumphp/particles`), shown by `games/particles-demo`:

```php
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
    $this->fire->moveTo($this->hero->centerX(), $this->hero->getY());
    if ($hit) {
        $this->particles->burst($this->sparks->moveTo($x, $y), 40);
    }
    $this->particles->update($dt);
}

public function render(Renderer $r): void
{
    $this->particles->render($r, $camera->getX(), $camera->getY());
}
```

- Emitter settings: `rate` (a second; 0 = bursts only), `duration` (null = until `stop()`), `life`, `speed` (ranges
  `[min, max]`), `angle` / `spread` (degrees, 0 = right, -90 = up), `gravity` (px/s², negative rises), `drag` (0..1 a
  second), `colors` (over the life; `#rrggbbaa` fades), `size` (`[birth, death]`), `shape` (`circle` / `square`),
  `area` (born in a box).
- Deterministic: the same seed (project.yaml `particles: {seed: 7}`, or `reset(7)`) and the same frames give the same
  particles, on the desktop and in the browser (its generator works on floats, not 64-bit integers).
- `particles: {max: 3000}` caps the particles alive in a system. Colours go through a 16-step ramp per emitter, so
  translucent particles stay cheap (few cached textures).
- Files: `assets/fx/show.particles.json` = `{"torch": {...settings...}, "snow": {...}}`, read with
  `$this->engine->extensions()->load('assets/fx/show.particles.json')` (named `Emitter`s).
- Inspect: `count()`, `countOf($emitter)`, `particles()` (positions, speeds, ages), `emitters()`.
