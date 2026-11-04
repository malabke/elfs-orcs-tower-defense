# Skeleton Extension

A JumPHP **engine extension** (docs/extensions.md of JumPHP): a Composer package of type `jumphp-extension`. A game
that requires it gets its services, its boot class, its file loaders and its project.yaml keys, with nothing to wire.

```php
public function __construct(private Flash $flash) {}        // in a scene
$this->flash->flash('#ff4040');                            // then update($dt) / render($r) each frame
```

Example game: `games/skeleton-extension-demo`. Publish it: a GitHub repository + Packagist (rename the package in
composer.json first), then any game `bin/jumphp composer <game> require <vendor>/<package>`.
