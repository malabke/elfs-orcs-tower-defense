<?php

namespace JumPHP\Ide\Plugin;

use JumPHP\Ide\Ide;
use JumPHP\Ide\Workspace\Workspace;

/**
 * What a plugin gets in activate(): the IDE's extension points and its services. Ids given here are the
 * plugin's own (the IDE prefixes them with the plugin's id).
 */
final class Api
{
    public function __construct(private readonly PluginHost $host, public readonly PluginInfo $plugin, private readonly Ide $ide)
    {
    }

    /**
     * An action: Find Action, the main menu (Tools by default), a shortcut.
     *
     * @param callable(EditorState): mixed $run
     * @param array{kbd?: string, icon?: string, menu?: string} $options kbd: 'Ctrl+Alt+T'...; menu: Tools, Code, Run, View, Navigate...
     */
    public function action(string $id, string $label, callable $run, array $options = []): void
    {
        $this->host->addAction($this->plugin, $id, $label, $run(...), $options);
    }

    /**
     * A tool window: a button on a side stripe, its content drawn from Ui nodes by $render (called each time it
     * shows, after each of its callbacks, and on refresh()).
     *
     * @param callable(): array<string, mixed> $render
     * @param array{side?: 'left'|'right'|'bottom', icon?: string} $options
     */
    public function toolWindow(string $id, string $label, callable $render, array $options = []): ToolWindow
    {
        return $this->host->addToolWindow($this->plugin, $id, $label, $render(...), $options);
    }

    /**
     * A run configuration (the Run widget's list): a command run in the project's folder, its output in the Run
     * window. A JumPHP project's commands go through Docker: ['bin/jumphp', 'php', 'games/x', 'tools/y.php'].
     *
     * @param list<string> $command
     * @param array{kind?: 'run'|'test'|'build'|'tool', icon?: string, group?: string} $options
     */
    public function runConfiguration(string $id, string $label, array $command, array $options = []): void
    {
        $this->host->addRunConfiguration($this->plugin, $id, $label, $command, $options);
    }

    /**
     * Completion in the files whose path matches $pattern (a glob: '*.php', 'config/*.yaml', '*'): the
     * provider returns items for where the caret is.
     *
     * @param callable(EditorState): list<array{label: string, insert?: string, detail?: string, kind?: string}> $provider
     */
    public function completion(string $pattern, callable $provider): void
    {
        $this->host->addProvider('completion', $this->plugin, $pattern, $provider(...));
    }

    /**
     * Problems in the files matching $pattern, checked as the user types (underlined, in the Problems window).
     *
     * @param callable(string $path, string $text): list<array{line: int, column?: int, endLine?: int, endColumn?: int, message: string, severity?: 'error'|'warning'}> $provider
     */
    public function diagnostics(string $pattern, callable $provider): void
    {
        $this->host->addProvider('diagnostics', $this->plugin, $pattern, $provider(...));
    }

    /**
     * A listener: 'file.saved' (string $path), 'files.changed' (outside the editor: git, a run).
     *
     * @param callable $listener
     */
    public function on(string $event, callable $listener): void
    {
        $this->host->addListener($this->plugin, $event, $listener(...));
    }

    /** @param callable(): mixed $work runs every $seconds (at least one frame), on the IDE's frame: keep it short */
    public function every(float $seconds, callable $work): void
    {
        $this->host->addTimer($this->plugin, $seconds, $work(...));
    }

    /** A notification (kind: info, success, warning, error). */
    public function notify(string $message, string $kind = 'info'): void
    {
        $this->host->ui(['do' => 'notify', 'message' => $message, 'kind' => $kind, 'title' => $this->plugin->name]);
    }

    /** Opens a file of the project in the editor (at a line). */
    public function openFile(string $path, ?int $line = null): void
    {
        $this->host->ui(['do' => 'open', 'path' => $path, 'line' => $line]);
    }

    /** Shows one of this plugin's tool windows. */
    public function showToolWindow(string $id): void
    {
        $this->host->ui(['do' => 'show', 'tool' => $this->host->qualify($this->plugin, $id)]);
    }

    /** @param list<string> $command runs a command in the project's folder, shown in the Run window */
    public function run(array $command, ?string $label = null): void
    {
        $this->host->ui(['do' => 'run', 'command' => array_map('strval', $command), 'label' => $label ?? implode(' ', $command)]);
    }

    /** The project's files: read(), write(), exists(), files()... (paths relative to the project). */
    public function workspace(): Workspace
    {
        return $this->ide->workspace;
    }

    /** The project's folder on this computer. */
    public function root(): ?string
    {
        return $this->ide->workspace->localPath();
    }

    /** The IDE's core: its PHP index ($ide->core()->index, ->php: the code intelligence), its search, its Git. */
    public function core(): Ide
    {
        return $this->ide;
    }

    /** A setting of this plugin, kept for this computer (all projects). */
    public function setting(string $name, mixed $default = null): mixed
    {
        return $this->host->settings($this->plugin)[$name] ?? $default;
    }

    public function setSetting(string $name, mixed $value): void
    {
        $this->host->setSetting($this->plugin, $name, $value);
    }
}
