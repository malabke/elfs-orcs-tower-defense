<?php

namespace JumPHP\Ide\Workspace;

/** Project-relative paths: cleaned, never absolute, never going up. */
final class Paths
{
    /** Folders the listing skips (still readable by path). */
    public const SKIPPED = ['vendor', 'node_modules', 'var', 'dist', 'build', '.cache'];

    public static function clean(string $path): string
    {
        $path = str_replace('\\', '/', trim($path));
        $parts = [];
        foreach (explode('/', $path) as $part) {
            if ($part === '' || $part === '.') {
                continue;
            }
            if ($part === '..' || str_contains($part, "\0")) {
                throw new WorkspaceException("bad path: $path");
            }
            $parts[] = $part;
        }
        return implode('/', $parts);
    }

    /** Whether the listing leaves this path out (hidden folders, dependencies, build output). */
    public static function isSkipped(string $path): bool
    {
        foreach (explode('/', $path) as $i => $part) {
            if ($part !== '' && $part[0] === '.' && $part !== '.github' && $part !== '.env.example') {
                return true;
            }
            if (in_array($part, self::SKIPPED, true) && $i < substr_count($path, '/')) {
                return true;
            }
        }
        return false;
    }

    public static function extension(string $path): string
    {
        return strtolower(pathinfo($path, PATHINFO_EXTENSION));
    }

    /** Whether the editor opens it as text (by name; the content is checked too). */
    public static function isText(string $path): bool
    {
        static $text = ['php', 'phtml', 'js', 'mjs', 'ts', 'json', 'yaml', 'yml', 'md', 'txt', 'html', 'htm', 'css', 'scss',
            'xml', 'svg', 'neon', 'dist', 'sh', 'ini', 'env', 'csv', 'tmj', 'tsj', 'tmx', 'tsx', 'glsl', 'vert', 'frag', 'sql',
            'twig', 'lock', 'toml', 'rs', 'c', 'h', 'py', 'gitignore', 'dockerignore', 'conf', 'log', 'webmanifest'];
        $base = basename($path);
        return in_array(self::extension($path), $text, true) || !str_contains($base, '.')
            || in_array($base, ['Dockerfile', 'Makefile', '.gitignore', '.env', 'LICENSE'], true);
    }
}
