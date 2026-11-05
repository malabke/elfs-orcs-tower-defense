# skeleton-extension — notes for agents

A JumPHP engine extension (read `../../AGENTS.md`, section "Engine extensions", and `../../docs/extensions.md`).

- `composer.json` `extra.jumphp`: what the engine registers (services, boot, loaders, config keys with defaults) and what jumphp.net shows
  (`summary` one line, `features` a list, `screenshots` https pictures, `example`, `docs`, `engine` the JumPHP versions it works with).
- Pure PHP only (the browser build packs it): no extension of PHP, no blocking call, 32-bit integers.
- Its example game `games/skeleton-extension-demo` requires it through a path repository: develop both together,
  `bin/jumphp test skeleton-extension-demo` (game tests of the extension), `bin/jumphp shot`, `bin/jumphp build` + `webshot`.

## What a finished extension has (do ALL of it, in this order, as you write the code)

1. **The code** in `src/` (namespace of the package), small classes, one job each; services the scenes ask for in `extra.jumphp.services`.
2. **Unit tests** of the logic in the example game's `tests/` (no window), and **game tests** of what is seen (GameTestCase).
3. **`doc/index.md`**: what it brings, how to use it (short code), the project.yaml keys, the list of samples. Written for someone who has never seen it.
4. **`doc/samples/*.php`: one sample per thing it does** (not one for the whole): a scene `final class <Thing>Sample extends AbstractScene`,
   namespace `App\Extensions\<Pascal of the id>`, `public const LABEL = '...'` (what it shows, one line), a comment on top saying what to press and
   how to run it (`JUMPHP_SCENE='App\Extensions\<Name>\<Thing>Sample' bin/jumphp run <game>`), actions only the engine's (`confirm`, `up`, `down`, `left`,
   `right`, `back`) or the mouse, a text on the screen telling what is happening. Concrete, small (about 60 lines), pretty to look at, runnable alone.
5. **`tests/SamplesTest.php` of the example game** already starts every sample 90 frames: run `bin/jumphp test <id>-demo`; a new sample needs nothing more.
   The example game autoloads the samples where they are (composer.json + config/services.yaml): the IDE's ▶ on a sample's `class` line runs it.
6. **`README.md`** (what it is, install, one example) and `extra.jumphp` filled (`summary`, `features`, `engine`), screenshots made with `bin/jumphp shot`.
7. **Check** `bin/jumphp test <id>-demo` passes, look at a `bin/jumphp shot <id>-demo` of the main sample, `bin/jumphp lint`.
8. **Publish** (the user does it): the IDE's Publish button (Publish extension) puts the package in its own GitHub repository, tagged, and
   submits it on Packagist; do not make repositories or tags yourself, and never put a token in a file.
