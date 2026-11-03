<?php

namespace JumPHP\Ide\Testing;

use JumPHP\Ide\Ide;
use JumPHP\Ide\Plugin\EditorState;
use JumPHP\Ide\Plugin\PluginHost;
use JumPHP\Ide\Workspace\LocalWorkspace;
use PHPUnit\Framework\TestCase;

/**
 * Tests of an IDE plugin: the IDE's core on a project of a few files (made by each test), the plugin loaded in it,
 * and what a user does: run an action, look at a tool window and click in it, ask completion, type a file and
 * read its problems, save, let time pass. No window, no Docker.
 *
 *     final class TodoPluginTest extends PluginTestCase
 *     {
 *         public function testTheToolWindowListsTheTodos(): void
 *         {
 *             $this->project(['src/A.php' => "<?php\n// TODO: fix\n"]);
 *             self::assertStringContainsString('fix', $this->toolText('todos'));
 *             $this->click('todos', 'fix');
 *             self::assertSame(['path' => 'src/A.php', 'line' => 2], $this->opened());
 *         }
 *     }
 */
abstract class PluginTestCase extends TestCase
{
    protected Ide $ide;
    protected PluginHost $plugins;
    protected string $root;

    /** @var list<array{string, array<string, mixed>}> the events the IDE sent to its interface */
    protected array $events = [];

    /** The plugin's folder (its composer.json): by default the folder above tests/. */
    protected function pluginDir(): string
    {
        return dirname((string)(new \ReflectionClass(static::class))->getFileName(), 2);
    }

    protected function setUp(): void
    {
        $this->root = sys_get_temp_dir() . '/jumphp-plugin-test-' . bin2hex(random_bytes(4));
        mkdir($this->root, 0777, true);
        $this->project([]);
    }

    protected function tearDown(): void
    {
        self::removeTree($this->root);
    }

    /** A fresh project made of these files (path => content), the plugin loaded in it. @param array<string, string> $files */
    protected function project(array $files): void
    {
        self::removeTree($this->root);
        mkdir("$this->root/project", 0777, true);
        mkdir("$this->root/plugins", 0777, true);
        foreach ($files as $path => $content) {
            @mkdir(dirname("$this->root/project/$path"), 0777, true);
            file_put_contents("$this->root/project/$path", $content);
        }
        $this->events = [];
        $this->ide = new Ide(new LocalWorkspace("$this->root/project"), null, function (string $event, array $payload): void {
            $this->events[] = [$event, $payload];
        });
        $this->plugins = $this->ide->enablePlugins("$this->root/plugins");
        $loaded = $this->plugins->load($this->pluginDir(), 'dev');
        if (($loaded['status'] ?? '') !== 'active') {
            self::fail('the plugin did not load: ' . ($loaded['error'] ?? 'unknown error'));
        }
        while (!$this->ide->index->refresh(1.0)) {
            // the PHP index, whole
        }
    }

    /** The id the IDE gives a plugin's action / tool window / run configuration. */
    protected function id(string $id): string
    {
        return str_starts_with($id, 'plugin:') ? $id : 'plugin:' . (string)json_decode((string)file_get_contents($this->pluginDir() . '/composer.json'), true)['extra']['jumphp-ide']['id'] . ":$id";
    }

    private function pluginId(): string
    {
        return (string)json_decode((string)file_get_contents($this->pluginDir() . '/composer.json'), true)['extra']['jumphp-ide']['id'];
    }

    /** @return array<string, mixed> the plugin's settings page as the IDE shows it: name => value (its defaults until changed) */
    protected function settingsPage(): array
    {
        $me = array_values(array_filter($this->ide->handle('plugins.list', []), fn ($p) => $p['id'] === $this->pluginId()))[0] ?? [];
        return array_column($me['settings'] ?? [], 'value', 'name');
    }

    /** The user changes a field of the settings page and saves (on('setting.changed') listeners run): the value kept. */
    protected function changeSetting(string $name, mixed $value): mixed
    {
        return $this->ide->handle('plugins.setting', ['id' => $this->pluginId(), 'name' => $name, 'value' => $value])['value'];
    }

    /** Runs an action, the editor on $path (its text read from the project) at $line. */
    protected function action(string $id, ?string $path = null, int $line = 1, int $column = 1, string $selection = ''): mixed
    {
        return $this->plugins->runAction($this->id($id), new EditorState($path, $path !== null ? $this->read($path) : '', $line, $column, $selection));
    }

    /** @return array<string, mixed> a tool window's content (Ui nodes, callbacks numbered) */
    protected function tool(string $id): array
    {
        return $this->plugins->view($this->id($id));
    }

    /** A tool window's texts, one per line (labels, hints, texts, headings, buttons...). */
    protected function toolText(string $id): string
    {
        return implode("\n", self::texts($this->tool($id)));
    }

    /** Clicks what shows this label (a button, a link, a list item, a check box) in a tool window. @return array<string, mixed> the new content */
    protected function click(string $tool, string $label): array
    {
        $node = self::find($this->tool($tool), fn ($n) => isset($n['label']) && str_contains((string)$n['label'], $label)
            && (isset($n['onClick']) || isset($n['onChange'])));
        self::assertNotNull($node, "nothing to click labelled '$label' in $tool:\n" . $this->toolText($tool));
        return isset($node['onChange'])
            ? $this->plugins->callback($this->id($tool), (int)$node['onChange'], empty($node['checked']))
            : $this->plugins->callback($this->id($tool), (int)$node['onClick'], null);
    }

