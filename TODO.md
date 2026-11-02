# TODO

## Desktop apps (Tauri): the next targets

Done: Linux, Windows, macOS runtime (workflow `tauri`), clipboard, tray, global shortcuts, menus, hybrid games (two
windows, or the game inside the Tauri window: `app.embed`), one-file export for Linux and Windows, an `.app` for
macOS (workflow `app-export` opens it on two Macs), updates (`app:release`, `JumPHP\Tauri\Updater`), the same app
in the browser and on phones (`bin/jumphp build <app>`, `JumPHP\Tauri\BrowserRuntime`). docs/tauri.md.

- Android / iOS as native Tauri apps (the browser version runs there already, Android through `bin/jumphp twa`):
  Tauri's mobile loop is driven by the activity / UIKit, so PHP has to be embedded in the app (the PHP builds of
  `docker/android-native` / the Apple apps) and called from there (`run_return` does not exist on iOS)
- signed apps: Authenticode (Windows), Developer ID + notarization (macOS) with the developer's certificates
- a .dmg for macOS (the zip of the .app works; `hdiutil` only exists on a Mac: the workflow could make it)

## Apple apps: signing, TestFlight, the App Store (needs the Apple developer account)

`bin/jumphp apple <game>` (docs/apple.md) builds the macOS app (.dmg, Apple silicon + Intel), the iPhone / iPad
app (.ipa) and the simulator's app on GitHub's Macs, and tries them there; they are signed ad hoc. With the account
(99 $ a year):

- macOS: Developer ID certificate in the repository's secrets, `codesign --options runtime` + `notarytool` +
  `stapler` in the workflow: opens without the right click
