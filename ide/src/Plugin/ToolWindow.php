<?php

namespace JumPHP\Ide\Plugin;

/** A plugin's tool window: refresh() draws it again (after the plugin's state changed by itself: a timer, a save). */
final class ToolWindow
{
    /** @param \Closure(): array<string, mixed> $render */
    public function __construct(
        public readonly string $id,
        public readonly string $label,
        public readonly \Closure $render,
        public readonly string $side,
        public readonly string $icon,
        private readonly \Closure $onRefresh,
    ) {
    }

    public function refresh(): void
    {
        ($this->onRefresh)($this->id);
    }
}
