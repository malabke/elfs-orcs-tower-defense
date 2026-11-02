<?php

namespace JumPHP\Ide\Plugin;

use JumPHP\Ide\Ide;

/**
 * The IDE's plugins on one opened project: found in folders (the user's plugins, the project's .jumphp/plugins/),
 * loaded into the IDE's PHP, activated, and what they add served to the interface through the plugins.* commands.
 * A plugin that fails (activating, an action, a tool window, a provider, a timer) is reported, never fatal.
 *
 * PHP cannot unload a class: a plugin installed while the IDE runs is loaded at once, but a newer version of a
 * loaded one (an update, a plugin being developed) needs the IDE restarted (plugins.list says which).
 */
final class PluginHost
{
    /** @var array<string, array{info: PluginInfo, status: string, error: ?string, instance: ?Plugin}> by id */
    private array $plugins = [];

    /** @var array<string, array{plugin: string, id: string, label: string, run: \Closure, kbd: ?string, icon: ?string, menu: string}> */
    private array $actions = [];

    /** @var array<string, array{plugin: string, window: ToolWindow, callbacks: array<int, \Closure>}> */
    private array $tools = [];

    /** @var array<string, array{plugin: string, id: string, label: string, command: list<string>, kind: string, icon: string, group: string}> */
    private array $runs = [];

    /** @var list<array{kind: string, plugin: string, pattern: string, provider: \Closure}> */
    private array $providers = [];

    /** @var list<array{plugin: string, event: string, listener: \Closure}> */
    private array $listeners = [];

    /** @var array<string, array{plugin: string, name: string, description: string, inputSchema: array<string, mixed>, handler: \Closure}> MCP tools for Claude */
    private array $mcp = [];

    /** @var list<array{plugin: string, every: float, next: float, work: \Closure}> */
    private array $timers = [];

    /** @var array{disabled: list<string>, settings: array<string, array<string, mixed>>}|null */
    private ?array $state = null;

    /** @var array<string, string> where an older version of a plugin was replaced: needs a restart */
    private array $pending = [];

    /**
     * @param callable(string, array<string, mixed>): void $emit the IDE's events
     * @param string|null $userDir the user's plugins (install / remove go there); its plugins.json keeps the state
     */
    public function __construct(private readonly Ide $ide, private $emit, private readonly ?string $userDir = null)
    {
    }

    // loading -----------------------------------------------------------------------------------------------------

    /** Loads every plugin under these folders (each sub-folder with a composer.json). @param array<string, string> $dirs folder => source */
    public function loadFrom(array $dirs): void
    {
        foreach ($dirs as $parent => $source) {
            foreach (glob(rtrim($parent, '/') . '/*/composer.json') ?: [] as $file) {
                $this->load(dirname($file), $source);
            }
        }
    }

    /** Loads one plugin's folder. @return array<string, mixed> its description and status */
    public function load(string $dir, string $source = 'user'): array
    {
        try {
            $info = PluginInfo::read($dir, $source);
        } catch (\InvalidArgumentException $e) {
            $this->emitError('plugins', $e);
            return ['dir' => $dir, 'status' => 'error', 'error' => $e->getMessage()];
        }
        if (isset($this->plugins[$info->id])) {
            return $this->describe($info->id);
        }
        $this->plugins[$info->id] = ['info' => $info, 'status' => 'loading', 'error' => null, 'instance' => null];
        if (in_array($info->id, $this->state()['disabled'], true)) {
            $this->plugins[$info->id]['status'] = 'disabled';
            return $this->describe($info->id);
        }
        try {
            $info->registerAutoload();
            if (!class_exists($info->class)) {
                throw new \RuntimeException("class {$info->class} not found (composer.json autoload)");
            }
            $instance = new ($info->class)();
            if (!$instance instanceof Plugin) {
                throw new \RuntimeException("{$info->class} does not implement " . Plugin::class);
            }
            $this->plugins[$info->id]['instance'] = $instance;
            $instance->activate(new Api($this, $info, $this->ide));
            $this->plugins[$info->id]['status'] = 'active';
        } catch (\Throwable $e) {
            $this->forget($info->id);
            $this->plugins[$info->id]['status'] = 'error';
            $this->plugins[$info->id]['error'] = $e->getMessage() . ' (' . basename($e->getFile()) . ':' . $e->getLine() . ')';
            $this->emitError($info->name, $e);
        }
        return $this->describe($info->id);
    }

