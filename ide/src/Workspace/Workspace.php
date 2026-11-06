<?php

namespace JumPHP\Ide\Workspace;

/**
 * The files of the project being edited. Paths are relative to the project's root, with forward slashes, never
 * starting with "/" and never going up ("..").
 *   LocalWorkspace    a folder on disk (the desktop app: a clone of the project)
 *   OverlayWorkspace  read-only files with changes on top (the web IDE: the published game + the user's drafts)
 *   MemoryWorkspace   in memory (tests)
 */
interface Workspace
{
    /** A short name for the project (its folder's name). */
    public function name(): string;

    /**
     * Every file (not folders), sorted, with its size and modification time (a counter or a time: it only has to
     * change when the file does). Hidden folders (.git, .idea...) and vendor/, node_modules/ are left out of
     * the listing unless $all.
     *
     * @return array<string, array{size: int, mtime: float}>
     */
    public function files(bool $all = false): array;

    public function exists(string $path): bool;

    /** The file's modification time (as in files()), null when it does not exist. */
    public function mtime(string $path): ?float;

    /** The file's content; a missing file throws WorkspaceException. */
    public function read(string $path): string;

    /** Creates or replaces the file (and its folders). */
    public function write(string $path, string $content): void;

    public function delete(string $path): void;

    public function rename(string $from, string $to): void;

    /** The absolute path on disk when there is one (null: not a local folder). */
    public function localPath(string $path = ''): ?string;
}
