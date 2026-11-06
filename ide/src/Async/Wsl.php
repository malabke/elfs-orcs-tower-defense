<?php

namespace JumPHP\Ide\Async;

/**
 * Windows with Docker Engine inside a WSL distribution (no Docker Desktop): `docker` does not answer on Windows but
 * `wsl -e docker info` does. Then bin/jumphp runs inside WSL: the project's folder seen from Linux (C:\x -> /mnt/c/x,
 * \\wsl$\Distro\home\x -> /home/x), the command quoted for `bash -lc`, the JUMPHP_* variables carried over by WSLENV.
 * bin/jumphp does the same by itself (tools/wsl.sh) when it is started from Git for Windows' bash.
 */
final class Wsl
{
    /** The folder as WSL's Linux sees it, or null when it is not a Windows / WSL path. */
    public static function linuxPath(string $path): ?string
    {
        $p = str_replace('\\', '/', $path);
        // \\wsl$\Ubuntu\home\x or \\wsl.localhost\Ubuntu\home\x: a folder of the distribution itself
        if (preg_match('#^//(?:wsl\$|wsl\.localhost)/[^/]+(/.*)?$#i', $p, $m)) {
            return ($m[1] ?? '') === '' ? '/' : rtrim($m[1], '/');
        }
        // C:\Users\x, C:/Users/x, /c/Users/x (Git for Windows' bash), /mnt/c/... (already Linux)
        if (preg_match('#^([A-Za-z]):(/.*)?$#', $p, $m) || preg_match('#^/([A-Za-z])(/.*)?$#', $p, $m)) {
            return '/mnt/' . strtolower($m[1]) . rtrim($m[2] ?? '', '/');
        }
        if (str_starts_with($p, '/mnt/') || str_starts_with($p, '/home/') || $p === '/') {
            return $p;
        }
        return null;
    }

    /** One argument for bash: single quotes, a single quote inside written '\''. */
    public static function quote(string $arg): string
    {
        return preg_match('#^[\w@%+=:,./-]+$#', $arg) ? $arg : "'" . str_replace("'", "'\\''", $arg) . "'";
    }

    /**
     * A command run in $cwd, started inside WSL: [wsl, -e, bash, -lc, "cd <dir> && exec <command>"].
     * A relative program (bin/jumphp) stays relative to $cwd.
     *
     * @param list<string> $command
     * @return list<string>
     */
    public static function command(array $command, string $cwd, string $wsl = 'wsl.exe'): array
    {
        $dir = self::linuxPath($cwd) ?? throw new \RuntimeException("$cwd cannot be reached from WSL");
        // a Windows absolute path given as an argument (a file chosen in the IDE) is translated too
        $words = array_map(fn (string $a) => self::quote(preg_match('#^[A-Za-z]:[\\\\/]#', $a) ? (self::linuxPath($a) ?? $a) : $a), $command);
        return [$wsl, '-e', 'bash', '-lc', 'cd ' . self::quote($dir) . ' && exec ' . implode(' ', $words)];
    }

    /**
     * WSLENV for these variables: the JUMPHP_* ones (and any already listed) reach the Linux side.
     *
     * @param array<string, string> $env
     */
    public static function wslenv(array $env, string $current = ''): string
    {
        $names = array_filter(explode(':', $current));
        foreach (array_keys($env) as $name) {
            if (str_starts_with($name, 'JUMPHP_') && !in_array($name, $names, true)) {
                $names[] = $name;
            }
        }
        return implode(':', $names);
    }
}
