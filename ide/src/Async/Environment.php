<?php

namespace JumPHP\Ide\Async;

/**
 * The environment of the programs the IDE starts. An app opened from the desktop's menu or a double click gets a
 * minimal PATH (often /usr/bin:/bin): no /usr/local/bin, where Docker usually is, no ~/.local/bin... So the PATH
 * is the IDE's own, plus the user's login shell's (read once, in the background), plus the usual folders.
 */
final class Environment
{
    /** @var list<string> folders added when they exist */
    public const USUAL = ['/usr/local/bin', '/usr/local/sbin', '/usr/bin', '/bin', '/usr/sbin', '/sbin', '/snap/bin',
        '/opt/homebrew/bin', '/opt/local/bin', '/Applications/Docker.app/Contents/Resources/bin', '/var/lib/flatpak/exports/bin'];

    private static ?string $shellPath = null;
    private static bool $asked = false;

    /** The PATH programs get. */
    public static function path(): string
    {
        $home = getenv('HOME') ?: '';
        $dirs = [];
        $add = function (string $list) use (&$dirs): void {
            foreach (explode(PATH_SEPARATOR, $list) as $dir) {
                $dir = rtrim($dir, '/\\');
                if ($dir !== '' && !in_array($dir, $dirs, true)) {
                    $dirs[] = $dir;
                }
            }
        };
        $add((string)getenv('PATH'));
        if (self::$shellPath !== null) {
            $add(self::$shellPath);
        }
        $usual = self::USUAL;
        if ($home !== '') {
            array_push($usual, "$home/.local/bin", "$home/bin", "$home/.docker/bin", "$home/.rd/bin");
        }
        foreach ($usual as $dir) {
            if (is_dir($dir)) {
                $add($dir);
            }
        }
        return implode(PATH_SEPARATOR, $dirs);
    }

    /** @return array<string, string> the IDE's environment with the full PATH */
    public static function variables(): array
    {
        $env = getenv();
        $env['PATH'] = self::path();
        return $env;
    }

    /** Where a program is (null: not found in the PATH programs get). */
    public static function which(string $program): ?string
    {
        if (str_contains($program, '/') || str_contains($program, '\\')) {
            return is_file($program) && is_executable($program) ? $program : null;
        }
        // Windows: git means git.exe (or .cmd, .bat...: PATHEXT) when no extension is given
        $names = [$program];
        if (PHP_OS_FAMILY === 'Windows' && pathinfo($program, PATHINFO_EXTENSION) === '') {
            $names = array_map(fn (string $ext) => $program . strtolower($ext), array_filter(explode(';', (string)(getenv('PATHEXT') ?: '.EXE;.CMD;.BAT;.COM'))));
        }
        foreach (explode(PATH_SEPARATOR, self::path()) as $dir) {
            foreach ($names as $name) {
                $candidate = $dir . DIRECTORY_SEPARATOR . $name;
                if (is_file($candidate) && is_executable($candidate)) {
                    return $candidate;
                }
            }
        }
        return null;
    }

    /**
     * Reads the PATH of the user's login shell once (bash -l / zsh -l: their .profile, .bashrc...), without waiting:
     * the next programs get it.
     */
    public static function loadShellPath(Processes $processes): void
    {
        if (self::$asked || PHP_OS_FAMILY === 'Windows') {
            return;
        }
        self::$asked = true;
        $shell = getenv('SHELL') ?: '/bin/sh';
        if (!is_executable($shell)) {
            return;
        }
        $marker = '__JUMPHP_PATH__';
        $started = $processes->start([$shell, '-lic', "printf '$marker%s$marker' \"\$PATH\""], getenv('HOME') ?: '/', [], null, '');
        $started['done']->then(function (array $r) use ($marker): void {
            if (preg_match("/$marker(.*?)$marker/s", $r['stdout'], $m)) {
                self::$shellPath = trim($m[1]);
            }
        }, fn () => null);
    }
}
