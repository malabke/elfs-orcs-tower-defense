# jumphp/ide

The JumPHP IDE's core (PHP, `JumPHP\Ide\`) and interface (`ui/`: HTML / CSS / JS with Monaco), shared by the desktop app
(`games/ide`) and the web IDE of jumphp.net. Documentation: [docs/ide.md](../docs/ide.md).

```bash
tools/fetch-vendor.sh                    # Monaco into ui/vendor/ (not in git)
bin/jumphp php ide vendor/bin/phpunit    # from the repository root: the core's tests
```

A host makes `new JumPHP\Ide\Ide($workspace, $cacheDir, $emit)`, calls `handle($name, $payload)` for the page's commands
(a `Deferred` when the answer comes later), `tick()` every frame, and serves `ui/` with `window.JUMPHP_IDE` (web) or
`window.jumphp` (desktop) for `ui/js/backend.js`.
