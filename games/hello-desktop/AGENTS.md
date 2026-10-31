# hello-desktop — notes for agents

This is a JumPHP **desktop app** (not an SDL game). **Read `../../AGENTS.md` §16 and `../../docs/tauri.md` first.**

## Checklist

```bash
bin/jumphp app:test hello-desktop       # php -l + phpunit (AppTestCase, no window) + the app on Xvfb (must pass)
bin/jumphp app:shot hello-desktop       # -> games/hello-desktop/var/screenshots/app.png: open it and look
bin/jumphp app:export hello-desktop     # -> games/hello-desktop/dist/standalone/hello-desktop-linux-x86_64
```

## This app

- **What it is**: the example desktop app of JumPHP (Tauri): a notepad. The page is HTML (`ui/`), the logic is PHP (`src/Main.php`): open / save with the system dialogs, a PHP clock pushed to the page, dropped files described by PHP, a question before quitting with unsaved text, the built-in `native.*` commands (message, confirm, folder, notification).
- **PHP side**: `src/Main.php` (`AppMain::boot()`: commands, listeners, timers).
- **Pages**: `ui/` (`index.html`, `style.css`, `app.js`); `window.jumphp` is injected in every page.
- **Commands** the pages call: `info`, `greet`, `notes.changed`, `notes.open`, `notes.save`, `notes.saveAs`, `notify`; plus the built-in `native.*` (`JumPHP\Tauri\NativeCommands`).
