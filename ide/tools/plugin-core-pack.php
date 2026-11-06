<?php
// The web IDE's plugins run in the visitor's browser (ide/ui/js/plugin-worker.js): this packs what their PHP needs
// into one file the worker unpacks: the IDE's core (ide/src) and its pure-PHP packages, with an autoloader.
// Same format as tools/pack.php ("JPK1", header length uint32 LE, JSON header {"files": [[path, offset, size]]}, bytes).
//   php ide/tools/plugin-core-pack.php <ide/src> <vendor> <out.pack>
// jumphp.net runs it from bin/ide-install (out: public/ide-ui/plugin-core.pack).

[, $src, $vendor, $out] = $argv + [null, null, null, null];
if (!$src || !$vendor || !$out || !is_file("$src/Ide.php") || !is_dir($vendor)) {
    fwrite(STDERR, "usage: php plugin-core-pack.php <ide/src> <vendor> <out.pack>\n");
    exit(1);
}

// prefix => folder in the pack; the files PHP must load at once (functions, polyfills)
$packages = [
    'JumPHP\\Ide\\' => [rtrim($src, '/'), 'ide/src'],
    'PhpParser\\' => ["$vendor/nikic/php-parser/lib/PhpParser", 'vendor/php-parser'],
    'Symfony\\Component\\Yaml\\' => ["$vendor/symfony/yaml", 'vendor/yaml'],
    'Symfony\\Polyfill\\Ctype\\' => ["$vendor/symfony/polyfill-ctype", 'vendor/polyfill-ctype'],
];
$files = [
    "$vendor/symfony/deprecation-contracts/function.php" => 'vendor/deprecation/function.php',
    "$vendor/symfony/polyfill-ctype/bootstrap.php" => 'vendor/polyfill-ctype/bootstrap.php',
];
if (is_file("$vendor/symfony/polyfill-ctype/bootstrap80.php")) {
    $files["$vendor/symfony/polyfill-ctype/bootstrap80.php"] = 'vendor/polyfill-ctype/bootstrap80.php';
}

$entries = [];
foreach ($packages as $prefix => [$dir, $to]) {
    if (!is_dir($dir) && $prefix === 'Symfony\\Polyfill\\Ctype\\') {
        unset($packages[$prefix]);     // PHP's own ctype extension is enough
        continue;
    }
    if (!is_dir($dir)) {
        fwrite(STDERR, "missing $dir\n");
        exit(1);
    }
    $it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($dir, FilesystemIterator::SKIP_DOTS));
    foreach ($it as $file) {
        $rel = substr($file->getPathname(), strlen($dir) + 1);
        if ($file->isFile() && str_ends_with($rel, '.php') && !preg_match('#(^|/)(Tests?|tests?)/#', $rel)) {
            $entries["$to/$rel"] = $file->getPathname();
        }
    }
}
foreach ($files as $from => $to) {
    if (is_file($from)) {
        $entries[$to] = $from;
    }
}

$map = var_export(array_map(fn ($p) => '/plugin-core/' . $p[1], $packages), true);
$autoload = <<<PHP
<?php
spl_autoload_register(static function (string \$class): void {
    foreach ($map as \$prefix => \$dir) {
        if (str_starts_with(\$class, \$prefix)) {
            \$file = \$dir . '/' . str_replace('\\\\', '/', substr(\$class, strlen(\$prefix))) . '.php';
            if (is_file(\$file)) {
                require \$file;
            }
            return;
        }
    }
});
foreach (['vendor/deprecation/function.php', 'vendor/polyfill-ctype/bootstrap.php'] as \$f) {
    if (is_file('/plugin-core/' . \$f)) {
        require_once '/plugin-core/' . \$f;
    }
}
PHP;

ksort($entries);
$list = [];
$data = '';
foreach ($entries as $path => $from) {
    $bytes = (string)file_get_contents($from);
    $list[] = [$path, strlen($data), strlen($bytes)];
    $data .= $bytes;
}
$list[] = ['autoload.php', strlen($data), strlen($autoload)];
$data .= $autoload;
$header = json_encode(['files' => $list], JSON_UNESCAPED_SLASHES);
@mkdir(dirname($out), 0777, true);
file_put_contents($out, 'JPK1' . pack('V', strlen($header)) . $header . $data);
printf("%s: %d files, %.1f MB\n", $out, count($list), filesize($out) / 1048576);