    /** What a plugin added goes away (it failed): its actions, windows, providers, listeners, timers. */
    private function forget(string $plugin): void
    {
        $keep = fn (array $x) => $x['plugin'] !== $plugin;
        $this->actions = array_filter($this->actions, $keep);
        $this->tools = array_filter($this->tools, $keep);
        $this->runs = array_filter($this->runs, $keep);
        $this->providers = array_values(array_filter($this->providers, $keep));
        $this->listeners = array_values(array_filter($this->listeners, $keep));
        $this->timers = array_values(array_filter($this->timers, $keep));
        $this->mcp = array_filter($this->mcp, $keep);
    }

    // what plugins add (Api) ----------------------------------------------------------------------------------------

    public function qualify(PluginInfo $plugin, string $id): string
    {
        return 'plugin:' . $plugin->id . ':' . $id;
    }

    /** @param array<string, mixed> $options */
    public function addAction(PluginInfo $plugin, string $id, string $label, \Closure $run, array $options): void
    {
        $key = $this->qualify($plugin, $id);
        $this->actions[$key] = ['plugin' => $plugin->id, 'id' => $key, 'label' => $label, 'run' => $run,
            'kbd' => isset($options['kbd']) ? (string)$options['kbd'] : null, 'icon' => isset($options['icon']) ? (string)$options['icon'] : null,
            'menu' => (string)($options['menu'] ?? 'Tools')];
    }

    /** @param array<string, mixed> $options */
    public function addToolWindow(PluginInfo $plugin, string $id, string $label, \Closure $render, array $options): ToolWindow
    {
        $key = $this->qualify($plugin, $id);
        $side = in_array($options['side'] ?? 'right', ['left', 'right', 'bottom'], true) ? (string)($options['side'] ?? 'right') : 'right';
        $window = new ToolWindow($key, $label, $render, $side, (string)($options['icon'] ?? 'plugin'),
            fn (string $tool) => $this->ui(['do' => 'refresh', 'tool' => $tool]));
        $this->tools[$key] = ['plugin' => $plugin->id, 'window' => $window, 'callbacks' => []];
        return $window;
    }

    /** @param list<string> $command @param array<string, mixed> $options */
    public function addRunConfiguration(PluginInfo $plugin, string $id, string $label, array $command, array $options): void
    {
        $key = $this->qualify($plugin, $id);
        $this->runs[$key] = ['plugin' => $plugin->id, 'id' => $key, 'label' => $label, 'command' => array_map('strval', $command),
            'kind' => (string)($options['kind'] ?? 'tool'), 'icon' => (string)($options['icon'] ?? 'plugin'), 'group' => (string)($options['group'] ?? $plugin->name)];
    }

    public function addProvider(string $kind, PluginInfo $plugin, string $pattern, \Closure $provider): void
    {
        $this->providers[] = ['kind' => $kind, 'plugin' => $plugin->id, 'pattern' => $pattern, 'provider' => $provider];
    }

    /** The MCP name of a plugin's tool: plugin_<id>_<name> (Claude Code calls it mcp__jumphp-ide__plugin_<id>_<name>). */
    public function mcpName(PluginInfo $plugin, string $name): string
    {
        return 'plugin_' . str_replace('-', '_', $plugin->id) . '_' . $name;
    }

