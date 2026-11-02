# hello-plugin — a plugin of the JumPHP IDE

PHP that runs inside the JumPHP IDE. Read docs/ide-plugins.md of JumPHP (the API, the Ui nodes, the tests) first.

- `composer.json` `extra.jumphp-ide`: id, name, version, the plugin's class; `autoload` psr-4 (a namespace of its
  own: `JumphpPlugins\...`). No package of its own at run time: only PHP and the IDE's (nikic/php-parser, symfony/yaml).
- `src/TodoPlugin.php` implements `JumPHP\Ide\Plugin\Plugin`: `activate(Api $ide)` adds actions, tool windows,
  run configurations, completion, problems, listeners, timers.
- Never block: the plugin runs on the IDE's frame (no sleep, no long loops, no network waits).
- Tests: `tests/*Test.php` extend `JumPHP\Ide\Testing\PluginTestCase` (a project of a few files, the plugin loaded,
  `action()`, `toolText()`, `click()`, `submit()`, `complete()`, `problems()`, `save()`, `wait()`, `notifications()`,
  `opened()`). `bin/jumphp test hello-plugin` must pass.
