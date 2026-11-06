# Skeleton Extension

What this extension brings to a game, and how to use it. This file is its documentation: `bin/jumphp extension:add` copies it into the
game (`extensions/skeleton-extension/doc/`) and the JumPHP IDE shows it, with a **Play** button for each sample.

## Use it

```php
use JumphpExtensions\SkeletonExtension\Flash;

public function __construct(private Flash $flash) {}       // a scene asks for the service

$this->flash->flash('#ff4040');                            // light the screen, it fades
// then update($dt) in update(), render($r) last in render()
```

## Settings

Say here the project.yaml keys it reads (`extra.jumphp.config` of its composer.json) and what they do.

## Samples

`doc/samples/FlashSample.php` is a scene of the game that has the extension (namespace `App\Extensions\SkeletonExtension`): press Enter, Space
or click to flash the screen. Write one sample for each thing the extension does: authors read them before they read this page.
