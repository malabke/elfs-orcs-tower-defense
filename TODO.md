# TODO

**Later (on hold): [TODO-AFTER.md](TODO-AFTER.md)** (signing).

**Urgent first: [TODO-URGENT.md](TODO-URGENT.md)** (what is left of the repository / publishing fixes), then [TODO-FIRST.md](TODO-FIRST.md).

## Desktop apps (Tauri): the next targets

Done: Linux, Windows, macOS runtime (workflow `tauri`), clipboard, tray, global shortcuts, menus, hybrid games (two
windows, or the game inside the Tauri window: `app.embed`), one-file export for Linux and Windows, an `.app` for
macOS (workflow `app-export` opens it on two Macs), updates (`app:release`, `JumPHP\Tauri\Updater`), the same app
in the browser and on phones (`bin/jumphp build <app>`, `JumPHP\Tauri\BrowserRuntime`). docs/tauri.md.

- Android / iOS as native apps for DESKTOP (HTML) apps, `ui: webview`: done 2026-11-04 (not committed yet): the app's
  browser build in the phone's WebView + a bridge (save, notify, open, share, clipboard) used by jumphp-app.js;
  `bin/jumphp android <app>` (tools/android/webview; tried in an emulator with hello-desktop: PHP answers, Save as opens
  the system dialog) and `bin/jumphp ios <app>` (tools/apple/webview/main.m, a WKWebView; NOT tried yet: run the
  apple workflow once on a webview app and fix). docs/tauri.md, android-native.md, apple.md.
- done (2026-11-04, not released): the installers offer a "start with the computer" box (project.yaml app.autostart;
  jumphp2installer's `autostart` step, needs its 0.2.9 release: bin/jumphp only shows the box when the base installers have it)
- services / daemons in PHP: done 2026-11-04 (JumPHP 0.5.6): project.yaml `service:`, `JumPHP\Service\Daemon`,
  `bin/jumphp service:install / uninstall / status / logs / start / stop / print` (tools/service.sh: systemd user or
  system units, launchd plists, WinSW on Windows), docs/services.md. Tried on Linux in a systemd container (user and
  system units, restart after kill -9, clean stop, logs, uninstall). Left: try macOS (launchd) and Windows (WinSW) for
  real, the installers' step `service`

## Engine extensions (Composer packages that extend JumPHP, mostly for games)

Done (2026-11-04, JumPHP 0.5.6): the contract (`type: jumphp-extension`, `extra.jumphp`: services, services_file, boot,
loaders, config defaults; found from installed.json at boot, in the browser build too), `bin/jumphp extension:new`
(skeleton-extension/) and `extension:add`, extensions/particles (+ games/particles-demo), extensions/physics and
extensions/shorts with `bin/jumphp video:make` (the TikTok / Shorts video maker, games/shorts-demo), published games on
jumphp.net getting their extensions (also for run_tests), the directory https://jumphp.net/extensions; docs/extensions.md,
docs/video-maker.md, AGENTS.md §20.

Left:
- the other extensions: a collision engine (shapes, polygons, broad phase), a 3D engine (meshes, cameras, lights on
  OpenGL / WebGL), an SDL effects engine (transitions, shakes, palettes, scanlines, distortions), a tile engine with its
  level editor, a dialogue / quest system; a particle editor with a live preview
- JumPHP's own extensions on Packagist (jumphp/particles, physics, shorts: 404 there today; each its own repository or a
  split of extensions/), so `composer require` works outside a JumPHP checkout
- an extension shipping its IDE plugin (its files in a tool window / editor tab, completion of its config keys)
- "JumPHP extension" as a project kind in the IDE's New Project; extension:new tested in CI (desktop + webshot)
- the video maker: captions beyond title / caption / timer, a game's replay to MP4, the IDE previewing templates live

## CLI apps on jumphp.net: what is left

Done (2026-11-04, JumPHP 0.5.6): questions, hidden answers, Ctrl+C and full-screen symfony/tui screens in the web terminal
(PHP in a Web Worker waiting on a SharedArrayBuffer).
- browsers without Document-Isolation-Policy (Firefox, Safari): inside the playground's frame they get a link to a tab of
  its own; try them for real
