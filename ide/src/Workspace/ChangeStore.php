<?php

namespace JumPHP\Ide\Workspace;

/** Where an OverlayWorkspace keeps its changes (the playground: the user's drafts). */
interface ChangeStore
{
    /** @return array<string, ?string> path => new content (null: the file is deleted) */
    public function all(): array;

    /** Records a change: a content, null (deleted), or false (no change any more: back to the base). */
    public function put(string $path, string|false|null $content): void;
}
