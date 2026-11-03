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
- the installers (jumphp2installer) offering a "start with the computer" box (the app does it: `$app->autostart()`,
  done; docs/tauri.md)
- services / daemons in PHP: an app (or a CLI app) that runs in the background without a user session: Windows
  service (SCM: start / stop / status, a service wrapper around the executable), macOS LaunchDaemon, Linux systemd
  unit (user or system); `bin/jumphp service:install / uninstall / status <id>`, the installers' step `service`,
  logs, restart on failure; the async runtime (threads) and JumPHP\Http for servers, tested on the three systems in CI

## Engine extensions (Composer packages that extend JumPHP, mostly for games)

Packages a game requires (`bin/jumphp composer <id> require vendor/pkg`) that add a whole subsystem to the engine,
with their own data format and, often, their own editor: a tile engine with its level editor and its map format, a
particular 3D engine (voxels, a raycaster, a mode-7 plane), a dialogue / quest system, a particle editor...
- first extensions to make (each one a package, its example game, its editor if it has data): a collision engine
  (shapes, polygons, broad phase), a gravity / physics engine (bodies, joints, forces), a particle engine (emitters,
  an editor with a live preview), a 3D engine (meshes, cameras, lights on OpenGL / WebGL), an SDL effects engine
  (transitions, screen shakes, palettes, scanlines, distortions), a video maker for TikTok / Shorts, a tile engine
  with its level editor
- the TikTok / Shorts video maker, above all for viral "satisfying" videos: simulations that make their own
  music (coloured balls bouncing inside circles that grow, shrink, open or rotate, each bounce plays the next
  note of a melody; balls that multiply, rings that break, races, marbles on a track, a timer and a question at
  the top: "will it escape?"). Made of: the physics (the collision / gravity extensions), a melody read from a
  MIDI file or notes (each collision = a note, Synth or samples), trails, glow and particles (the effects
  extensions), a vertical 1080×1920 view at 60 FPS, a seed so a video can be made again, many variations from
  one scene (colours, speeds, seeds) to post several. Rendered headless frame by frame (never in real time), the
  sound mixed from the collision log, out as an MP4 ready to post (H.264 + AAC, ffmpeg in Docker) with a title,
  captions and a cover; `bin/jumphp video:make <id> [--seed N] [--count 10]`. Also: a game's replay or a scripted
  scene to the same vertical MP4. Templates to start from (bouncing ball in a circle, escape the rings, ball
  race, colour fight); the IDE previews them live
- the contract: `"type": "jumphp-extension"` + `extra.jumphp` (services to register in the game's container, scenes,
  asset loaders for their formats, `config/*.yaml` keys with their schema, stubs), discovered by the engine at boot
  (no manual wiring), pure PHP so they run in the browser too (WebAssembly: no extensions of their own)
- their editors inside the IDE: an extension can ship an IDE plugin (docs/ide-plugins.md) that opens its files
  (its level format in a tool window / editor tab), its completion for its config keys, its run configurations
- `bin/jumphp extension:new <id>` (a skeleton with an example game and tests: GameTestCase), tested in CI on the
  desktop and in the browser build (webshot)
- published first as what they are: a GitHub repository and a Packagist package (versions = git tags, `composer
  require` works anywhere); jumphp.net only indexes them: a marketplace (like /ide-plugins: search, stats, marks,
  "Add to my project" = `composer require` + its config, from the site, the IDE's Plugins / Extensions window and
  `bin/jumphp extension:add <game> <vendor/pkg>`), filled from Packagist by their type (`jumphp-extension`)
- a project kind of its own: "JumPHP extension" (New Project in the IDE, `bin/jumphp extension:new`: a repository
  ready for Packagist, its example game, its tests, its IDE plugin), next to games, apps and IDE plugins
- the playground's shared engine loading the extensions a published game requires (today a game's own Composer
  packages are not supported there)

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

- the debugger, what is left (done in IDE 0.1.13 and 0.1.31: Xdebug, breakpoints with conditions, the Debug window,
  steps, watches, Run to Cursor, a variable's new value, stop at exceptions, Claude's debug_* tools in the IDE and in a
  terminal through `bin/jumphp debug`): games in the browser / jumphp.net/ide (no Xdebug in WebAssembly: a step
  debugger of its own?), the async runtime (PHP 8.6 ZTS image with Xdebug), desktop apps (Tauri, `ui: webview`:
  app:run uses the Tauri image, without Xdebug); try it on Windows / macOS (host.docker.internal)
- plugins, what is left (done in IDE 0.1.24-0.1.27: the PHP plugin API, docs/ide-plugins.md, plugin:new / plugin:pack,
  PluginTestCase, Tools > Plugins…, jumphp.net/ide-plugins: publish, install, update, plugins' tools for Claude on the
  IDE's MCP server, updates: checked every 6 hours,
  installed automatically if wanted; IDE 0.1.29: a plugin's own settings page): plugins in the web IDE (they would run on the server: a
  sandbox first)
- editors for a game's resources, inside the IDE: sprites (sheets, animations, palettes), music, sound effects (and
  tiles / levels): see how David's game site with editors does it (its public/js/editor-sprites.js, editor-music.js,
  editor-sfx.js, editor-tiles.js, editor-level.js, templates/editor/; ask David for the repository), made into IDE tool windows / editor
  tabs that read and write the game's assets/
- AI generation from these editors: for each kind (pictures, music, sound effects...) the online AIs that do it best
  (Claude among them), the user giving their own token / API key in the editor, then "generate" a sprite, a tune, an
  effect from a description, straight into the game's assets (a provider per service, the keys kept on the user's
  computer, never in the project)

## Windows: Docker through WSL, and a composer install that seemed to loop

- Docker inside WSL without Docker Desktop (Docker Engine installed in a WSL distribution: no Docker Desktop licence, often
  faster): when `docker info` does not answer on Windows but `wsl -e docker info` does, run bin/jumphp inside WSL
  (`wsl -e bash -lc 'cd "$(wslpath ...)" && bin/jumphp ...'`), from the IDE (JumPHP\Ide\Async\Docker: a "wsl" state,
  the Runner wraps the commands) and from bin/jumphp itself. To try on a real Windows: the project on C:\ seen as /mnt/c
  is slow in WSL (volumes in Docker even more): maybe offer to keep projects in the WSL file system (\\wsl$\...),
  Docker Desktop staying the default (it runs on WSL 2 already).
- Reported on Windows (2026-11-02): `composer install` of a new project seemed to run forever. Found (2026-11-03, on
  GitHub's Windows, workflow ide-windows): the IDE could not start bin/jumphp at all on Windows (a bash script: nothing
  ran it), and before that it waited for Docker (up to 4 min while Docker Desktop starts). Fixed in IDE 0.1.30: bin/jumphp
  and `sh -c` go through Git for Windows' bash, a stopped run ends its whole tree. Left: try it on a real PC with
  Docker Desktop (GitHub's Windows has no Linux containers): the first `composer install` of a new project, Run, Test.

## jumphp.net: big downloads, to watch

Seen 2026-11-02: two downloads of the installers out of six stopped half way through Cloudflare, ~0.4-3 MB/s.
Tried again the same evening: three downloads whole (Linux 5.4 MB/s, macOS 1 MB/s, Windows 0.4 MB/s). Likely
transient (David). The executables stay behind Cloudflare. If it comes back: a cache rule on /installers/download/
(the download counted another way), FrankenPHP's file server instead of PHP streaming them, Range requests.
