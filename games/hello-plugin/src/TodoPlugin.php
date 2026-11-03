<?php

namespace JumphpPlugins\HelloPlugin;

use JumPHP\Ide\Plugin\Api;
use JumPHP\Ide\Plugin\EditorState;
use JumPHP\Ide\Plugin\Plugin;
use JumPHP\Ide\Plugin\ToolWindow;
use JumPHP\Ide\Plugin\Ui;

/**
 * TODO Finder: what a plugin can add to the JumPHP IDE, in one small example.
 * - a tool window (right): the project's TODO / FIXME comments, a filter, a click opens the line;
 * - an action (Tools menu, Find Action, Ctrl+Alt+T): how many in the open file;
 * - problems: a FIXME is underlined as a warning while one types;
 * - completion: "@todo" in a PHP comment;
 * - a tool for Claude (the IDE's MCP server): list_todos;
 * - a settings page (Tools > Plugins… > Settings…): FIXME only, the FIXME underlined or not, the most listed;
 * - listeners: the list follows the saved files and the settings.
 */
final class TodoPlugin implements Plugin
{
    /** @var list<array{path: string, line: int, tag: string, text: string}>|null null: not scanned yet */
    private ?array $todos = null;
    private string $filter = '';
    private ToolWindow $window;

    public function activate(Api $ide): void
    {
        $ide->settings([
            'fixmeOnly' => ['label' => 'FIXME only', 'type' => 'bool', 'default' => false, 'description' => 'the TODOs window lists only the FIXME comments'],
            'warnFixme' => ['label' => 'Underline the FIXME comments', 'type' => 'bool', 'default' => true, 'description' => 'as warnings in the editor and the Problems window'],
            'max' => ['label' => 'At most', 'type' => 'number', 'default' => 500, 'min' => 10, 'max' => 10000, 'description' => 'comments listed in the window'],
        ]);
        $this->window = $ide->toolWindow('todos', 'TODOs', fn () => $this->render($ide), ['side' => 'right', 'icon' => 'check']);

        $ide->action('count', 'Count the TODOs of This File', function (EditorState $at) use ($ide) {
            $n = count(Todos::in($at->text));
            $ide->notify($at->path === null ? 'No file is open.' : "$n TODO / FIXME in " . basename($at->path) . '.');
        }, ['kbd' => 'Ctrl+Alt+T', 'icon' => 'check']);

        $ide->diagnostics('*', fn (string $path, string $text) => !$ide->setting('warnFixme') ? [] : array_values(array_map(
            fn ($t) => ['line' => $t['line'], 'column' => $t['column'], 'message' => "FIXME: {$t['text']}", 'severity' => 'warning'],
            array_filter(Todos::in($text), fn ($t) => $t['tag'] === 'FIXME'),
        )));

        $ide->completion('*.php', fn (EditorState $at) => preg_match('/(\/\/|\*)\s*@?t?o?d?o?$/i', $at->before())
            ? [['label' => '@todo', 'insert' => '@todo ', 'detail' => 'a TODO the TODOs window lists']] : []);

        $ide->mcpTool('list_todos', 'The TODO and FIXME comments of the project: path, line, tag, text. Use it to find what is left to do.',
            function (array $args) use ($ide): array {
                $all = $this->todos ??= $this->scan($ide);
                return array_values(array_filter($all, fn ($t) => (empty($args['fixme_only']) || $t['tag'] === 'FIXME')
                    && (empty($args['path']) || str_starts_with($t['path'], (string)$args['path']))));
            },
            ['fixme_only' => ['type' => 'boolean', 'description' => 'only the FIXME ones'],
                'path' => ['type' => 'string', 'description' => 'only under this folder or file of the project, e.g. games/pong/src']]);

        $ide->on('file.saved', function (string $path): void {
            if ($this->todos !== null) {
                $this->todos = null;           // scanned again when the window draws
                $this->window->refresh();
            }
        });
        $ide->on('files.changed', fn () => $this->todos = null);
        $ide->on('setting.changed', fn (string $name) => $this->window->refresh());
    }

    private function render(Api $ide): array
    {
        $this->todos ??= $this->scan($ide);
        $fixmeOnly = (bool)$ide->setting('fixmeOnly');
        $shown = array_slice(array_values(array_filter($this->todos, fn ($t) => (!$fixmeOnly || $t['tag'] === 'FIXME')
            && ($this->filter === '' || stripos($t['text'] . ' ' . $t['path'], $this->filter) !== false))), 0, (int)$ide->setting('max'));
        return Ui::column(
            Ui::row(
                Ui::input('Filter', fn (string $q) => $this->filter = trim($q), $this->filter),
                Ui::button('Refresh', fn () => $this->todos = null, 'refresh'),
            ),
            Ui::checkbox('FIXME only', $fixmeOnly, fn (bool $on) => $ide->setSetting('fixmeOnly', $on)),
            Ui::text(count($shown) . ' of ' . count($this->todos), true),
            Ui::list(array_map(fn ($t) => Ui::item("{$t['tag']}: {$t['text']}", fn () => $ide->openFile($t['path'], $t['line']),
                "{$t['path']}:{$t['line']}", $t['tag'] === 'FIXME' ? 'warning' : 'check'), $shown), 'No TODO in the project'),
        );
    }

    /** @return list<array{path: string, line: int, tag: string, text: string}> */
    private function scan(Api $ide): array
    {
        $all = [];
        foreach (array_keys($ide->workspace()->files()) as $path) {
            if (!preg_match('/\.(php|js|ts|css|html|md|ya?ml|json|txt)$/i', $path)) {
                continue;
            }
            foreach (Todos::in($ide->workspace()->read($path)) as $t) {
                $all[] = ['path' => $path, 'line' => $t['line'], 'tag' => $t['tag'], 'text' => $t['text']];
            }
        }
        return $all;
    }
}
