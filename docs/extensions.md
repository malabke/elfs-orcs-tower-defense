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

## Samples and documentation

What an extension brings into a game besides its code (`bin/jumphp extension:add <game> <vendor/package>` does it, `extension:remove` undoes it):

| in the package | in the game | what for |
|---|---|---|
| `doc/` (`doc/index.md`, else its `README.md`) | `extensions/<name>/doc/` | its documentation, opened by the IDE |
| `samples/*.php` | `src/Extensions/<Name>/` | scenes of the game that play and build with it, also in the browser |
| | `extensions/<name>/extension.json` | what the IDE reads: the documentation and the samples |

- A sample is a scene in the namespace `App\Extensions\<Name>` (`<Name>` = the package name in StudlyCase: `jumphp/particles` -> `Particles`);
  `public const LABEL = '...'` is what the IDE shows. A sample the game already has (maybe edited) is not overwritten.
- Play one: `JUMPHP_SCENE='App\Extensions\Particles\FireSample' bin/jumphp run <game>`; in the IDE the documentation opens after
  the install (Tools > Engine Extensions…) with a **Play** button for each sample, and from the External Libraries (the extension
  has its package name and a **Docs** button there).
- `extra.jumphp.samples` / `extra.jumphp.doc` name other folders than `samples/` and `doc/`.
- JumPHP's own five (`particles`, `physics`, `shorts`, `effects`, `collision`) have both: try `bin/jumphp extension:add <game> jumphp/particles`.

## A clean project

A new project (composer create-project, the IDE's New Project, jumphp.net's) does not keep JumPHP's own development files: its
extensions (they are on Packagist: `extension:add`), its examples of `games/` and `apps/`, its notes (`TODO*.md`), its tooling for its own
releases. `tools/project-clean.list` says what, `tools/project-clean.sh` does it, `bin/jumphp tidy` does it again in an older project
(`--examples` also takes JumPHP's example games out; your own games and apps are never touched). What was removed is listed in
`.jumphp/excluded`: `bin/jumphp update` does not bring it back, and a merge that touches those files raises no conflict.

## Its page on jumphp.net

https://jumphp.net/extensions lists every Packagist package of type `jumphp-extension`; each has a page
(`/extensions/<vendor>/<package>`): its README, how to add it, and what its `composer.json` `extra.jumphp` says about it
(the page reads them, nothing to upload):

| key | what the page shows |
|---|---|
| `summary` | one line under the title (else the Composer `description`) |
| `engine` | "Works with JumPHP ^0.5" (see below) |
| `features` | a list of what it brings (12 lines of 160 characters at most) |
| `screenshots` | pictures: https addresses or paths in the repository (`docs/shot.png`; png, jpg, gif, webp; 8 at most) |
| `example` | a code example (4000 characters at most) |
| `docs` | the address of its documentation |
| `demo` | its example game |
| `services`, `config` | read as they are: the services it adds, the project.yaml keys it reads |

**Install in my IDE**: the page's button asks the JumPHP IDE running on the visitor's computer (it listens on `127.0.0.1`,
ports 47321-47325, only for pages of jumphp.net). The IDE asks its user to confirm, and for an extension which game of the
project gets it (`bin/jumphp extension:add`); for an IDE plugin it installs it. Nothing is installed without the yes.
In the IDE: Tools > **Engine Extensions…** searches the same list. `JUMPHP_IDE_LINK=0` turns the listener off.

## Which JumPHP it works with

`extra.jumphp.engine` is a version constraint on JumPHP (`"^0.5"`, `">=0.5 <0.7"`, `"0.5.*"`, `"^0.5 || ^0.9"`). At boot the engine
compares it with the project's `VERSION`: a game on a JumPHP the extension does not accept stops with
`acme/stars works with JumPHP ^0.6, this game runs JumPHP 0.5.7: update JumPHP (bin/jumphp update) or the extension`.
Without the key, or when the version is unknown (some builds without a `VERSION` file), nothing is checked. Declare it in
every extension you publish, and widen it after trying the newer JumPHP.

## Making one

In the JumPHP IDE: New Project > **Engine extension** (your own GitHub repository, the package and its example game, README with the
publishing steps). On the command line:

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
- Publish: see below.

## Publishing an extension

Packagist serves a package from a repository with its `composer.json` at the root, its versions being the git tags.

**Your own extension**: a public GitHub repository holding `extensions/<id>/`'s content at its root (rename `name`
first: `<your-vendor>/<package>`, keep `"type": "jumphp-extension"`), a tag (`git tag v1.0.0 && git push --tags`), then
https://packagist.org/packages/submit with the repository's address (signed in with GitHub, Packagist installs its hook:
each new tag is a new version). Games then `bin/jumphp extension:add <game> <your-vendor>/<package>`, and
https://jumphp.net/extensions lists it (it reads Packagist's `jumphp-extension` packages). Do not require
`jumphp/engine`: it is not on Packagist, and every JumPHP project already has it (`../../engine`).

**JumPHP's own** (`jumphp/particles`, `jumphp/physics`, `jumphp/shorts`, `jumphp/effects`, `jumphp/collision`): developed here in `extensions/<id>`, split to
one read-only repository each, `davidnurdin/jumphp-<id>`, by `.github/workflows/extensions-split.yml` on each release
tag `v*` (the split gets the same tag) — the same as `tools/extensions-publish.sh --push`. Without `--push` the script is
a dry run: the splits go to `.build/extensions-split/<id>.git`, to look at or to require through a `vcs` repository.

Once, to set it up:
1. create the three empty public repositories `davidnurdin/jumphp-particles`, `jumphp-physics`, `jumphp-shorts`;
2. a fine-grained token (GitHub > Settings > Developer settings) with *Contents: read and write* on these three, saved as
   the JumPHP repository's secret `EXTENSIONS_SPLIT_TOKEN`;
3. run the workflow once (Actions > extensions-split > Run workflow, or the next `v*` tag);
4. submit each repository on https://packagist.org/packages/submit.

## The effects extension

`extensions/effects` (`jumphp/effects`), shown by `games/effects-demo`: screen **transitions** (`Transition`: fade, four wipes, an iris on a
point, blinds, a dissolve; `cover()` a scene change `reveal()`), a **flash**, a **vignette** and **easing curves** (`Ease`). Everything is
drawn with translucent rectangles, pure PHP, 32-bit safe. Its `doc/index.md` has the code; `bin/jumphp extension:add <game> jumphp/effects`
brings two samples (`FlashSample`, `TransitionSample`) into your game. Before its repository `davidnurdin/jumphp-effects` exists and it is
submitted on Packagist, the release split skips it with a warning (the others are not blocked).

## The collision extension

`extensions/collision` (`jumphp/collision`), shown by `games/collision-demo`: circles, boxes and convex polygons; `Collision::test($a, $b)` gives
the normal and the depth (the minimum translation: `$hit->pushOutOfFirst()`), `Collision::raycast()` the first shape a ray meets, a
`SpatialHash` broad phase and a `CollisionWorld` service (`add`, `update`, `collisions()`, `at()`, `overlapping()`, `raycast()`). Pure PHP on
floats. Its `doc/index.md` has the code, and `bin/jumphp extension:add <game> jumphp/collision` brings `CollisionSample` into the game.

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
