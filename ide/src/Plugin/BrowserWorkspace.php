<?php

namespace JumPHP\Ide\Plugin;

use JumPHP\Ide\Workspace\MemoryWorkspace;
use JumPHP\Ide\Workspace\Workspace;

/**
 * The project's files as the web IDE's plugins see them (BrowserHost): a copy in the browser's PHP, kept up to date by
 * the page; what a plugin writes, deletes or renames is kept here and handed to the page (changes()), which saves it
 * through the web IDE's own commands (so a read-only member's plugins change nothing on jumphp.net).
 */
final class BrowserWorkspace implements Workspace
{
    /** @var list<array{op: string, path: string, content?: string, to?: string}> */
    private array $changes = [];

    public function __construct(private readonly MemoryWorkspace $files)
    {
    }

    /** A file as the page sees it (loaded, saved in the editor): not a plugin's change. */
    public function sync(string $path, ?string $content): void
    {
        if ($content === null) {
            if ($this->files->exists($path)) {
                $this->files->delete($path);
            }
            return;
        }
        $this->files->write($path, $content);
    }

    /** @return list<array{op: string, path: string, content?: string, to?: string}> the plugins' changes since the last call */
    public function changes(): array
    {
        [$out, $this->changes] = [$this->changes, []];
        return $out;
    }

    public function name(): string
    {
        return $this->files->name();
    }

    public function files(bool $all = false): array
    {
        return $this->files->files($all);
    }

    public function exists(string $path): bool
    {
        return $this->files->exists($path);
    }

    public function mtime(string $path): ?float
    {
        return $this->files->mtime($path);
    }

    public function read(string $path): string
    {
        return $this->files->read($path);
    }

    public function write(string $path, string $content): void
    {
        $this->files->write($path, $content);
        $this->changes[] = ['op' => 'write', 'path' => $path, 'content' => $content];
    }

    public function delete(string $path): void
    {
        $this->files->delete($path);
        $this->changes[] = ['op' => 'delete', 'path' => $path];
    }

    public function rename(string $from, string $to): void
    {
        $this->files->rename($from, $to);
        $this->changes[] = ['op' => 'rename', 'path' => $from, 'to' => $to];
    }

    public function localPath(string $path = ''): ?string
    {
        return null;
    }
}