    /**
     * @param array<string, array<string, mixed>> $properties JSON Schema of each argument
     * @param list<string> $required
     */
    public function addMcpTool(PluginInfo $plugin, string $name, string $description, \Closure $handler, array $properties, array $required): string
    {
        if (!preg_match('/^[a-z][a-z0-9_]{0,40}$/', $name)) {
            throw new \InvalidArgumentException("MCP tool name '$name': lower case letters, digits and _");
        }
        $full = $this->mcpName($plugin, $name);
        $schema = ['type' => 'object', 'properties' => $properties ?: new \stdClass()];
        if ($required) {
            $schema['required'] = $required;
        }
        $this->mcp[$full] = ['plugin' => $plugin->id, 'name' => $full, 'description' => "[{$plugin->name}] $description", 'inputSchema' => $schema, 'handler' => $handler];
        return $full;
    }

    /** @return list<array{name: string, description: string, inputSchema: array<string, mixed>}> the plugins' tools for Claude */
    public function mcpTools(): array
    {
        return array_values(array_map(fn ($t) => ['name' => $t['name'], 'description' => $t['description'], 'inputSchema' => $t['inputSchema']], $this->mcp));
    }

    /** Runs a plugin's MCP tool: its value (or a Deferred of it); its exception is the tool's error. @param array<string, mixed> $args */
    public function callMcpTool(string $name, array $args): mixed
    {
        $tool = $this->mcp[$name] ?? throw new \InvalidArgumentException("no plugin tool '$name'");
        return $this->call($tool['plugin'], fn () => ($tool['handler'])($args));
    }

    public function addListener(PluginInfo $plugin, string $event, \Closure $listener): void
    {
        $this->listeners[] = ['plugin' => $plugin->id, 'event' => $event, 'listener' => $listener];
    }

    public function addTimer(PluginInfo $plugin, float $seconds, \Closure $work): void
    {
        $this->timers[] = ['plugin' => $plugin->id, 'every' => max(0.0, $seconds), 'next' => microtime(true) + max(0.0, $seconds), 'work' => $work];
    }

    /** @param array<string, mixed> $message to the interface (event plugins.ui) */
    public function ui(array $message): void
    {
        ($this->emit)('plugins.ui', $message);
    }

    // the interface's commands ------------------------------------------------------------------------------------

    public function registerCommands(): void
    {
        $this->ide->register('plugins.list', fn () => $this->listing());
        $this->ide->register('plugins.contributions', fn () => $this->contributions());
        $this->ide->register('plugins.action', fn (array $p) => $this->runAction((string)($p['id'] ?? ''), EditorState::from($p)));
        $this->ide->register('plugins.view', fn (array $p) => $this->view((string)($p['tool'] ?? '')));
        $this->ide->register('plugins.callback', fn (array $p) => $this->callback((string)($p['tool'] ?? ''), (int)($p['callback'] ?? -1), $p['value'] ?? null));
        $this->ide->register('plugins.complete', fn (array $p) => $this->complete(EditorState::from($p)));
        $this->ide->register('plugins.diagnostics', fn (array $p) => $this->diagnostics((string)($p['path'] ?? ''), (string)($p['text'] ?? '')));
        $this->ide->register('plugins.enable', fn (array $p) => $this->enable((string)($p['id'] ?? ''), !empty($p['on'])));
        $this->ide->register('plugins.setting', function (array $p) {
            $info = $this->plugins[(string)($p['id'] ?? '')]['info'] ?? throw new \InvalidArgumentException('no plugin ' . ($p['id'] ?? ''));
            $this->setSetting($info, (string)($p['name'] ?? ''), $p['value'] ?? null);
            return true;
        });
        if ($this->userDir !== null) {
            $this->ide->register('plugins.install', fn (array $p) => $this->install((string)($p['dir'] ?? '')));
            $this->ide->register('plugins.remove', fn (array $p) => $this->remove((string)($p['id'] ?? '')));
        }
    }

    /** @return array<string, mixed> */
    private function describe(string $id): array
    {
        $p = $this->plugins[$id];
        return $p['info']->describe() + ['status' => $p['status'], 'error' => $p['error'], 'restart' => isset($this->pending[$id]),
            'removable' => $this->userDir !== null && $p['info']->source === 'user'];
    }

    /** @return list<array<string, mixed>> */
    public function listing(): array
    {
        return array_map(fn ($id) => $this->describe((string)$id), array_keys($this->plugins));
    }

