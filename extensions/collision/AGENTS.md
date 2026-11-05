# collision — notes for agents

A JumPHP engine extension (read `../../AGENTS.md`, section "Engine extensions", and `../../docs/extensions.md`).

- `composer.json` `extra.jumphp`: what the engine registers (services, boot, loaders, config keys with defaults).
- Pure PHP only (the browser build packs it): no extension of PHP, no blocking call, 32-bit integers.
- Its example game `games/collision-demo` requires it through a path repository: develop both together,
  `bin/jumphp test collision-demo` (game tests of the extension), `bin/jumphp shot`, `bin/jumphp build` + `webshot`.
