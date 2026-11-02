<?php

// bin/jumphp plugin:pack <plugin>: dist/<id>-<version>.zip of the plugin's folder (composer.json, src/, what the
// plugin reads at run time: not vendor/, var/, tests/, dist/, .git/), the folder at the zip's root.
$dir = realpath($argv[1] ?? '.');
$composer = json_decode((string)file_get_contents("$dir/composer.json"), true);
$meta = $composer['extra']['jumphp-ide'] ?? null;
if (!is_array($meta) || empty($meta['id']) || empty($meta['class'])) {
    fwrite(STDERR, "composer.json: extra.jumphp-ide needs id and class\n");
    exit(1);
}
$id = $meta['id'];
$version = $meta['version'] ?? '0.0.0';
@mkdir("$dir/dist", 0777, true);
$out = "$dir/dist/$id-$version.zip";
@unlink($out);
$zip = new ZipArchive();
if ($zip->open($out, ZipArchive::CREATE) !== true) {
    fwrite(STDERR, "cannot write $out\n");
    exit(1);
}
$skip = ['vendor', 'var', 'tests', 'dist', '.git', 'node_modules', 'composer.lock', 'phpstan.neon', '.phpunit.result.cache'];
$files = 0;
$add = function (string $path, string $name) use (&$add, $zip, $skip, &$files, $id): void {
    foreach (scandir($path) ?: [] as $entry) {
        if ($entry === '.' || $entry === '..' || ($name === '' && in_array($entry, $skip, true))) {
            continue;
        }
        $full = "$path/$entry";
        $rel = ltrim("$name/$entry", '/');
        if (is_dir($full)) {
            $add($full, $rel);
        } else {
            $zip->addFile($full, "$id/$rel");
            $files++;
        }
    }
};
$add($dir, '');
$zip->close();
printf("%s (%d files, %d KB)\n", $out, $files, intdiv((int)filesize($out) + 1023, 1024));
