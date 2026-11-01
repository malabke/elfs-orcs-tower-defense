<?php

namespace JumPHP\Ide\Php;

use PhpParser\Node;

/** Where a position is in a file: namespace, imports, class, the variables known there, the nodes around it. */
final class Context
{
    public string $namespace = '';

    /** @var array<string, string> alias (lower case) => fqn */
    public array $uses = [];

    /** fqn of the class around the position */
    public ?string $class = null;

    /** @var array<string, string> variable => type, as known at the position */
    public array $vars = [];

    /** @var array<string, int> variable => byte offset of its first definition in the function */
    public array $varDefinitions = [];

    /** @var list<Node> from the outermost to the innermost node containing the position */
    public array $path = [];

    /** the innermost function-like node around the position */
    public ?Node\FunctionLike $function = null;

    /** @var array<string, int> byte offset of the last "use" statement's end, of the namespace line's end */
    public array $importAt = [];

    public function innermost(): ?Node
    {
        return $this->path ? $this->path[count($this->path) - 1] : null;
    }

    public function parent(int $level = 1): ?Node
    {
        return $this->path[count($this->path) - 1 - $level] ?? null;
    }
}