- fibers in the WebAssembly engine (PHP's ucontext fallback: `getcontext` is missing) would let Revolt's own drivers run
- an app making its own `new SymfonyStyle` / `new QuestionHelper` still shows hidden answers (no signal from Symfony)

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
`bin/jumphp apple`).

Done already: the HTML / JS / CSS layer on the desktop and in the browser (desktop apps, Tauri: WebKitGTK, WebView2,
WKWebView; §16), apps drawn natively without HTML (JumPHP\Gui, §17), CLI apps (§18), services, and the IDE itself
(JumPHP IDE, a JumPHP app with Claude inside). Left: the systems' own APIs through FFI, and the HTML layer on phones
(Android WebView / WKWebView: the "native Tauri apps" item above).

- every system's APIs reachable from PHP through FFI: Android (JNI / NDK: sensors, camera, notifications, files,
  sharing...), Windows (Win32, WinRT), Linux (D-Bus, GTK...), macOS / iOS (Objective-C runtime, UIKit / AppKit),
  as PHP classes generated or written over them, the same names everywhere when the systems agree
- an option to embed an HTML / JS / CSS layer for the app's visual interface (the system's web view: Android
  WebView, WebView2, WebKitGTK, WKWebView), talking with the PHP side (calls and events both ways); without it, the
  interface drawn natively (SDL / OpenGL, or the system's widgets through FFI)
- in the long run: an IDE (a software, itself a JumPHP app) to develop every kind of app: the project, the code,
  run / test / package for each system, Claude inside

## JumPHP IDE: the next features

- in progress (2026-11-04, stopped by the usage limit, resume on David's word): Xdebug on a TUI app + Run / Debug icons
  on the line (the two items below); its scratch app is apps/zz-tui (delete it when done)
- detailed logs, readable inside the IDE: a Logs view (e.g. under Help) following them live like `tail -f -s 0`, but
  visual: every source (the IDE itself, bin/jumphp runs, Docker, Claude, plugins, the game / app run), levels, filter,
  search, pause / follow, copy, open the file; the same logs kept on disk for bug reports
- "What's new": a section in the IDE linked to the site (and a page on jumphp.net), filled at each new feature (a
  changelog entry written with the feature: title, short text, picture or GIF, the version), shown once after an
  update, then reachable from Help; the site's page in the 14 languages. Make it part of the definition of done
- an IDE for phones: a light version (maybe a "soft" one) that drives Claude, either on the phone itself (not sure it
  can) or a computer's Claude through Remote Control (`/rc`): to study
- reported by David (2026-11-04): a TUI project (CLI app) runs, but started under Xdebug it does not stop at a breakpoint:
  find why (the CLI app's run path with JUMPHP_XDEBUG: `bin/jumphp run` of a cli app, the Terminal's Run, path mapping
  of apps/<id>, xdebug.start_with_request in that container) and fix it, tested with a real DBGp session
- a Run and a Debug (Xdebug) icon in the editor's gutter, on the line: run / debug the program from there with its
  arguments (a CLI app's command and options typed or remembered per line), like the run configurations

- the debugger, what is left (done in IDE 0.1.13 and 0.1.31: Xdebug, breakpoints with conditions, the Debug window,
  steps, watches, Run to Cursor, a variable's new value, stop at exceptions, Claude's debug_* tools in the IDE and in a
  terminal through `bin/jumphp debug`; 2026-11-04: the async runtime (`jumphp/desktop-async-xdebug`, Xdebug's
  development branch at a fixed commit: the 3.5 releases stop below PHP 8.6; TrueAsync's threads crash under Xdebug,
  so `Tasks` runs the jobs between frames there, where breakpoints work) and desktop apps (`jumphp/tauri-xdebug`:
  app:run / app:test), both tried with a real DBGp session): games in the browser / jumphp.net/ide (no Xdebug in
  WebAssembly: a step debugger of its own?); try it on Windows / macOS (host.docker.internal), and
  `bin/jumphp debug` there too (only tried on Linux: there its container listens on 0.0.0.0 with the port published)
- plugins, what is left (done in IDE 0.1.24-0.1.27: the PHP plugin API, docs/ide-plugins.md, plugin:new / plugin:pack,
  PluginTestCase, Tools > Plugins…, jumphp.net/ide-plugins: publish, install, update, plugins' tools for Claude on the
  IDE's MCP server, updates: checked every 6 hours,
  installed automatically if wanted; IDE 0.1.29: a plugin's own settings page): plugins in the web IDE: done (2026-11-04, JumPHP 0.5.6 / IDE 0.1.38), run in the visitor's
  browser on the WebAssembly PHP (a Web Worker), writes through the web IDE's commands; MCP tools stay desktop only
- done (2026-11-04, JumPHP 0.5.6 / IDE 0.1.38): the IDE's resource editors: sprites (PNG), sound effects (.sfx.json -> .wav), music
  (piano roll -> .wav), tilesets (.tsj with collisions) and levels (.tmj: layers, objects). Left: animated / flipped tiles
- done (2026-11-04, JumPHP 0.5.6 / IDE 0.1.38): AI generation in the resource editors ("Generate…": Claude through the local
  `claude -p` for sprites, sounds, music and levels; OpenAI Images with the user's key for sprites). Left: other sound /
  music services, tilesets
- done (2026-11-04, JumPHP 0.5.6 / IDE 0.1.38): Claude's usage in the IDE's Claude pane: the open conversation's context, the 5-hour
  and the weekly limits with when they start again (from claude's rate_limit_event and its usage)

## Windows: Docker through WSL, and a composer install that seemed to loop

- done (2026-11-04, not tried on a real Windows yet): Docker inside WSL without Docker Desktop (tools/wsl.sh, the IDE's
  Docker state `wsl`, docs/windows-wsl.md; the ide-windows workflow runs its tests). Before:
  Docker inside WSL without Docker Desktop (Docker Engine installed in a WSL distribution: no Docker Desktop licence, often
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

## Release follow-ups (2026-11-04)

- iOS app of a desktop (HTML) app: done (2026-11-04): the link needed `-framework CoreGraphics` (tools/apple/build.sh); run 41907004407 on
  hello-desktop passes and the simulator shot shows the app with PHP answering. Left: the HTML menu bar sits under the notch / status bar
  (use `env(safe-area-inset-top)` in jumphp-app.js's bar on phones), try the .ipa on a real iPhone (needs the Apple account)
- jumphp2installer 0.2.9: released (2026-11-04, the workflow re-run): the "start with the computer" box should show now; check it in an installer

## jumphp.net: big downloads, to watch

Seen 2026-11-02: two downloads of the installers out of six stopped half way through Cloudflare, ~0.4-3 MB/s.
Tried again the same evening: three downloads whole (Linux 5.4 MB/s, macOS 1 MB/s, Windows 0.4 MB/s). Likely
transient (David). The executables stay behind Cloudflare. If it comes back: a cache rule on /installers/download/
(the download counted another way), FrankenPHP's file server instead of PHP streaming them, Range requests.

## Projects: progress, branches, sharing

- done (2026-11-03, JumPHP 0.5.6 / IDE 0.1.38): a project's progress set by its owner (`stage`: dev / beta / done; jumphp.net
  Studio, `update_project stage`, the IDE's Publish > Settings); "ready" is now shown as "published"
- done (2026-11-03, JumPHP 0.5.6 / IDE 0.1.38): `/rc` (or `/remote-control`) in the IDE's Claude pane: the conversation goes on
  in the Terminal as `claude --remote-control --resume <session>`
- done (2026-11-04, IDE 0.1.38): the IDE: branches and merges (create, switch, merge, conflicts: ours / theirs / both)
- done (2026-11-04, JumPHP 0.5.6): a project shared with several users, each with read or read + write rights
  (jumphp.net Studio, web IDE read only, MCP, the IDE). Left: try it with the real GitHub sign-in
