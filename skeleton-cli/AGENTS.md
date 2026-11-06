# {{GAME_ID}} — notes for agents

This is a JumPHP **CLI app**: it lives in the terminal (no window, no SDL). Commands are Symfony Console commands,
full-screen screens are symfony/tui widgets. **Read `../../docs/cli.md` first.**

## Checklist

```bash
bin/jumphp test {{GAME_ID}}                        # php -l + phpstan + phpunit (commands, screens on a VirtualTerminal) + smoke
JUMPHP_KEYS='[[1,"a"],[1.3,"Milk"],[1.6,"\r"]]' bin/jumphp shot {{GAME_ID}} 2.5   # the screen as text: read it
bin/jumphp run {{GAME_ID}} add "Something"         # a command (bin/jumphp run {{GAME_ID}}: the full-screen screen)
bin/jumphp export:standalone {{GAME_ID}} --linux --windows --macos   # one executable per system, in dist/standalone/
```

## This app

- **What it is**: ...
- **Entry**: `app.php` -> `App\Main::boot($root)->run()` (the exported executable calls the same).
- **Commands**: `src/Command/` (one class each, `#[AsCommand]`), registered in `src/Main.php`.
- **Screens**: `src/Ui/` (symfony/tui: `Tui`, `SelectListWidget`, `InputWidget`, `TextWidget`...); tests drive them on a
  `VirtualTerminal` (`tests/TaskScreenTest.php`).
- **Data**: `Main::boot()` gives the data folder: `var/` here, the user's data folder once exported (`JUMPHP_VAR`).

## Rules

- No SDL / engine scenes here; never `sleep()` in a screen (the TUI has its own loop: `scheduleInterval()`).
- Full-screen screens need a terminal: when the output is not one (a pipe), fall back to plain output (`Main`).
- Windows: commands work in any console; full-screen screens need a terminal with `stty` (Windows Terminal + Git
  Bash / WSL): test the commands there, keep everything reachable as commands.
