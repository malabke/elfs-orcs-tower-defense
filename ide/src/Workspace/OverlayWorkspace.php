<?php

namespace JumPHP\Ide\Workspace;

/**
 * Files that are not changed in place (the published version of a project) with changes on top, kept elsewhere
 * (the web IDE: the user's drafts in the playground's database). Writing back the base's own content drops the
 * change.
 */
final class OverlayWorkspace implements Workspace
{
    /** @var array<string, ?string>|null */
    private ?array $changes = null;

    public function __construct(private Workspace $base, private ChangeStore $store)
    {
    }

    public function name(): string
    {
        return $this->base->name();
    }

    /** @return array<string, ?string> path => content (null: deleted) */
    public function changes(): array
    {
        return $this->changes ??= $this->store->all();
    }

    /** 'added', 'modified', 'deleted' or null (unchanged). */
    public function state(string $path): ?string
    {
        $path = Paths::clean($path);
        $changes = $this->changes();
        if (!array_key_exists($path, $changes)) {
            return null;
        }
        if ($changes[$path] === null) {
            return 'deleted';
        }
        return $this->base->exists($path) ? 'modified' : 'added';
    }

    public function base(): Workspace
    {
        return $this->base;
    }

    public function files(bool $all = false): array
    {
        $files = $this->base->files($all);
        foreach ($this->changes() as $path => $content) {
            if ($content === null) {
                unset($files[$path]);
            } elseif ($all || !Paths::isSkipped($path)) {
                $files[$path] = ['size' => strlen($content), 'mtime' => (float)crc32($content)];
            }
        }
        ksort($files, SORT_STRING);
        return $files;
    }

    public function exists(string $path): bool
    {
        $path = Paths::clean($path);
        $changes = $this->changes();
        return array_key_exists($path, $changes) ? $changes[$path] !== null : $this->base->exists($path);
    }

    public function mtime(string $path): ?float
    {
        $path = Paths::clean($path);
        $changes = $this->changes();
        if (array_key_exists($path, $changes)) {
            return $changes[$path] === null ? null : (float)crc32($changes[$path]);
        }
        return $this->base->mtime($path);
    }

    public function read(string $path): string
    {
        $path = Paths::clean($path);
        $changes = $this->changes();
        if (array_key_exists($path, $changes)) {
            return $changes[$path] ?? throw new WorkspaceException("no file $path");
        }
        return $this->base->read($path);
    }

    public function write(string $path, string $content): void
    {
        $path = Paths::clean($path);
        $this->changes();
        if ($this->base->exists($path) && $this->base->read($path) === $content) {
            $this->store->put($path, false);
            unset($this->changes[$path]);
            return;
        }
        $this->store->put($path, $content);
        $this->changes[$path] = $content;
    }

    public function delete(string $path): void
    {
        $path = Paths::clean($path);
        foreach (array_keys($this->files(true)) as $file) {
            if ($file === $path || str_starts_with($file, "$path/")) {
                $this->deleteFile($file);
            }
        }
    }

    private function deleteFile(string $path): void
    {
        $this->changes();
        if ($this->base->exists($path)) {
            $this->store->put($path, null);
            $this->changes[$path] = null;
        } else {
            $this->store->put($path, false);
            unset($this->changes[$path]);
        }
    }

    public function rename(string $from, string $to): void
    {
        $from = Paths::clean($from);
        $to = Paths::clean($to);
        if ($this->exists($to)) {
            throw new WorkspaceException("$to already exists");
        }
        foreach (array_keys($this->files(true)) as $file) {
            if ($file === $from || str_starts_with($file, "$from/")) {
                $this->write($to . substr($file, strlen($from)), $this->read($file));
                $this->deleteFile($file);
            }
        }
    }

    /** Forgets the change of a path (null: every change): back to the base. */
    public function discard(?string $path = null): void
    {
        foreach (array_keys($this->changes()) as $file) {
            if ($path === null || $file === Paths::clean($path)) {
                $this->store->put($file, false);
                unset($this->changes[$file]);
            }
        }
    }

    public function localPath(string $path = ''): ?string
    {
        return null;
    }
}