    /** Types text into the input showing this placeholder and presses Enter. @return array<string, mixed> */
    protected function submit(string $tool, string $placeholder, string $text): array
    {
        $node = self::find($this->tool($tool), fn ($n) => ($n['type'] ?? '') === 'input' && str_contains((string)$n['placeholder'], $placeholder));
        self::assertNotNull($node, "no input '$placeholder' in $tool");
        return $this->plugins->callback($this->id($tool), (int)$node['onSubmit'], $text);
    }

    /** The plugins' completion labels where '|' is in $text (the caret; the text is not written). @return list<string> */
    protected function complete(string $path, string $text): array
    {
        $offset = (int)strpos($text, '|');
        $before = substr($text, 0, $offset);
        $line = substr_count($before, "\n") + 1;
        $column = mb_strlen(substr($before, (int)strrpos("\n" . $before, "\n"))) + 1;
        $state = new EditorState($path, substr($text, 0, $offset) . substr($text, $offset + 1), $line, $column);
        return array_column($this->plugins->complete($state), 'label');
    }

    /** @return list<array<string, mixed>> the plugins' problems in this text */
    protected function problems(string $path, string $text): array
    {
        return $this->plugins->diagnostics($path, $text);
    }

    /**
     * Calls one of the plugin's tools for Claude, as the IDE's MCP server does ($name: the plugin's own name, or the
     * whole plugin_<id>_<name>); a Deferred is waited for while it settles at once.
     *
     * @param array<string, mixed> $args
     */
    protected function mcp(string $name, array $args = []): mixed
    {
        $full = str_starts_with($name, 'plugin_') ? $name
            : 'plugin_' . str_replace('-', '_', (string)json_decode((string)file_get_contents($this->pluginDir() . '/composer.json'), true)['extra']['jumphp-ide']['id']) . '_' . $name;
        $value = $this->plugins->callMcpTool($full, $args);
        if ($value instanceof \JumPHP\Ide\Async\Deferred) {
            self::assertTrue($value->isDone(), "the tool $full did not answer at once");
            if ($value->error() !== null) {
                throw $value->error();
            }
            return $value->result();
        }
        return $value;
    }

    /** @return list<string> the MCP names of the tools Claude gets from the plugins */
    protected function mcpTools(): array
    {
        return array_column($this->plugins->mcpTools(), 'name');
    }

    /** Writes a file as the editor saves it (the plugins hear file.saved). */
    protected function save(string $path, string $content): void
    {
        $this->ide->handle('file.write', ['path' => $path, 'content' => $content]);
    }

    protected function read(string $path): string
    {
        return $this->ide->workspace->read($path);
    }

    /** Lets $seconds pass for the plugins' timers. */
    protected function wait(float $seconds): void
    {
        $this->plugins->tick(microtime(true) + $seconds);
    }

    /** @return list<array<string, mixed>> what the plugin asked the interface (notify, open, run, show, refresh) */
    protected function ui(?string $do = null): array
    {
        $out = [];
        foreach ($this->events as [$event, $payload]) {
            if ($event === 'plugins.ui' && ($do === null || ($payload['do'] ?? '') === $do)) {
                $out[] = $payload;
            }
        }
        return $out;
    }

    /** @return list<string> the notifications' messages */
    protected function notifications(): array
    {
        return array_map(fn ($m) => (string)$m['message'], $this->ui('notify'));
    }

    /** @return array{path: string, line: ?int}|null the last file the plugin opened */
    protected function opened(): ?array
    {
        $last = $this->ui('open');
        $m = end($last);
        return $m === false ? null : ['path' => (string)$m['path'], 'line' => $m['line'] ?? null];
    }

    /** @return list<array{where: string, message: string}> the errors of the plugin (reported, not thrown) */
    protected function errors(): array
    {
        $out = [];
        foreach ($this->events as [$event, $payload]) {
            if ($event === 'ide.error') {
                $out[] = ['where' => (string)$payload['where'], 'message' => (string)$payload['message']];
            }
        }
        return $out;
    }

    /** @param array<string, mixed> $node @return list<string> */
    private static function texts(array $node): array
    {
        $out = [];
        foreach (['text', 'label', 'hint', 'placeholder'] as $key) {
            if (isset($node[$key]) && is_string($node[$key]) && $node[$key] !== '') {
                $out[] = $node[$key];
            }
        }
        foreach (['children', 'items'] as $key) {
            foreach ((array)($node[$key] ?? []) as $child) {
                if (is_array($child)) {
                    array_push($out, ...self::texts($child));
                }
            }
        }
        if (($node['type'] ?? '') === 'list' && empty($node['items'])) {
            $out[] = (string)($node['empty'] ?? '');
        }
        return $out;
    }

    /** @param array<string, mixed> $node @param callable(array<string, mixed>): bool $match @return array<string, mixed>|null */
    private static function find(array $node, callable $match): ?array
    {
        if ($match($node)) {
            return $node;
        }
        foreach (['children', 'items'] as $key) {
            foreach ((array)($node[$key] ?? []) as $child) {
                if (is_array($child) && ($found = self::find($child, $match)) !== null) {
                    return $found;
                }
            }
        }
        return null;
    }

    private static function removeTree(string $dir): void
    {
        if (!is_dir($dir)) {
            return;
        }
        foreach (scandir($dir) ?: [] as $name) {
            if ($name !== '.' && $name !== '..') {
                is_dir("$dir/$name") && !is_link("$dir/$name") ? self::removeTree("$dir/$name") : @unlink("$dir/$name");
            }
        }
        @rmdir($dir);
    }
}
