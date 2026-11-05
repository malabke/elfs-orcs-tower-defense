<?php

namespace App;

use JumPHP\Save\Storage;

/**
 * A copy of the IDE's settings (sign-in, accounts, options, credentials, recent projects) in the user's home folder, outside the folder the IDE
 * lives in and its data folder: reinstalling the IDE (the installer, an update, another way of starting it, a cleaned cache) must never leave the
 * user signed out with their settings gone. At start what is missing is put back; then the copy follows the settings (every few minutes).
 * The files keep the permissions of the user alone (0600). JUMPHP_IDE_BACKUP=<folder> moves it, an empty value turns it off (the tests).
 */
final class SettingsBackup
{
    public const FILES = ['session.json', 'accounts.json', 'options.json', 'git-credentials.json', 'packagist.json', 'recent.json'];

    public static function folder(): ?string
    {
        $env = getenv('JUMPHP_IDE_BACKUP');
        if ($env !== false) {
            return $env === '' ? null : rtrim($env, '/\\');
        }
        $home = (string)(getenv('HOME') ?: getenv('USERPROFILE'));
        return $home !== '' ? rtrim($home, '/\\') . '/.jumphp/ide-backup' : null;
    }

    /** A settings file with something in it (not [] / {} / empty). */
    private static function filled(string $file): bool
    {
        if (!is_file($file)) {
            return false;
        }
        $json = json_decode((string)file_get_contents($file), true);
        return is_array($json) ? $json !== [] : $json !== null;
    }

    /** Puts back what the storage lacks from the copy. @return list<string> the files restored */
    public static function restore(Storage $storage, ?string $folder = null): array
    {
        $folder ??= self::folder();
        $restored = [];
        if ($folder === null || !is_dir($folder)) {
            return $restored;
        }
        foreach (self::FILES as $name) {
            $target = $storage->path($name);
            if (!self::filled($target) && self::filled("$folder/$name")) {
                @mkdir(dirname($target), 0777, true);
                if (@copy("$folder/$name", $target)) {
                    $restored[] = $name;
                }
            }
        }
        return $restored;
    }

    /** Copies the settings that have something in them and changed. @return int how many files were written */
    public static function backup(Storage $storage, ?string $folder = null): int
    {
        $folder ??= self::folder();
        if ($folder === null) {
            return 0;
        }
        $written = 0;
        foreach (self::FILES as $name) {
            $source = $storage->path($name);
            if (!self::filled($source)) {
                continue;
            }
            $copy = "$folder/$name";
            if (is_file($copy) && md5_file($copy) === md5_file($source)) {
                continue;
            }
            if (!is_dir($folder) && !@mkdir($folder, 0700, true)) {
                return $written;
            }
            if (@copy($source, $copy)) {
                @chmod($copy, 0600);
                $written++;
            }
        }
        return $written;
    }
}
