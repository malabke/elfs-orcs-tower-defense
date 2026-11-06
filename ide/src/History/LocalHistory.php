<?php

namespace JumPHP\Ide\History;

use JumPHP\Ide\Workspace\Paths;
use JumPHP\Ide\Workspace\Workspace;

/**
 * A file's local history (PhpStorm's Local History): its versions, kept by the IDE on this computer, without git.
 * A version is kept each time the IDE saves the file, and when it finds the file changed by something else (Claude
 * Code, a terminal, git) before it saves or opens it again. Show Local History lists them; one is compared with the
 * file, or put back.
 *
 * In the project, never in git: .jumphp/history/files/<sha1 of the path>/<time>.txt + index.json ({path, versions:
 * [{id, time, size, label}]}). Text files of 1 MB at most; the 60 newest versions of each file; none older than 60 days.
 */
final class LocalHistory
{
    public const DIR = '.jumphp/history/files';
    public const KEEP = 60;
    public const DAYS = 60;
    public const MAX_SIZE = 1_000_000;

    public function __construct(private readonly Workspace $workspace)
    {
    }

    /**
     * Keeps a version of a file (its content now, or $content), unless it is the same as the newest one.
     * @return array{id: string, time: float, size: int, label: string}|null the version kept
     */
    public function record(string $path, ?string $content = null, string $label = ''): ?array
    {
        $path = Paths::clean($path);
        if (!$this->tracks($path)) {
            return null;
        }
        if ($content === null) {
            if (!$this->workspace->exists($path)) {
                return null;
            }
            $content = $this->workspace->read($path);
        }
        if (strlen($content) > self::MAX_SIZE || (str_contains($content, "\0") && !Paths::isText($path))) {
            return null;
        }
        $index = $this->index($path);
        $newest = end($index['versions']);
        if ($newest !== false && $this->workspace->exists($this->file($path, $newest['id']))
            && hash_equals(sha1($this->workspace->read($this->file($path, $newest['id']))), sha1($content))) {
            return null;
        }
        $time = microtime(true);
        $id = sprintf('%.4f', $time);
        $version = ['id' => $id, 'time' => $time, 'size' => strlen($content), 'label' => $label];
        $this->ignore();
        $this->workspace->write($this->file($path, $id), $content);
        $index['versions'][] = $version;
        $this->prune($path, $index);
        return $version;
    }

    /**
     * Before the IDE writes a file: what is on disk now, when the history does not have it yet (the file as it was
     * before its first save, or changed outside the IDE since: Claude, a terminal, git). saved() then keeps the new one.
     */
    public function beforeWrite(string $path): void
    {
        $this->record($path, null, $this->index(Paths::clean($path))['versions'] === [] ? 'Before the first save' : 'Changed outside the IDE');
    }

    public function saved(string $path, string $content): void
    {
        $this->record($path, $content, '');
    }

    /** A file opened again: changed outside the IDE since its last version, that one is kept too (files with a history only). */
    public function opened(string $path, string $content): void
    {
        if ($this->index(Paths::clean($path))['versions'] !== []) {
            $this->record($path, $content, 'Changed outside the IDE');
        }
    }

    /** @return list<array{id: string, time: float, size: int, label: string}> newest first */
    public function versions(string $path): array
    {
        return array_reverse($this->index(Paths::clean($path))['versions']);
    }

    public function read(string $path, string $id): string
    {
        if (!preg_match('/^\d+\.\d{4}$/', $id)) {
            throw new \InvalidArgumentException("not a version: $id");
        }
        $file = $this->file(Paths::clean($path), $id);
        if (!$this->workspace->exists($file)) {
            throw new \RuntimeException("no version $id of $path");
        }
        return $this->workspace->read($file);
    }

    /** The project's own files only: not the history itself, nor what tools rebuild (vendor/, var/, dist/...). */
    private function tracks(string $path): bool
    {
        return $path !== '' && !str_starts_with($path, '.jumphp/history/') && !Paths::isSkipped($path);
    }

    private function folder(string $path): string
    {
        return self::DIR . '/' . sha1($path);
    }

    private function file(string $path, string $id): string
    {
        return $this->folder($path) . "/$id.txt";
    }

    /** @return array{path: string, versions: list<array{id: string, time: float, size: int, label: string}>} */
    private function index(string $path): array
    {
        $file = $this->folder($path) . '/index.json';
        $data = $this->workspace->exists($file) ? json_decode($this->workspace->read($file), true) : null;
        return is_array($data) && is_array($data['versions'] ?? null) ? ['path' => $path, 'versions' => array_values($data['versions'])] : ['path' => $path, 'versions' => []];
    }

    /** @param array{path: string, versions: list<array{id: string, time: float, size: int, label: string}>} $index */
    private function prune(string $path, array $index): void
    {
        $oldest = microtime(true) - self::DAYS * 86400;
        $keep = [];
        $versions = $index['versions'];
        foreach ($versions as $n => $version) {
            if ($n >= count($versions) - self::KEEP && ($version['time'] >= $oldest || $n === count($versions) - 1)) {
                $keep[] = $version;
            } elseif ($this->workspace->exists($this->file($path, $version['id']))) {
                $this->workspace->delete($this->file($path, $version['id']));
            }
        }
        $index['versions'] = $keep;
        $this->workspace->write($this->folder($path) . '/index.json', (string)json_encode($index, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
    }

    /** The folder never goes into git (a .gitignore of its own). */
    private function ignore(): void
    {
        if (!$this->workspace->exists('.jumphp/history/.gitignore')) {
            $this->workspace->write('.jumphp/history/.gitignore', "# the IDE's history of this computer (Claude's conversations, files' local history): never in git\n*\n");
        }
    }
}
