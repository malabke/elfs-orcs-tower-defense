<?php

// A JumPHP CLI app: `php app.php <command>` (bin/jumphp run <app> -- <command>), or one executable once exported
// (bin/jumphp export:standalone <app>: the command's arguments come after its name). docs/cli.md.
require __DIR__ . '/vendor/autoload.php';

exit(App\Main::boot(__DIR__)->run());