- iOS: distribution certificate + provisioning profile (or automatic signing with an App Store Connect API key),
  the .ipa signed, uploaded to TestFlight (`xcrun altool --upload-app` / the App Store Connect API), then the store
  listing (screenshots: the simulator's, as check.sh takes them)
- a playground "Apple" section (bundle id, as the Android one) when the App Store is reached

## Without PHP at run time: illegalstudio (to study)

Try a flag (e.g. `bin/jumphp export:standalone <game> --native`) that builds the game with
[elephc](https://github.com/illegalstudio/elephc) (illegalstudio) instead of embedding PHP: a compiler of a static
subset of PHP to native code, no Zend Engine (from its README, nothing tried yet).

What helps:
- modern PHP: classes, interfaces, traits, enums, closures, generators, namespaces, Fibers, Composer's autoload
  resolved when compiling; GD inside; C libraries through `extern` (its DOOM showcase: a game in compiled PHP)
- probably much faster than the interpreter, and a smaller executable

What blocks or needs work:
- platforms: executables for Linux x86_64 / ARM64 and macOS ARM64, static libraries for iOS; no Windows, no
  Android. So a variant for Linux (and maybe a lead for the iPhone app), not a replacement of PHP
- JumPHP's extensions do not exist there (php-sdl, PHP-OpenGL, PHP-OpenAL): the ~70 SDL / Mix / TTF / IMG functions
  the engine calls, and the GL / AL ones, declared again as `extern` with the same names (the engine barely
  changes); its C callbacks take no strings yet
- the engine's dynamic PHP: Symfony DependencyInjection (Reflection, autowiring: a container generated beforehand
  as plain PHP), laravel/serializable-closure (the worker threads: left out in this mode), `new $class()` for the
  scenes and ReactPHP to check
- the players' games are written freely (often by Claude): some will not compile, so the flag tries, falls back to
  PHP and says what blocked

First step: Pong or hello-world compiled by elephc on Linux, to see what passes and the speed gained. PHP stays
the default (Windows, Android).

### Tried (2026-11-01, elephc 0.27.6 built from its sources, Linux x86_64, in Docker)

- **Speed**, same script, compiled vs PHP 8.5 (JIT off): 100 balls x 100 000 steps of physics (objects, floats)
  **0.63 s vs 1.69 s (2.7x faster)**; but 10 000 x 1 000 additions over an integer array **1.84 s vs 0.19 s
  (10x slower)**: its arrays are slower than PHP's. Executable: 0.9 MB, static.
- **SDL through `extern`** works: SDL2 declared in PHP (`extern "SDL2" { function SDL_Init(...)... }`), a software
  renderer, 600 frames, events polled, pixels read back right. The engine's SDL calls could be declared this way.
- **Engine classes**: `Util\Math` and `Physics\Aabb` compile unchanged and print exactly PHP's results;
  `Util\Color` stops at `public static function of(...): self` ("expects Color, got self").
- **Language**: namespaces, interfaces, traits, abstract classes, enums, generators, closures with `use`,
  `new $class()`, `match`, `$fn()` / `$obj->$m()` / `call_user_func`, `json_encode`, `sprintf`: pass.
  Refused: references (`&$a['x']`), `pack()` / `unpack()` (JumPHP's network messages), `spl_object_id()`, a closure
  `fn (string $c): Scene => new $c()` (return type "mixed"), `: self`; regex needs its native package (pcre2);
  `$this->listeners[$ev] ?? []` warns "Undefined array key" where PHP does not.
- Not tried: Symfony DependencyInjection, ReactPHP, a whole game.

**Verdict**: not yet for whole games: the engine relies on what is refused (references, pack, `self`, DI,
ReactPHP), and array-heavy code (tile maps, collisions) would be slower. Worth following: plain classes compile
as they are, and objects / floats run ~3x faster. Next step when elephc grows: a "native" build of the engine's
pure parts (physics, tile collisions) as a PHP extension (`--emit phpext`), PHP keeping the rest.

## Full native apps in PHP: every system's APIs, an optional HTML / JS / CSS layer, an IDE (likely, by David)

Beyond games: any kind of app written in PHP, with the same packaging (`bin/jumphp android`, `export:standalone`,
later iOS).

- every system's APIs reachable from PHP through FFI: Android (JNI / NDK: sensors, camera, notifications, files,
  sharing...), Windows (Win32, WinRT), Linux (D-Bus, GTK...), macOS / iOS (Objective-C runtime, UIKit / AppKit),
  as PHP classes generated or written over them, the same names everywhere when the systems agree
- an option to embed an HTML / JS / CSS layer for the app's visual interface (the system's web view: Android
  WebView, WebView2, WebKitGTK, WKWebView), talking with the PHP side (calls and events both ways); without it, the
  interface drawn natively (SDL / OpenGL, or the system's widgets through FFI)
- in the long run: an IDE (a software, itself a JumPHP app) to develop every kind of app: the project, the code,
  run / test / package for each system, Claude inside

## JumPHP IDE: the next features

- the debugger, what is left (done in IDE 0.1.13: Xdebug in docker/desktop-xdebug through `JUMPHP_XDEBUG`, gutter
  breakpoints with conditions, Debug tool window, steps, watches, Claude's debug_* tools in the IDE): a `bin/jumphp
  debug` MCP server for Claude Code in a terminal (the same tools, ide/src/Debug/Debugger.php reused), the async
  runtime (PHP 8.6 ZTS image with Xdebug), games in the browser / jumphp.net/ide (no Xdebug in WebAssembly: a
  step debugger of its own?), desktop apps (Tauri, `ui: webview`: app:run uses the Tauri image, without Xdebug),
  exception breakpoints, "run to cursor", setting a variable's value; try it on
  Windows / macOS (host.docker.internal)
- plugins to extend the IDE: a plugin API (tool windows, actions and menus, run configurations, editor features,
  PHP side and interface side), plugins developed and tested like any JumPHP project, and a plugin marketplace on
  jumphp.net (publish, versions, install / update / remove from the IDE)
- editors for a game's resources, inside the IDE: sprites (sheets, animations, palettes), music, sound effects (and
  tiles / levels): see how David's game site with editors does it (its public/js/editor-sprites.js, editor-music.js,
  editor-sfx.js, editor-tiles.js, editor-level.js, templates/editor/; ask David for the repository), made into IDE tool windows / editor
  tabs that read and write the game's assets/
- AI generation from these editors: for each kind (pictures, music, sound effects...) the online AIs that do it best
  (Claude among them), the user giving their own token / API key in the editor, then "generate" a sprite, a tune, an
  effect from a description, straight into the game's assets (a provider per service, the keys kept on the user's
  computer, never in the project)

## JumPHP IDE: autocompletion, what is left

Done (IDE 0.1.9): generics (`@template`, `@extends`, `class-string<T>`), array shapes and their keys, foreach over
collections, the libraries' functions and classmaps, the stubs' functions, `config/game.yaml` and `config/input.yaml`.
Left: `services.yaml` / form files / `lang.yaml`, the bin/jumphp commands (Run configurations, terminal), a background
index of a whole vendor/ (names come from file names today, members when a class is first used).

## CLI apps: what is left

Done: `bin/jumphp cli:new` (skeleton-cli/, docs/cli.md, games/hello-cli), run / test / shot (the screen as text) /
export:standalone (Linux, Windows with its console, macOS executables), the IDE's New Project and Run configurations.
Left: a page on jumphp.net's docs (14 languages), full-screen screens on Windows without `stty` (symfony/tui reads
the terminal with it), a macOS installer for CLI apps (executables given as they are today).

## Windows: Docker through WSL, and a composer install that seemed to loop

- Docker inside WSL without Docker Desktop (Docker Engine installed in a WSL distribution: no Docker Desktop licence, often
  faster): when `docker info` does not answer on Windows but `wsl -e docker info` does, run bin/jumphp inside WSL
  (`wsl -e bash -lc 'cd "$(wslpath ...)" && bin/jumphp ...'`), from the IDE (JumPHP\Ide\Async\Docker: a "wsl" state,
  the Runner wraps the commands) and from bin/jumphp itself. To try on a real Windows: the project on C:\ seen as /mnt/c
  is slow in WSL (volumes in Docker even more): maybe offer to keep projects in the WSL file system (\\wsl$\...),
  Docker Desktop staying the default (it runs on WSL 2 already).
- Reported on Windows (2026-11-02, before Docker was started by the IDE): `composer install` of a new project seemed to
  run forever (its status bar task stayed, nothing in the Run window). Likely Docker not answering (bin/jumphp and the IDE
  now wait for it, 20 s per check, and say so); check again on Windows with IDE 0.1.12: if it still happens, look at the
  Run window's output with files on Windows (ide/src/Async/Processes.php) and at a `docker build` of the desktop image
  that prints nothing for minutes (the first time: say it in the Run window).

## jumphp.net: big downloads are slow and sometimes cut

Seen 2026-11-02: the installers handed out by jumphp.net (/installers/download/<id>/<platform>, 20-32 MB) come at
~0.4-3 MB/s through Cloudflare, and two downloads out of six stopped half way (curl: partial file). Decided
(2026-11-02): the executables stay behind Cloudflare (no download host around it). Within that: Cloudflare caching
them (a cache rule on /installers/download/, the download counted another way), X-Sendfile / FrankenPHP's file
server instead of PHP streaming them, Range requests through Cloudflare so a cut download resumes.

## Cleaning

- the GitHub pre-release `standalone-1460857eb89286a9`: its Linux micro needs AVX-512 (built for GitHub's
  processor, before docker/standalone/spc-target); no key points to it any more
