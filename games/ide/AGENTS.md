# games/ide: JumPHP IDE (desktop app)

The desktop host of the JumPHP IDE (docs/ide.md). A JumPHP desktop app (`ui: webview`, docs/tauri.md) whose pages are the
shared interface `ide/ui` (through Composer's path repository: `vendor/jumphp/ide/ui`) and whose core is `ide/src`
(`JumPHP\Ide\`). Edit the interface and the core in `ide/`, not here.

- `src/Main.php`: the one page command `ide` {name, payload}: session (`session.*`), projects (`projects.*`), jumphp.net
  (`playground.publish`), native dialogs (`native.open`, `native.openUrl`); everything else goes to the open project's
  `JumPHP\Ide\Ide`. Every frame: processes, the sign-in listener, the index, files changed outside (every 3 s).
- `src/Playground.php`: jumphp.net's `/api/ide/*` (Bearer `jpi_…`). `src/Loopback.php`: the sign-in's way back on
  127.0.0.1. `src/Creator.php`: New Project (games on jumphp.net; apps / hybrid games from the template + `bin/jumphp`).
- Tests: `tests/MainTest.php` (AppTestCase, a temporary project through `JUMPHP_IDE_PROJECT`); `bin/jumphp app:test ide`.
  The core's own tests: `bin/jumphp php ide vendor/bin/phpunit`.
- Look at it: `games/ide/tools/shot.sh var/screenshots/x.png 600 /workspace "<js>"` (the script can use
  `window.jumphpIde`, the workbench; `jumphp.send('log', …)` prints to the output). Open the PNG.
- Never block the frame (rule 2); never write in a project outside what the user asked; the IDE's notes about a project
  go to `.jumphp/ide.json` (excluded from git); no personal data in HTTP requests (the device name is generic).