    /** @return array<string, list<array<string, mixed>>> what the interface adds: actions, tool windows, run configurations, providers */
    public function contributions(): array
    {
        $name = fn (string $id) => $this->plugins[$id]['info']->name ?? $id;
        return [
            'actions' => array_values(array_map(fn ($a) => array_diff_key($a, ['run' => 1]) + ['pluginName' => $name($a['plugin'])], $this->actions)),
            'tools' => array_values(array_map(fn ($t) => ['id' => $t['window']->id, 'label' => $t['window']->label, 'side' => $t['window']->side,
                'icon' => $t['window']->icon, 'plugin' => $t['plugin']], $this->tools)),
            'run' => $this->runConfigurations(),
            'providers' => array_map(fn ($p) => ['kind' => $p['kind'], 'pattern' => $p['pattern'], 'plugin' => $p['plugin']], $this->providers),
            'mcp' => array_values(array_map(fn ($t) => ['name' => $t['name'], 'description' => $t['description'], 'plugin' => $t['plugin']], $this->mcp)),
        ];
    }

    /** @return list<array<string, mixed>> run configurations, as ProjectInfo's */
    public function runConfigurations(): array
    {
        return array_values(array_map(fn ($r) => array_diff_key($r, ['plugin' => 1]), $this->runs));
    }

    public function runAction(string $id, EditorState $at): mixed
    {
        $action = $this->actions[$id] ?? throw new \InvalidArgumentException("no plugin action '$id'");
        return $this->call($action['plugin'], fn () => $this->plain(($action['run'])($at)));
    }

    /** @return array<string, mixed> the tool window's content, callbacks numbered */
    public function view(string $tool): array
    {
        $t = $this->tools[$tool] ?? throw new \InvalidArgumentException("no plugin tool window '$tool'");
        $this->tools[$tool]['callbacks'] = [];
        try {
            $node = ($t['window']->render)();
            if (!isset($node['type'])) {
                $node = Ui::text('(the tool window returned no Ui node)', true);
            }
        } catch (\Throwable $e) {
            $this->emitError($this->plugins[$t['plugin']]['info']->name ?? $t['plugin'], $e);
            $node = Ui::column(Ui::text('This tool window failed:'), Ui::code($e->getMessage() . "\n" . basename($e->getFile()) . ':' . $e->getLine()));
        }
        return $this->serialize($tool, $node);
    }

    /** Runs a callback of the tool window (a click, a text, a box) and returns its new content. @return array<string, mixed> */
    public function callback(string $tool, int $callback, mixed $value): array
    {
        $t = $this->tools[$tool] ?? throw new \InvalidArgumentException("no plugin tool window '$tool'");
        $fn = $t['callbacks'][$callback] ?? null;
        if ($fn !== null) {
            $this->call($t['plugin'], fn () => $value === null ? $fn() : $fn($value), false);
        }
        return $this->view($tool);
    }

    /**
     * @param array<string, mixed> $node
     * @return array<string, mixed>
     */
    private function serialize(string $tool, array $node): array
    {
        foreach (['onClick', 'onSubmit', 'onChange'] as $key) {
            if (isset($node[$key]) && is_callable($node[$key])) {
                $this->tools[$tool]['callbacks'][] = \Closure::fromCallable($node[$key]);
                $node[$key] = array_key_last($this->tools[$tool]['callbacks']);
            } else {
                unset($node[$key]);
            }
        }
        foreach (['children', 'items'] as $key) {
            if (isset($node[$key]) && is_array($node[$key])) {
                $node[$key] = array_values(array_map(fn ($child) => is_array($child) ? $this->serialize($tool, $child) : $child, $node[$key]));
            }
        }
        return $node;
    }

