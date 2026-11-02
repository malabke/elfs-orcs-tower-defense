# JumPHP IDE

[Français](ide.fr.md)

An IDE for JumPHP projects (games, desktop apps, hybrid games, Amstrad CPC remakes, ports), **written in PHP**, that
works the same way **as a native app on your computer** and **on the web** (`jumphp.net/ide`). It edits your real
projects: you sign in with jumphp.net, it lists your projects (your GitHub repositories), clones them, and pushes your
commits.

```
ide/                   the package jumphp/ide: the PHP core + the interface (HTML / CSS / JS, Monaco)
  src/                 JumPHP\Ide\: Workspace, Php (index, code intelligence), Search, Git, Run, Project, Ide (commands)
  ui/                  index.html, css/, js/ (app, editor, tree, search, git, run, welcome, designer, backend), img/
  tools/               fetch-vendor.sh (Monaco into ui/vendor/, not in git), icon.php
  tests/               PHPUnit (the core: completion, definition, usages, rename, problems, search, Git, workspaces)
games/ide/             the desktop app (Tauri, docs/tauri.md): Main (session, projects, dispatch), Playground (API),
                       Loopback (sign-in), Creator (New Project); pages = vendor/jumphp/ide/ui
```

## Features

| | |
|---|---|
| **Editor** | Monaco (the editor of VS Code): tabs, preview tabs, split right, sticky scroll, multi-cursor, minimap (option), diff, autosave, dark / light themes |
| **PHP code intelligence** | completion that follows types (`$this->engine->config->`, return types, `@var`, `@property`, `@method`, generics `list<Foo>`), auto-import (`use`), parameter hints, go to declaration / implementation, find usages, rename (classes, methods, properties, constants, variables; a class renames its file), quick documentation, file structure, problems (syntax errors, unknown classes / methods / functions), breadcrumbs, highlight of the symbol under the cursor |
| **Navigation** | Search Everywhere (Double Shift: classes, files, symbols, actions, `:line`), go to file / class / symbol, recent files, find / replace in files (case, words, regex, file masks), back / forward |
| **Git** | Commit tool window (changes, unversioned files, check boxes, amend), diff with HEAD, commit and push, update (pull), branches (checkout, new, merge, delete), stash, log with the files of each commit and their diff, annotate (blame), rollback |
| **Run** | the run configurations of the project (`bin/jumphp run / test / shot / build / webshot / serve / export / lint`, per game or app, plus `.jumphp/run.json`), Run tool window with live output, clickable `file:line`, test results (PHPUnit testdox) as a tree, screenshots opened in a tab |
| **Visual HTML / CSS editor** | `.html` files: Code / Split / Design. Components (drag and drop), layers, inspector (attributes, classes, inline styles), the CSS rules of the linked stylesheets (edited in place), devices (desktop, tablet, phone), undo / redo; the HTML is kept as written (scripts, head, comments). For desktop apps' pages (`games/<app>/ui/`) and game sites (`games/<id>/site/`) |
| **Play** | green Play in the editor's and the Project view's context menus (`Ctrl+Shift+F10`): a `src/Scene/*.php` starts the game in that scene, a `tests/*Test.php` runs only its tests (results as a tree in Run), else the whole game. Web: the **Game** window (right or bottom) with ⚡ Live reload and the game's console |
| **Hierarchy, libraries** | Hierarchy tool window (`Ctrl+H`): the class's ancestors as a tree, the classes under it; External Libraries in the Project view (the engine, vendor packages), read only |
| **Claude** | desktop: the Claude tool window (`Alt+8`) talks to the Claude Code of your computer (`claude -p` in the project's folder): its answers, the files it reads and edits (click to open), the commands it runs, as they come; the conversation goes on; what it may do: edit files, plan only, ask, full auto. "Ask Claude about the Selection" in the editor's menu |
| **Claude on the web** | jumphp.net/ide uses the Claude Code of your computer: `bin/jumphp claude:connect` (or the account menu › Claude for jumphp.net) opens the JumPHP IDE, authorized once in the browser; it takes the web IDE's messages, runs Claude on a copy of the project with your drafts (`~/.cache/jumphp/claude-bridge/`), streams the answer back, and the files Claude changed become drafts |
| **Projects** | sign in with jumphp.net, your projects, clone, open a folder, clone any repository, New Project: game, desktop app, hybrid game, Amstrad CPC remake (`.dsk` kept local), port of an open-source game |
| **Games kept alone** | a repository holding only the game (`config/game.yaml` at its root, `composer.json` → `../../engine`) runs too: the IDE keeps its own JumPHP clone (the toolkit, in its data folder), links the game into it as `games/<id>` and runs `bin/jumphp` there |
| **jumphp.net** (`Alt+J`, desktop) | the game's project on jumphp.net: publish (push + sync), its settings (title, descriptions, category, listed), statistics and players' messages, make its web site (`bin/jumphp site:new`), build the executables (Windows, Linux, macOS, Android) and put them on its page, the installer hosted on jumphp.net (it installs the newest version sent, with shortcuts and updates), the Android and Apple apps with the store steps one by one (links to Play Console, App Store Connect…) |

PhpStorm's keyboard shortcuts: Double Shift, `Ctrl+N`, `Ctrl+Shift+N`, `Ctrl+E`, `Ctrl+B`, `Ctrl+Alt+B`, `Alt+F7`, `Shift+F6`,
`Ctrl+F12`, `Ctrl+P`, `Ctrl+Q`, `Ctrl+Shift+F` / `R`, `Ctrl+K`, `Ctrl+Shift+K`, `Ctrl+T`, `Shift+F10`, `Ctrl+Shift+F10`, `Ctrl+F2`,
`Ctrl+D`, `Ctrl+Y`, `Ctrl+W`, `Ctrl+Alt+L`, `Alt+1/0/3/4/6/7/9`, `Ctrl+Alt+Left/Right`, `Alt+Insert`… (Help > Keyboard Shortcuts).

## How it works

One interface, one PHP core, two hosts:

```
            ide/ui (HTML / JS, Monaco)                         the same files on both
                     │  backend.invoke(name, payload), backend.on(event)
       ┌─────────────┴──────────────┐
  desktop: window.jumphp (Tauri)     web: fetch POST /api/ide/<slug>/invoke
       │                              │
  games/ide Main ── session, projects, sign-in        jumphp-playground WebIde ── drafts, preview, tests
       │                              │
  JumPHP\Ide\Ide ── LocalWorkspace    JumPHP\Ide\Ide ── OverlayWorkspace (published game + drafts)
       files, php.*, search.*, git.* (git program), run.* (processes)
```

- **The core** (`JumPHP\Ide\Ide`) answers commands: `file.*`, `php.*` (complete, definition, references, rename, hover,
  signature, diagnostics, outline, symbols), `search.*`, `git.*`, `run.*`, `project.info`. It never blocks: Git and runs are
  processes read a little at every frame (`Processes`), results are `Deferred`.
- **The PHP index** (`Php\Index`): every PHP file of the project analysed with nikic/php-parser (declarations, references with
  the receiver's type when the file tells it), a few ms per frame, cached; libraries (Composer `vendor/`) found by their PSR-4
  prefixes and analysed when a class is needed; PHP's own classes and functions by Reflection.
- **Desktop**: `games/ide` is a JumPHP desktop app (docs/tauri.md). The pages call one command, `ide`; Main answers the session
  and project commands and hands the rest to the open project's `Ide`. Sign-in: a loopback (as native apps do OAuth): the IDE
  listens on `127.0.0.1:<random port>`, opens `jumphp.net/ide/connect`, you authorize, jumphp.net sends the browser back with a
  token (`jpi_…`, revocable in the studio). Git push uses your GitHub token through jumphp.net, passed in git's environment,
  never written in the repository.
- **Web**: `jumphp.net/ide/<project>` serves the same `ui/`; its commands work on the published game with your drafts on top
  (the same drafts as the online editor); commit = one commit pushed to GitHub, the **Game** tool window (right, `Shift+F10` / `Alt+5`) plays the game with the
  drafts beside the code: ⚡ Live puts each save into the running game (hot reload: pictures and sounds in place, code by PHP
  started again in the scene that was playing), Restart, the game's PHP output in its console; tests run
  in the playground's sandbox. The engine's classes and SDL / OpenGL / OpenAL functions resolve there too (sources and stubs
  installed with the IDE by the playground's `bin/ide-install`). Branches, log, blame and local runs are desktop only.

## Commands

```bash
bin/jumphp install ide           # composer install (and Monaco into ide/ui/vendor/)
bin/jumphp app:run ide           # the IDE on your screen (Docker: see below)
bin/jumphp app:test ide          # php -l, phpstan, phpunit (AppTestCase), the app for real on Xvfb
bin/jumphp app:export ide        # games/ide/dist/standalone/ide-linux-x86_64: the IDE as one executable
bin/jumphp php ide vendor/bin/phpunit   # the core's tests
games/ide/tools/shot.sh var/screenshots/x.png 600 /workspace "<javascript>"   # a screenshot driven by a script
```

**Use the exported executable** to work on your projects: it runs on your computer, with your `git` and your Docker
(`bin/jumphp run / test` of the projects need Docker, as always). `app:run` runs the IDE inside the Tauri image: fine to
try it, but there it only sees the JumPHP repository and has no Docker.

Environment: `JUMPHP_IDE_SERVER` (another jumphp.net, e.g. `http://jumphp.localhost:8000`), `JUMPHP_IDE_PROJECTS` (where
projects are cloned, default `~/JumPHP-Projects`), `JUMPHP_IDE_PROJECT` (open this folder), `JUMPHP_IDE_EVAL` (JavaScript run
in the page once loaded: checks and screenshots).

**Releases**: `bin/jumphp app:export ide --linux --windows --macos`, the three files (`ide-linux-x86_64`,
`ide-windows-x86_64.exe`, `ide-macos.zip`) attached to a GitHub release tagged `ide-v<version>` (game.yaml `version`),
then bump `games/ide/installer/version.json` on main: the installers (`JumPHP-IDE-setup.exe`, `-setup-linux-x86_64`,
`-setup-macos.zip`, [jumphp2installer](https://github.com/davidnurdin/jumphp2installer)) read `games/ide/installer/` on
main through raw.githubusercontent.com, so the ones already given install the new version: no need to remake them.
Not `tools/github-release.sh` (it points them to the repository's newest release, which is JumPHP's, not the IDE's).
Remaking them (another installer version, pictures, `install.yaml`): from a jumphp2installer clone, with the package
folder copied into it and its base installers in `bases/` (`gh release download installer-v… -p 'jumphp2installer-*'`):
`bin/jumphp php . tools/package-release.php <pkg> https://raw.githubusercontent.com/davidnurdin/jumPhp/main/games/ide/installer/ bases out`,
then `gh release upload ide-v<version> out/JumPHP-IDE-setup*`. `games/ide/tools/installer-banner.php` remakes its pictures.

The IDE's own files: `~/.local/share/ide` (exported) or `games/ide/var/` (session, recent projects, PHP index cache).
A project's IDE settings: `.jumphp/ide.json` (its jumphp.net project, a CPC disc path), kept out of git
(`.git/info/exclude`); custom run configurations: `.jumphp/run.json` (`[{"label": "…", "command": ["…"]}]`).

## Playground side

See the playground's README ("Le JumPHP IDE"): `bin/ide-install --from ../JumPHP` copies `ide/src` and `ide/ui` into it;
routes `/ide`, `/ide/<slug>`, `/ide/connect`, `/studio/ide`, `/api/ide/*` (Bearer `jpi_…`) and `/api/ide/<slug>/invoke`.

## Roadmap

Debugger (Xdebug in the desktop image), terminal tool window, PHP formatting, local history, more refactorings (extract
method / variable, move class), a tile map editor for games, multiple projects in one window, signed macOS / Windows executables.
