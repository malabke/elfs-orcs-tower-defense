<?php

// A JumPHP desktop app: native windows with HTML / CSS / JS pages, driven by PHP (Tauri).
// bin/jumphp app:run <app> runs it; bin/jumphp app:export <app> makes it something to give (docs/tauri.md).
require __DIR__ . '/vendor/autoload.php';

exit(JumPHP\Tauri\App::boot(__DIR__)->run());