    /** @return list<array<string, mixed>> completion items of the plugins for this file */
    public function complete(EditorState $at): array
    {
        $items = [];
        foreach ($this->matching('completion', (string)$at->path) as $p) {
            $found = $this->call($p['plugin'], fn () => ($p['provider'])($at), false);
            foreach (is_array($found) ? $found : [] as $item) {
                if (is_array($item) && isset($item['label'])) {
                    $items[] = ['label' => (string)$item['label'], 'insert' => (string)($item['insert'] ?? $item['label']),
                        'detail' => (string)($item['detail'] ?? ($this->plugins[$p['plugin']]['info']->name ?? '')), 'kind' => (string)($item['kind'] ?? 'text')];
                }
            }
        }
        return $items;
    }

    /** @return list<array<string, mixed>> problems the plugins find in this text */
    public function diagnostics(string $path, string $text): array
    {
        $out = [];
        foreach ($this->matching('diagnostics', $path) as $p) {
            $found = $this->call($p['plugin'], fn () => ($p['provider'])($path, $text), false);
            foreach (is_array($found) ? $found : [] as $d) {
                if (!is_array($d) || !isset($d['line'], $d['message'])) {
                    continue;
                }
                $line = max(1, (int)$d['line']);
                $out[] = ['startLine' => $line, 'startColumn' => max(1, (int)($d['column'] ?? 1)), 'endLine' => max($line, (int)($d['endLine'] ?? $line)),
                    'endColumn' => (int)($d['endColumn'] ?? 1000), 'message' => (string)$d['message'],
                    'severity' => ($d['severity'] ?? 'warning') === 'error' ? 'error' : 'warning', 'source' => $this->plugins[$p['plugin']]['info']->name ?? $p['plugin']];
            }
        }
        return $out;
    }

    /** @return list<array{kind: string, plugin: string, pattern: string, provider: \Closure}> */
    private function matching(string $kind, string $path): array
    {
        return array_values(array_filter($this->providers, fn ($p) => $p['kind'] === $kind
            && ($p['pattern'] === '*' || fnmatch($p['pattern'], $path) || fnmatch($p['pattern'], basename($path)))));
    }

    // events, timers ------------------------------------------------------------------------------------------------

    public function dispatch(string $event, mixed ...$args): void
    {
        foreach ($this->listeners as $l) {
            if ($l['event'] === $event) {
                $this->call($l['plugin'], fn () => ($l['listener'])(...$args), false);
            }
        }
    }

    public function tick(?float $now = null): void
    {
        $now ??= microtime(true);
        foreach ($this->timers as $i => $t) {
            if ($now >= $t['next']) {
                $this->timers[$i]['next'] = $now + $t['every'];
                $this->call($t['plugin'], $t['work'], false);
            }
        }
    }

    /** Runs a plugin's code: its exception is reported (ide.error) and, for a command, rethrown with its name. */
    private function call(string $plugin, \Closure $work, bool $rethrow = true): mixed
    {
        try {
            return $work();
        } catch (\Throwable $e) {
            $name = $this->plugins[$plugin]['info']->name ?? $plugin;
            $this->emitError($name, $e);
            if ($rethrow) {
                throw new \RuntimeException("$name: " . $e->getMessage(), 0, $e);
            }
            return null;
        }
    }

    /** What an action returns, made plain for the interface (a string is shown as a notification). */
    private function plain(mixed $value): mixed
    {
        if (is_string($value) && $value !== '') {
            return ['message' => $value];
        }
        return is_scalar($value) || is_array($value) ? $value : null;
    }

    private function emitError(string $where, \Throwable $e): void
    {
        ($this->emit)('ide.error', ['where' => $where, 'message' => $e->getMessage(), 'at' => basename($e->getFile()) . ':' . $e->getLine()]);
    }

    // installing, state -----------------------------------------------------------------------------------------------

    /** Copies a plugin's folder into the user's plugins and loads it (a newer version of a loaded one: at the next start). @return array<string, mixed> */
    public function install(string $dir): array
    {
        if ($this->userDir === null) {
            throw new \RuntimeException('plugins cannot be installed here');
        }
        $info = PluginInfo::read($dir, 'user');
        $target = $this->userDir . '/' . $info->id;
        if (realpath($dir) !== realpath($target)) {
            if (is_dir($target)) {
                self::removeTree($target);
            }
            self::copyTree(rtrim($dir, '/'), $target);
        }
        if (isset($this->plugins[$info->id])) {
            $this->pending[$info->id] = $target;
            return $this->describe($info->id) + ['installed' => $info->version];
        }
        return $this->load($target, 'user') + ['installed' => $info->version];
    }

