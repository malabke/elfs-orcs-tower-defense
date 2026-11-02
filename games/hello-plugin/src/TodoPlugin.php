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
 * - a setting kept by the IDE (FIXME only), a listener (the list follows the saved files).
 */
final class TodoPlugin implements Plugin
{
    /** @var list<array{path: string, line: int, tag: string, text: string}>|null null: not scanned yet */
    private ?array $todos = null;
    private string $filter = '';
    private ToolWindow $window;

    public function activate(Api $ide): void
    {
        $this->window = $ide->toolWindow('todos', 'TODOs', fn () => $this->render($ide), ['side' => 'right', 'icon' => 'check']);

        $ide->action('count', 'Count the TODOs of This File', function (EditorState $at) use ($ide) {
            $n = count(Todos::in($at->text));
            $ide->notify($at->path === null ? 'No file is open.' : "$n TODO / FIXME in " . basename($at->path) . '.');
        }, ['kbd' => 'Ctrl+Alt+T', 'icon' => 'check']);

        $ide->diagnostics('*', fn (string $path, string $text) => array_values(array_map(
            fn ($t) => ['line' => $t['line'], 'column' => $t['column'], 'message' => "FIXME: {$t['text']}", 'severity' => 'warning'],
            array_filter(Todos::in($text), fn ($t) => $t['tag'] === 'FIXME'),
        )));

        $ide->completion('*.php', fn (EditorState $at) => preg_match('/(\/\/|\*)\s*@?t?o?d?o?$/i', $at->before())
            ? [['label' => '@todo', 'insert' => '@todo ', 'detail' => 'a TODO the TODOs window lists']] : []);

        $ide->on('file.saved', function (string $path): void {
            if ($this->todos !== null) {
                $this->todos = null;           // scanned again when the window draws
                $this->window->refresh();
            }
        });
        $ide->on('files.changed', fn () => $this->todos = null);
    }

    private function render(Api $ide): array
    {
        $this->todos ??= $this->scan($ide);
        $fixmeOnly = (bool)$ide->setting('fixmeOnly', false);
        $shown = array_values(array_filter($this->todos, fn ($t) => (!$fixmeOnly || $t['tag'] === 'FIXME')
            && ($this->filter === '' || stripos($t['text'] . ' ' . $t['path'], $this->filter) !== false)));
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
