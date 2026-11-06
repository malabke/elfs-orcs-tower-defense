<?php

namespace JumPHP\Ide\Workspace;

final class MemoryChangeStore implements ChangeStore
{
    /** @param array<string, ?string> $changes */
    public function __construct(private array $changes = [])
    {
    }

    public function all(): array
    {
        return $this->changes;
    }

    public function put(string $path, string|false|null $content): void
    {
        if ($content === false) {
            unset($this->changes[$path]);
        } else {
            $this->changes[$path] = $content;
        }
    }
}
