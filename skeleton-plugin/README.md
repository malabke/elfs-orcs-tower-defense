# TODO Finder — a plugin of the JumPHP IDE

Lists the TODO and FIXME comments of the project in a tool window, underlines FIXME as a warning, completes
`@todo`, and counts the TODOs of the open file (Tools > Count the TODOs of This File, `Ctrl+Alt+T`).

```bash
bin/jumphp install {{GAME_ID}}
bin/jumphp test {{GAME_ID}}           # php -l, phpstan, the plugin's tests (PluginTestCase: the IDE's core, no window)
bin/jumphp plugin:pack {{GAME_ID}}    # dist/{{GAME_ID}}-<version>.zip, to install or to publish on jumphp.net
```

Try it in the JumPHP IDE: Settings > Plugins > Install from the project. The guide: docs/ide-plugins.md.
