<?php

namespace JumPHP\Ide\Workspace;

/** A folder on disk. */
final class LocalWorkspace implements Workspace
{
    private string $root;

    public function __construct(string $root)
    {
        $real = realpath($root);
        if ($real === false || !is_dir($real)) {
            throw new WorkspaceException("not a folder: $root");
        }
        $this->root = rtrim(str_replace('\\', '/', $real), '/');
    }

    public function name(): string
    {
        return basename($this->root);
    }

    public function files(bool $all = false): array
    {
        $files = [];
        $this->scan('', $all, $files);
        ksort($files, SORT_STRING);
        return $files;
    }

    /** @param array<string, array{size: int, mtime: float}> $files */
    private function scan(string $dir, bool $all, array &$files): void
    {
        $entries = @scandir($this->root . ($dir === '' ? '' : "/$dir"));
        if ($entries === false) {
            return;
        }
        foreach ($entries as $entry) {
            if ($entry === '.' || $entry === '..') {
                continue;
            }
            $path = $dir === '' ? $entry : "$dir/$entry";
            $full = "$this->root/$path";
            if (is_link($full) && !$all) {
                continue;
            }
            if (is_dir($full)) {
                if (!$all && (($entry[0] === '.' && $entry !== '.github') || in_array($entry, Paths::SKIPPED, true))) {
                    continue;
                }
                $this->scan($path, $all, $files);
            } elseif (is_file($full)) {
                $files[$path] = ['size' => (int)filesize($full), 'mtime' => (float)filemtime($full)];
            }
        }
    }

    public function exists(string $path): bool
    {
        return is_file($this->full($path));
    }

    public function mtime(string $path): ?float
    {
        $full = $this->full($path);
        clearstatcache(true, $full);
        return is_file($full) ? (float)filemtime($full) : null;
    }

    public function read(string $path): string
    {
        $full = $this->full($path);
        if (!is_file($full) || ($content = @file_get_contents($full)) === false) {
            throw new WorkspaceException("no file $path");
        }
        return $content;
    }

    public function write(string $path, string $content): void
    {
        $full = $this->full($path);
        if (!is_dir(dirname($full)) && !@mkdir(dirname($full), 0777, true) && !is_dir(dirname($full))) {
            throw new WorkspaceException("cannot create the folder of $path");
        }
        if (@file_put_contents($full, $content) === false) {
            throw new WorkspaceException("cannot write $path");
        }
        clearstatcache(true, $full);
    }

    public function delete(string $path): void
    {
        $full = $this->full($path);
        if (is_dir($full)) {
            $this->removeTree($full);
            return;
        }
        if (is_file($full) && !@unlink($full)) {
            throw new WorkspaceException("cannot delete $path");
        }
    }

    private function removeTree(string $dir): void
    {
        foreach (scandir($dir) ?: [] as $entry) {
            if ($entry !== '.' && $entry !== '..') {
                $full = "$dir/$entry";
                is_dir($full) && !is_link($full) ? $this->removeTree($full) : @unlink($full);
            }
        }
        @rmdir($dir);
    }

    public function rename(string $from, string $to): void
    {
        $target = $this->full($to);
        if (file_exists($target)) {
            throw new WorkspaceException("$to already exists");
        }
        if (!is_dir(dirname($target))) {
            @mkdir(dirname($target), 0777, true);
        }
        if (!@rename($this->full($from), $target)) {
            throw new WorkspaceException("cannot rename $from");
        }
    }

    public function localPath(string $path = ''): string
    {
        return $this->full($path);
    }

    private function full(string $path): string
    {
        $clean = Paths::clean($path);
        return $clean === '' ? $this->root : "$this->root/$clean";
    }
}
