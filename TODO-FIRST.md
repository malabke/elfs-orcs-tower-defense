# TODO FIRST: do this before anything else of TODO.md

**Before this: [TODO-URGENT.md](TODO-URGENT.md)** (bugs to fix first).

David's decision (2026-11-03): this is the next piece of work, first thing in the next session with tokens.

## `apps/` beside `games/`: projects that are not games get a folder of their own

It is odd that a CLI app, a graphic app (gui), a desktop app (Tauri) or an IDE plugin lives in `games/<id>`. Goal: those
are made in `apps/<id>`, games stay in `games/<id>`, and **nothing breaks** (existing projects keep `games/`).

Measured on 2026-11-03, the places that assume `games/`:

| Where | Hits |
|---|---|
| `bin/jumphp` | 53 |
| `tools/` (18 scripts: standalone, installers, init, post-create, sites, cli/plugin commands...) | 94 |
| `.github/workflows/` (apple, android, standalone, ide-*, tauri, app-export...) | 25 |
| the IDE (`ide/src`, `ide/ui/js`, `games/ide/src`) | ~26 |
| `engine/src` | 1 |
| jumphp-playground `src/` (it publishes only `games/<id>/` of a repository) | 37 |

Done (2026-11-03, not committed / released yet): `bin/jumphp` finds a project in `games/` then `apps/` (`project_path`,
`game_dir`, `default_game`, the Docker mounts of linked projects), `app:new` / `gui:new` / `cli:new` / `plugin:new` create
in `apps/`, `bin/jumphp update` and the IDE's updater leave `apps/` alone, `JUMPHP_APP` (JUMPHP_GAME still read), the
standalone / twa / android / apple scripts and workflows, the IDE (modules, stubs, New Project of a plugin), jumphp.net
(applications made in `apps/<id>` when the JumPHP has `project_path`, publishing finds `apps/<id>`), docs. Tried: a CLI, a
gui and a plugin project in `apps/`: install, test, run (inside the folder), shot, build + webshot, export:standalone
--linux, plugin:pack; games/pong and games/hello-cli still pass. Also tried (2026-11-04): a desktop app in `apps/` (app:test, app:shot, app:export --linux, the executable started
headless, app:release to a local folder), `export:standalone` and `installer --standalone --linux` of a gui app,
`build:remote` + remote webshot, `site:new` + `build:site` + `siteshot` of a game with `config/project.yaml`, the IDE on a
repository with `apps/` (modules and run configurations right); `pages` / `deploy` read (they go through `project_path`).
Not tried: the apple / android workflows (GitHub's machines), `deploy` / `pages` run for real (they publish).

Plan (agreed):
- one function in `bin/jumphp` finds `<id>` in `games/` then `apps/`; every command and every tools/ script goes through
  it (no more `games/$id` written by hand); the Docker mounts (`WS`, the linked games mounted at `/workspace/games/<id>`)
  handle `apps/` too
- `cli:new`, `gui:new`, `app:new`, `plugin:new` create in `apps/`; `new` (a game) in `games/`
- `default_game()` (run without an id) and `JUMPHP_GAME` work for both
- the IDE: ProjectInfo's modules, run configurations, External Libraries / the index (composer.json up to 3 levels),
  the toolkit / linked games (`Runner::linkedCommand`), New Project, the Terminal's Run
- jumphp.net: accept `games/<id>/` or `apps/<id>/` (publishing, sync, private files, the source browser, the web IDE,
  `create_project` / MCP, the AGENTS.md / BRIEF.md texts it writes); the docs in all 14 languages
- docs: AGENTS.md (map of the repository, §16-§19), docs/*.md, README, skeletons' AGENTS.md

Check EVERYTHING, target by target, with an app in `apps/` and a game in `games/`:
`test`, `shot`, `run` (with and without an id, inside the folder), `build` (WebAssembly) + `webshot`, `build:remote`,
`export:standalone` (Linux, Windows; macOS through its workflow), `installer` (`--standalone`, `--github`, `--url`),
`app:export` / `app:release`, `apple`, `twa` / `android` (dry run), `deploy`, `pages`, `bin/jumphp update` of an older
project (games/ only), a game kept in its own repository (linked into the IDE's copy of JumPHP), the IDE (Run, Test,
the Terminal, External Libraries, debugger), publishing on jumphp.net (a test project), the GitHub workflows that build
apps. Then a JumPHP release (VERSION, runtime, Packagist) and an IDE release.

## Same work: names that say "game" for every kind of project, backward compatible

Counted (2026-11-03, JumPHP + jumphp.net): `game.yaml` 266 hits in 118 files, `GameConfig` 57 / 31, `GameTestCase` 43 / 23,
`JUMPHP_GAME` 54 / 20 (`JUMPHP_APP` now read first), `game.json` 15 / 9. Waiting for David's choices.

Asked by David (2026-11-03) with the above: what is named after games but holds any project gets a neutral name, the old
one still read (nothing breaks):
- `config/game.yaml` → `config/app.yaml` (or `project.yaml`: to decide), its root key `game:` → `app:`; the engine
  (GameConfig), bin/jumphp, tools/, the IDE, jumphp.net read the new name first, then the old one; new projects get
  the new one; `bin/jumphp update` leaves old projects as they are
- `JUMPHP_GAME` (.env) → `JUMPHP_APP` (both read), `game_dir` / `default_game` / "missing <game>" messages, help texts
- the same kind of names elsewhere: `game.json` (the portal), `GameConfig`, `GameTestCase` (keep it for games, an alias
  for apps?), the IDE's labels; list them all first (grep), then decide with David what is renamed and what stays

## Where the entry point of a project is (asked by David, to keep in the docs)

- which project: the folder name, `games/<id>`, given to the commands; without it: the folder of `games/` the command
  is typed in, else `JUMPHP_GAME` (.env), else the only project
- what kind: `config/game.yaml` `kind:` (`gui`, `cli`, `plugin`; a game by default), or `ui: webview` / `ui: hybrid`
  (desktop apps)
- what starts: a game: `start_scene:` (game.yaml); a desktop app: `main: App\Main` (its `boot()`); a CLI app: `app.php`
  calls `App\Main::boot()`. The PHP entry is always `app.php` on the desktop; `engine/bootstrap/wasm.php` in the
  browser (it reads the same game.yaml); `tools/standalone-stub.php` in the one-file executables (it loads `app.php`)
