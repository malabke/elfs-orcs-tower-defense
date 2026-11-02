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
| **Config completion** | `config/game.yaml` (every key with what it does, scenes of the project, languages, key names...) `config/input.yaml` (actions, key / pad / touch / mouse names) and `config/services.yaml` (`_defaults`, a service's keys, the project's classes); in PHP, the game's texts in `t('…')` (config/lang.yaml) and its actions in `isPressed('…')`, `isHeld('…')`... |
| **PHP code intelligence** | completion that follows types (`$this->engine->config->`, return types, `@var`, `@property`, `@method`, generics: `list<Foo>`, `@template` classes `Box<Foo>` and their `@extends`, `class-string<T>` factories, `foreach` over collections, array shapes `array{hero: Foo}` and their keys), the libraries' classes, functions (`use function` added) and classmaps (Composer's vendor/), the extensions' stubs, auto-import (`use`), parameter hints, go to declaration / implementation, find usages, rename (classes, methods, properties, constants, variables; a class renames its file), quick documentation, file structure, problems (syntax errors, unknown classes / methods / functions), breadcrumbs, highlight of the symbol under the cursor |
| **Navigation** | Search Everywhere (Double Shift: classes, files, symbols, actions, `:line`), go to file / class / symbol, recent files, find / replace in files (case, words, regex, file masks), back / forward |
| **Git** | Commit tool window (changes, unversioned files, check boxes, amend), diff with HEAD, commit and push, update (pull), branches (checkout, new, merge, delete), stash, log with the files of each commit and their diff, annotate (blame), rollback |
| **Run** | the run configurations of the project (`bin/jumphp run / test / shot / build / webshot / serve / export / lint`, per game or app, plus `.jumphp/run.json`), Run tool window with live output, clickable `file:line`, test results (PHPUnit testdox) as a tree, screenshots opened in a tab |
| **Debugger** (desktop) | Xdebug: click in the gutter left of a line of PHP for a breakpoint (right click: a condition, `Ctrl+F8` at the caret), **Debug** (`Shift+F9`, the bug beside Run) runs the selected configuration with `JUMPHP_XDEBUG=<port>` (`bin/jumphp` then starts PHP in the desktop image with Xdebug, which connects back to the IDE; phpstan, `php -l` and composer run without it). Where it stops: the line marked, the **Debug** tool window with the stack (click a frame), the variables as a tree (read lazily), watches, an expression evaluated there; Resume `F9`, Step Over `F8`, Into `F7`, Out `Shift+F8`, Stop. Breakpoints are kept per project and follow their lines as you edit. Best with headless runs (`shot`, `test`): a game stopped in a window freezes until you resume |
| **Visual HTML / CSS editor** | `.html` files: Code / Split / Design. Components (drag and drop), layers, inspector (attributes, classes, inline styles), the CSS rules of the linked stylesheets (edited in place), devices (desktop, tablet, phone), undo / redo; the HTML is kept as written (scripts, head, comments). For desktop apps' pages (`games/<app>/ui/`) and game sites (`games/<id>/site/`) |
| **Play** | green Play in the editor's and the Project view's context menus (`Ctrl+Shift+F10`): a `src/Scene/*.php` starts the game in that scene, a `tests/*Test.php` runs only its tests (results as a tree in Run), else the whole game. Web: the **Game** window (right or bottom) with ⚡ Live reload and the game's console |
| **Hierarchy, libraries** | Hierarchy tool window (`Ctrl+H`): the class's ancestors as a tree, the classes under it; External Libraries in the Project view (the engine, vendor packages), read only |
| **Claude** | desktop: the Claude tool window (`Alt+8`) talks to the Claude Code of your computer (`claude -p` in the project's folder): its answers, the files it reads and edits (click to open), the commands it runs, as they come; the conversation goes on; what it may do: edit files, plan only, ask, full auto. "Ask Claude about the Selection" in the editor's menu |
| **Claude, seen working** | the eye of the Claude window (**Follow**, on by default): the IDE shows what Claude does as it does it. The file it reads opens at the lines it reads (blue), what an edit replaces is marked before (orange) and what it wrote after (green), its commands appear in the Run window with their output, its searches in Find, its plan (TodoWrite) above the conversation, the file in the Project view. The keyboard stays in the Claude window and nothing moves while you type in an editor |
| **Claude and jumphp.net** | the Claude window checks that Claude Code has jumphp.net's MCP server (`jumphp`: your projects, publishing, tests on jumphp.net, players' feedback, notes); when it is missing, a banner connects it in one click (`claude mcp add --scope user jumphp https://jumphp.net/mcp`, with the IDE's own sign-in as the token: signing in first if needed). Also Find Action › Connect Claude to jumphp.net |
| **Claude drives the IDE** | the IDE gives the Claude it starts its own tools (an MCP server, `jumphp-ide`, see below): open a file, point at lines with a note, show a diff or a tool window, run a run configuration in the Run window and read its result, play a scene, the problems of a file, what you are looking at (open files, cursor, selection), ask you a question in a dialog, a notification |
| **Claude on the web** | jumphp.net/ide uses the Claude Code of your computer: `bin/jumphp claude:connect` (or the account menu › Claude for jumphp.net) opens the JumPHP IDE, authorized once in the browser; it takes the web IDE's messages, runs Claude on a copy of the project with your drafts (`~/.cache/jumphp/claude-bridge/`), streams the answer back, and the files Claude changed become drafts |
| **Projects** | sign in with jumphp.net, your projects, clone, open a folder, clone any repository, New Project: game, desktop app, hybrid game, Amstrad CPC remake (`.dsk` kept local), port of an open-source game |
| **JumPHP kept up to date** | when a JumPHP project opens, the IDE brings its JumPHP files (bin/jumphp, the engine, tools, docker, skeletons, docs) to JumPHP's newest, never `games/` nor `README.md`: at once when you did not change them, else it asks (the files named; keep your changes or replace them). The commit they come from is kept in `.jumphp/jumphp.json` (commit it). `bin/jumphp update` does the same from a terminal for projects with their own history |
| **Games kept alone** | a repository holding only the game (`config/game.yaml` at its root, `composer.json` → `../../engine`) runs too: the IDE keeps its own JumPHP clone (the toolkit, in its data folder), links the game into it as `games/<id>` and runs `bin/jumphp` there |
| **jumphp.net** (`Alt+J`, desktop) | the game's project on jumphp.net: publish (push + sync), its settings (title, descriptions, category, listed), statistics and players' messages, make its web site (`bin/jumphp site:new`), build the executables (Windows, Linux, macOS, Android) and put them on its page, the installer hosted on jumphp.net (it installs the newest version sent, with shortcuts and updates), or **Run > Create an Installer…** (also in Find Action): choose the systems (Windows, Linux, macOS) and where it goes — jumphp.net, a GitHub release of the project's repository, another web site (a `dist/installer/` folder to upload at the address given; `bin/jumphp installer`), or standalone (the game inside each installer: no site, no network; `bin/jumphp installer --standalone`) — then the links to give, the Android and Apple apps with the store steps one by one (links to Play Console, App Store Connect…) |

PhpStorm's keyboard shortcuts: Double Shift, `Ctrl+N`, `Ctrl+Shift+N`, `Ctrl+E`, `Ctrl+B`, `Ctrl+Alt+B`, `Alt+F7`, `Shift+F6`,
`Ctrl+F12`, `Ctrl+P`, `Ctrl+Q`, `Ctrl+Shift+F` / `R`, `Ctrl+K`, `Ctrl+Shift+K`, `Ctrl+T`, `Shift+F10`, `Ctrl+Shift+F10`, `Ctrl+F2`, `Shift+F9`, `F7` / `F8` / `F9`, `Ctrl+F8`,
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

### The IDE's tools for an AI (MCP)

Each `claude -p` the Claude window starts gets `--mcp-config` (a file only you can read, in the temporary folder) naming
the IDE's MCP server: HTTP on `127.0.0.1`, a random port, a random token (`Authorization: Bearer`), refused to web pages
(Origin). Its tools are allowed (`--allowedTools mcp__jumphp-ide`) and told to Claude (`--append-system-prompt`).

```
claude ──HTTP JSON-RPC──▶ McpServer (ide/src/Agent) ──agent.call {call, tool, args}──▶ ide/ui/js/agent.js
       ◀── tool result ─── IdeAgent (waits, timeout) ◀──── agent.reply {call, result | error} ───┘  (does it on screen)
```

| Tool | |
|---|---|
| `get_state` | open files, the active one with its cursor and selected text, tool windows, run configurations (ids), problems |
| `open_file` {path, line, end_line} | opens and selects |
| `highlight` {path, start_line, end_line, message} / `clear_highlights` | marks lines with a note (violet) until cleared |
| `show_diff` {path?} | the diff with HEAD, or the Commit window |
| `show_tool` {tool} | project, commit, run, find, problems, game, git… |
| `find_in_files` {query, regex, include} | shown in Find, the counts returned |
| `run` {config, wait} | a configuration id (`test:pong`) or `bin/jumphp test|shot|build|webshot|lint|run|app:test… <id>` (only bin/jumphp's, not any program): in the Run window; returns exit code, tests, screenshot, end of the output |
| `play` {path} | as the IDE's Play (a scene, a test file) |
| `diagnostics` {path?} | the IDE's PHP problems |
| `ask_user` {question, options} | a dialog; the answer (10 min at most) |
| `notify` {message, kind} | a notification |
| `debug_breakpoints` {path, lines, clear_all} | sets a file's breakpoints (lines, or `{line, condition}`), shown in the gutter; no path: the list |
| `debug_run` {config, timeout} | a configuration under the debugger (as Debug): waits until a breakpoint stops it, then where, the stack, the locals; or it ended / still runs |
| `debug_state` {frame} / `debug_variables` {name, frame} | where it stopped, a frame's variables, the children of one (`$this->player`) |
| `debug_step` {action} | step_over, step_into, step_out, run, stop: waits for the next stop |
| `debug_eval` {expression} | PHP evaluated where it stopped (shown in the Debug window too) |

Add one: its definition in `AgentTools::all()` (name, description, JSON schema, timeout), its `tool_<name>(args)` in
`AgentTools` of `agent.js`. Tests: `ide/tests/AgentTest.php` (the protocol over a real socket). The web IDE (Claude of
your computer through jumphp.net) has Follow, not the tools yet.

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

**Releases**: bump game.yaml `version`, then `games/ide/tools/release.sh "What is new."`. It runs
`bin/jumphp app:release ide --linux --windows --macos` (the executables + `update.json` signed with
`~/.jumphp/keys/ide-update.pem`: **keep a copy of that key**, without it the installed IDEs can never be updated again;
its public half is `games/ide/config/update.pub`, inside the IDE), attaches them to the GitHub release `ide-v<version>`
(with the installers of the previous release), checks every address answers, then commits and pushes
`games/ide/installer/update.json` and `version.json` on main. The installers already given read `version.json` there
and install the newest version: no need to remake them.

**The IDE updates itself** (`games/ide/src/SelfUpdate.php`): 8 s after it starts and every 6 hours it reads
`games/ide/installer/update.json` on main (game.yaml `app.update.url`), checks its signature, and when the version is
newer downloads it, checks its SHA-256 and puts it in place of its executable, in the background; a notification then
offers *Restart Now* (otherwise the next start runs it). Help > Check for Updates…, Help > Update Automatically (on by
default). Run from its sources it only says a newer version is out. `JUMPHP_UPDATE_URL` tries another update.json,
`JUMPHP_IDE_UPDATES=0` turns the checks off (tests). IDEs older than 0.1.3 have no updater: reinstall them once.
Not `tools/github-release.sh` (it points the installers to the repository's newest release, which is JumPHP's, not the IDE's).
Remaking them (another installer version, pictures, `install.yaml`): from a jumphp2installer clone, with the package
folder copied into it and its base installers in `bases/` (`gh release download installer-v… -p 'jumphp2installer-*'`):
`bin/jumphp php . tools/package-release.php <pkg> https://raw.githubusercontent.com/davidnurdin/jumPhp/main/games/ide/installer/ bases out`,
then `gh release upload ide-v<version> out/JumPHP-IDE-setup*`. `games/ide/tools/installer-banner.php` remakes its pictures (the banner, the icon, and `jumphp-ide.ico` for the Windows shortcuts).

The IDE's own files: `~/.local/share/ide` (exported) or `games/ide/var/` (session, recent projects, PHP index cache).
A project's IDE settings: `.jumphp/ide.json` (its jumphp.net project, a CPC disc path), kept out of git
(`.git/info/exclude`); custom run configurations: `.jumphp/run.json` (`[{"label": "…", "command": ["…"]}]`).

## Playground side

See the playground's README ("Le JumPHP IDE"): `bin/ide-install --from ../JumPHP` copies `ide/src` and `ide/ui` into it;
routes `/ide`, `/ide/<slug>`, `/ide/connect`, `/studio/ide`, `/api/ide/*` (Bearer `jpi_…`) and `/api/ide/<slug>/invoke`.

## Roadmap

The debugger for remote / browser games and from a terminal's Claude Code (a `bin/jumphp debug` MCP server), terminal tool window, PHP formatting, local history, more refactorings (extract
method / variable, move class), a tile map editor for games, multiple projects in one window, signed macOS / Windows executables.
