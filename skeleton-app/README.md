# {{GAME_ID}}

A JumPHP desktop app: native windows whose pages are HTML / CSS / JS, driven by PHP (Tauri).

```bash
bin/jumphp app:run {{GAME_ID}}       # a window on your screen
bin/jumphp app:test {{GAME_ID}}      # lint + phpunit + the app for real on a virtual screen
bin/jumphp app:shot {{GAME_ID}}      # var/screenshots/app.png
bin/jumphp app:export {{GAME_ID}}    # dist/standalone/{{GAME_ID}}-linux-x86_64: the app for Linux, one file to give
```

See `docs/tauri.md` of JumPHP.
