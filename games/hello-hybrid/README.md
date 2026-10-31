# hello-hybrid

An SDL game that also drives Tauri (`ui: hybrid`): an HTML control panel beside the game's window, the system's
file chooser and dialogs, a tray icon, a global shortcut. See `docs/tauri.md` ("Hybrid games").

```bash
bin/jumphp run hello-hybrid           # the game + its panel (Tauri image)
bin/jumphp test hello-hybrid          # headless: a plain game there, + the native side without a window
bin/jumphp export:standalone hello-hybrid   # one Linux executable (SDL + FFI + the Tauri runtime)
```
