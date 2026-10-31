# hello-hybrid — notes for agents

A **hybrid** game (`config/game.yaml`: `ui: hybrid`, `main: App\Desktop`): an SDL game (read `../../AGENTS.md`)
that also drives Tauri (read `../../docs/tauri.md`, "Hybrid games").

- `src/Scene/BallsScene.php`: the game (balls bouncing). Uses `JumPHP\Native` (FilePicker::save for S, Dialog::confirm
  for Escape) when `Native::supports()` says so; headless it is a plain game (Escape quits).
- `src/Desktop.php` (`AppMain`): the panel's commands (`settings`, `set`, `shot`), the tray icon, the global shortcut.
- `src/Settings.php`: the state both sides share (static).
- `ui/`: the HTML panel (second window).
- Tests: `tests/HybridTest.php` (GameTestCase: the game), `tests/DesktopTest.php` (AppTestCase: the native side).
