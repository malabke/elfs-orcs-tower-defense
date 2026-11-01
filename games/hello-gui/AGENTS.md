# hello-gui

The reference graphic PHP app (no HTML): `JumPHP\Gui` forms, made from `skeleton-gui/` (`bin/jumphp gui:new`).

- `forms/MainForm.form.yaml`: the window's widgets (the IDE's visual editor writes it; plain YAML by hand too).
- `src/Form/MainForm.php`: the code behind it. `events: {click: onAddClick}` in the form file = the method called.
  The `@property` lines of its docblock are kept in step with the widgets by the visual editor.
- Contacts are saved in `var/contacts.json` (`$this->engine->storage`).
- `config/game.yaml`: `view: window` (the view is the window, pixel for pixel), `kind: gui`, `touch: false`.
- Layout: a `grid` of two panels, `span: {xs: 12, md: 5}` / `{xs: 12, md: 7}`: side by side on a big window,
  one under the other on a phone; the subtitle has `hide: [xs]`.
- Tests: `tests/MainFormTest.php` (GameTestCase): type, click, check the list.
