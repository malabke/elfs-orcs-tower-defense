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
     * A tool for Claude: the IDE's MCP server (jumphp-ide, given to the Claude it starts) offers it beside its own, as
     * plugin_<id>_<name> (Claude Code: mcp__jumphp-ide__plugin_<id>_<name>). The handler gets the arguments and returns
     * what Claude reads: a string, an array (sent as JSON), an MCP result (['content' => [...]]) or a Deferred of one;
     * an exception is the tool's error. Describe it well: Claude chooses its tools from their descriptions.
     *
     * @param callable(array<string, mixed>): mixed $handler
     * @param array<string, array<string, mixed>> $properties each argument's JSON Schema: ['path' => ['type' => 'string', 'description' => '...']]
     * @param list<string> $required
     * @return string the tool's MCP name
     */
    public function mcpTool(string $name, string $description, callable $handler, array $properties = [], array $required = []): string
    {
        return $this->host->addMcpTool($this->plugin, $name, $description, $handler(...), $properties, $required);
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

    /**
     * The plugin's settings page (Tools > Plugins… > Settings… next to it): its fields, drawn by the IDE, kept for this
     * computer; read them with setting(), on('setting.changed', fn (string $name, mixed $value) => ...) follows them.
     *
     *     $ide->settings([
     *         'tag' => ['label' => 'Comment tag', 'default' => 'TODO', 'description' => 'what the tool window lists'],
     *         'max' => ['label' => 'At most', 'type' => 'number', 'default' => 200, 'min' => 1, 'max' => 5000],
     *         'case' => ['label' => 'Match case', 'type' => 'bool', 'default' => false],
     *         'sort' => ['label' => 'Sort by', 'type' => 'choice', 'choices' => ['path' => 'File', 'text' => 'Text'], 'default' => 'path'],
     *     ]);
     *
     * Types: text (default), number, bool, choice. setting($name) gives the default until the user changes it.
     *
     * @param array<string, array{label?: string, type?: string, default?: mixed, description?: string, choices?: array<string, string>, min?: int|float, max?: int|float}> $fields
     */
    public function settings(array $fields): void
    {
        $this->host->setSettingsFields($this->plugin, $fields);
    }

    /** A setting of this plugin, kept for this computer (all projects): the user's value, else its field's default. */
    public function setting(string $name, mixed $default = null): mixed
    {
        return $this->host->settings($this->plugin)[$name] ?? $default ?? $this->host->settingDefault($this->plugin, $name);
    }

    public function setSetting(string $name, mixed $value): void
    {
        $this->host->setSetting($this->plugin, $name, $value);
    }
}
