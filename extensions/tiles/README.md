# Tiles

A JumPHP **engine extension** (docs/extensions.md of JumPHP): a Composer package of type `jumphp-extension`. A game
that requires it gets its services, its boot class, its file loaders and its project.yaml keys, with nothing to wire.

```php
public function __construct(private Flash $flash) {}        // in a scene
$this->flash->flash('#ff4040');                            // then update($dt) / render($r) each frame
```

Example game: `games/tiles-demo`.

## Install (for the games that use it)

```bash
bin/jumphp extension:add <game> <vendor>/<package>      # composer require + checks it is a jumphp-extension
```

## Make it yours

- `composer.json`: rename `name` (`<your-vendor>/<package>`), fill `description`, `license`, `homepage`, `support`;
  keep `"type": "jumphp-extension"` and the keyword `jumphp-extension` (jumphp.net/extensions lists the Packagist packages that have it).
- `extra.jumphp.engine`: the JumPHP versions it works with (`"^0.5"`, `">=0.5 <0.7"`): a game on another version stops at
  boot with a clear message instead of failing somewhere inside. Widen it once you tried the newer JumPHP.
- Do not require `jumphp/engine` in `require` (every JumPHP project has it already); pure PHP only (the browser build packs it).
- `doc/index.md`: its documentation, and `doc/samples/*.php`: one scene for each thing it does (namespace `App\Extensions\<Name>`, a
  `public const LABEL`). `bin/jumphp extension:add <game> <package>` copies them into the game (`extensions/<name>/doc/`,
  `src/Extensions/<Name>/`), and the JumPHP IDE opens the documentation with a Play button per sample.
- Tests: the example game's `tests/` play the extension (`bin/jumphp test <id>-demo`). Write one per feature.

## Publish it

1. A public GitHub repository with this folder's content at its root (`composer.json` at the top).
2. A tag: `git tag v1.0.0 && git push --tags` (Packagist's versions are the tags; follow semver).
3. https://packagist.org/packages/submit with the repository's address (sign in with GitHub, Packagist then installs its
   hook: each new tag is a new version).
4. It appears in https://jumphp.net/extensions by itself (it reads the Packagist packages of type `jumphp-extension`).
5. Show it: a README with a picture, the example game's screenshot (`bin/jumphp shot`), a CHANGELOG.
