<?php

namespace JumPHP\Ide\Workspace;

/** Files in memory (tests, generated projects). */
final class MemoryWorkspace implements Workspace
{
    private int $clock = 0;

    /** @var array<string, array{0: string, 1: int}> path => [content, mtime] */
    private array $files = [];

    /** @param array<string, string> $files */
    public function __construct(array $files = [], private string $name = 'project')
    {
        foreach ($files as $path => $content) {
            $this->write($path, $content);
        }
    }

    public function name(): string
    {
        return $this->name;
    }

    public function files(bool $all = false): array
    {
        $out = [];
        foreach ($this->files as $path => [$content, $mtime]) {
            if ($all || !Paths::isSkipped($path)) {
                $out[$path] = ['size' => strlen($content), 'mtime' => (float)$mtime];
            }
        }
        ksort($out, SORT_STRING);
        return $out;
    }

    public function exists(string $path): bool
    {
        return isset($this->files[Paths::clean($path)]);
    }

    public function mtime(string $path): ?float
    {
        $entry = $this->files[Paths::clean($path)] ?? null;
        return $entry === null ? null : (float)$entry[1];
    }

    public function read(string $path): string
    {
        return $this->files[Paths::clean($path)][0] ?? throw new WorkspaceException("no file $path");
    }

    public function write(string $path, string $content): void
    {
        $this->files[Paths::clean($path)] = [$content, ++$this->clock];
    }

    public function delete(string $path): void
    {
        $clean = Paths::clean($path);
        foreach (array_keys($this->files) as $file) {
            if ($file === $clean || str_starts_with($file, "$clean/")) {
                unset($this->files[$file]);
            }
        }
    }

    public function rename(string $from, string $to): void
    {
        $from = Paths::clean($from);
        $to = Paths::clean($to);
        if (isset($this->files[$to])) {
            throw new WorkspaceException("$to already exists");
        }
        foreach ($this->files as $file => $entry) {
            if ($file === $from || str_starts_with($file, "$from/")) {
                unset($this->files[$file]);
                $this->files[$to . substr($file, strlen($from))] = [$entry[0], ++$this->clock];
            }
        }
    }

    public function localPath(string $path = ''): ?string
    {
        return null;
    }
}