    public function remove(string $id): bool
    {
        $p = $this->plugins[$id] ?? throw new \InvalidArgumentException("no plugin '$id'");
        if ($this->userDir === null || $p['info']->source !== 'user') {
            throw new \RuntimeException("{$p['info']->name} is not one of the installed plugins (the project's own stay in the project)");
        }
        $this->forget($id);
        self::removeTree($this->userDir . '/' . $id);
        unset($this->plugins[$id], $this->pending[$id]);
        return true;
    }

    /** @return array<string, mixed> */
    public function enable(string $id, bool $on): array
    {
        $state = $this->state();
        $state['disabled'] = array_values(array_diff($state['disabled'], [$id]));
        if (!$on) {
            $state['disabled'][] = $id;
        }
        $this->saveState($state);
        $p = $this->plugins[$id] ?? throw new \InvalidArgumentException("no plugin '$id'");
        if (!$on) {
            $this->forget($id);
            $this->plugins[$id]['status'] = 'disabled';
        } elseif ($p['status'] === 'disabled') {
            unset($this->plugins[$id]);
            return $this->load($p['info']->dir, $p['info']->source);
        }
        return $this->describe($id);
    }

    /** @return array<string, mixed> */
    public function settings(PluginInfo $plugin): array
    {
        return $this->state()['settings'][$plugin->id] ?? [];
    }

    public function setSetting(PluginInfo $plugin, string $name, mixed $value): void
    {
        $state = $this->state();
        $state['settings'][$plugin->id][$name] = $value;
        $this->saveState($state);
    }

    /** @return array{disabled: list<string>, settings: array<string, array<string, mixed>>} */
    private function state(): array
    {
        if ($this->state === null) {
            $raw = $this->userDir !== null ? json_decode((string)@file_get_contents($this->userDir . '/plugins.json'), true) : null;
            $this->state = ['disabled' => array_values(array_map('strval', (array)($raw['disabled'] ?? []))),
                'settings' => is_array($raw['settings'] ?? null) ? $raw['settings'] : []];
        }
        return $this->state;
    }

    /** @param array{disabled: list<string>, settings: array<string, array<string, mixed>>} $state */
    private function saveState(array $state): void
    {
        $this->state = $state;
        if ($this->userDir !== null) {
            @mkdir($this->userDir, 0777, true);
            file_put_contents($this->userDir . '/plugins.json', json_encode($state, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
        }
    }

    /** A plugin's folder copied, without what only its development needs (vendor/, var/, .git/, tests/). */
    private static function copyTree(string $from, string $to): void
    {
        @mkdir($to, 0777, true);
        foreach (scandir($from) ?: [] as $name) {
            if ($name === '.' || $name === '..' || in_array($name, ['vendor', 'var', '.git', 'tests', 'node_modules'], true)) {
                continue;
            }
            is_dir("$from/$name") ? self::copyTreeAll("$from/$name", "$to/$name") : copy("$from/$name", "$to/$name");
        }
    }

    private static function copyTreeAll(string $from, string $to): void
    {
        @mkdir($to, 0777, true);
        foreach (scandir($from) ?: [] as $name) {
            if ($name !== '.' && $name !== '..') {
                is_dir("$from/$name") ? self::copyTreeAll("$from/$name", "$to/$name") : copy("$from/$name", "$to/$name");
            }
        }
    }

    private static function removeTree(string $dir): void
    {
        foreach (scandir($dir) ?: [] as $name) {
            if ($name !== '.' && $name !== '..') {
                is_dir("$dir/$name") && !is_link("$dir/$name") ? self::removeTree("$dir/$name") : @unlink("$dir/$name");
            }
        }
        @rmdir($dir);
    }
}
