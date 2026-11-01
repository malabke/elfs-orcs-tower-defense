# {{GAME_ID}}

A graphic PHP app (no HTML): `JumPHP\Gui` forms, made from `skeleton-gui/` (`bin/jumphp gui:new`). Guide: `docs/gui.md`
of JumPHP (AGENTS.md §17).

- `forms/MainForm.form.yaml`: the main window's widgets (the IDE's visual editor writes it; YAML by hand too).
- `src/Form/MainForm.php`: the code behind it; `events: {click: onAddClick}` in the form file = the method called.
  Keep one `@property <Widget> $<id>` docblock line per widget id.
- `config/game.yaml`: `kind: gui`, `view: window`, `touch: false`.
- Tests: `tests/MainFormTest.php` (GameTestCase: click, type, check). `bin/jumphp test {{GAME_ID}}`.

To do: replace the sample contacts window with the app.
