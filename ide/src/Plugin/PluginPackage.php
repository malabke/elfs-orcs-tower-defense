<?php

namespace JumPHP\Ide\Plugin;

/**
 * A plugin as a zip: what `bin/jumphp plugin:pack` makes and jumphp.net publishes (its folder at the zip's root,
 * named after the plugin, without what only its development needs), and the way back (a zip into a folder).
 */
final class PluginPackage
{
    /** what is left out of the zip, at the plugin's root */
    public const SKIP = ['vendor', 'var', 'tests', 'dist', '.git', 'node_modules', 'composer.lock', 'phpstan.neon', '.phpunit.result.cache', '.phpunit.cache'];

    /** Makes <dir>/dist/<id>-<version>.zip (or $out). @return array{file: string, files: int, size: int, id: string, version: string} */
    public static function pack(string $dir, ?string $out = null): array
    {
        $info = PluginInfo::read($dir);
        $out ??= "$dir/dist/{$info->id}-{$info->version}.zip";
        @mkdir(dirname($out), 0777, true);
        @unlink($out);
        $zip = new \ZipArchive();
        if ($zip->open($out, \ZipArchive::CREATE) !== true) {
            throw new \RuntimeException("cannot write $out");
        }
        $files = 0;
        $add = function (string $path, string $rel) use (&$add, $zip, &$files, $info): void {
            foreach (scandir($path) ?: [] as $entry) {
                if ($entry === '.' || $entry === '..' || ($rel === '' && in_array($entry, self::SKIP, true))) {
                    continue;
                }
                $full = "$path/$entry";
                $name = ltrim("$rel/$entry", '/');
                if (is_link($full)) {
                    continue;
                }
                if (is_dir($full)) {
                    $add($full, $name);
                } else {
                    $zip->addFile($full, "{$info->id}/$name");
                    $files++;
                }
            }
        };
        $add(rtrim($dir, '/'), '');
        $zip->close();
        return ['file' => $out, 'files' => $files, 'size' => (int)filesize($out), 'id' => $info->id, 'version' => $info->version];
    }

    /** Unpacks a plugin's zip into a new folder of $into: the plugin's folder (its composer.json read). */
    public static function unpack(string $zipFile, string $into): string
    {
        $zip = new \ZipArchive();
        if ($zip->open($zipFile) !== true) {
            throw new \RuntimeException('not a zip');
        }
        try {
            $top = null;
            for ($i = 0; $i < $zip->numFiles; $i++) {
                $name = (string)$zip->getNameIndex($i);
                if ($name === '' || str_starts_with($name, '/') || str_contains($name, '\\') || preg_match('#(^|/)\.\.(/|$)#', $name)) {
                    throw new \RuntimeException("a path of the zip is refused: $name");
                }
                $first = explode('/', $name)[0];
                if ($top !== null && $first !== $top) {
                    throw new \RuntimeException('the zip must hold one folder, the plugin\'s');
                }
                $top = $first;
            }
            if ($top === null) {
                throw new \RuntimeException('the zip is empty');
            }
            @mkdir($into, 0777, true);
            if (!$zip->extractTo($into)) {
                throw new \RuntimeException('the zip could not be unpacked');
            }
        } finally {
            $zip->close();
        }
        PluginInfo::read("$into/$top");     // a plugin indeed
        return "$into/$top";
    }
}
