<?php

namespace App;

use JumPHP\Async\Task;
use JumPHP\Native\Dialog;
use JumPHP\Native\FilePicker;
use JumPHP\Native\Native;
use JumPHP\Native\Notification;
use JumPHP\Tauri\App;
use JumPHP\Tauri\AppMain;
use JumPHP\Tauri\NativeEvent;

/**
 * Hello Desktop: a notepad. The page (ui/) is HTML; everything it does goes through PHP here: the file it edits
 * (opened / saved with the system's dialogs), the clock, the dropped files, the question before quitting.
 */
final class Main implements AppMain
{
    public const MAX_FILE = 1048576;

    private App $app;
    private ?string $file = null;
    private bool $dirty = false;
    private int $saves = 0;
    private int $menuClicks = 0;

    public function boot(App $app): void
    {
        $this->app = $app;

        $app->command('info', fn (): array => $this->info());
        $app->command('greet', function (mixed $p): string {
            $name = trim((string)(is_array($p) ? ($p['name'] ?? '') : ''));
            return sprintf('Hello %s, this is PHP %s.', $name === '' ? 'you' : $name, PHP_VERSION);
        });
        $app->command('notes.changed', function (mixed $p): bool {
            $this->dirty = (bool)(is_array($p) ? ($p['dirty'] ?? true) : true);
            $this->updateTitle();
            return $this->dirty;
        });
        $app->command('notes.open', fn (): Task => $this->open());
        $app->command('notes.save', fn (mixed $p): Task => $this->save((string)(is_array($p) ? ($p['text'] ?? '') : ''), false));
        $app->command('notes.saveAs', fn (mixed $p): Task => $this->save((string)(is_array($p) ? ($p['text'] ?? '') : ''), true));
        $app->command('notify', fn (): bool => Notification::show('Hello Desktop', "PHP says hi at " . date('H:i:s')));

        // PHP -> page every second (a ReactPHP timer: the app's loop runs one turn a frame)
        $app->gameLoop->addPeriodicTimer(1.0, fn () => $app->send('clock', [
            'time' => date('H:i:s'),
            'uptime' => (int)$app->time(),
            'memory' => round(memory_get_usage() / 1048576, 1),
        ]));

        // files dropped on the window: PHP looks at them
        $app->window()->on('drop', fn (array $paths) => $app->window()->send('dropped', array_map(static fn (string $p): array => [
            'name' => basename($p),
            'size' => is_file($p) ? filesize($p) : null,
            'dir' => is_dir($p),
        ], $paths)));

        // closing with unsaved text asks first: PHP decides
        $app->window()->on('close-requested', function (NativeEvent $e): void {
            if (!$this->dirty) {
                return;
            }
            $e->preventDefault();
            Dialog::confirm('Your note is not saved. Quit anyway?', 'Hello Desktop', 'Quit', 'Keep editing')
                ->then(fn (mixed $yes) => $yes ? $this->app->quit() : null);
        });

        // the window's menu (on macOS, the menu bar): the page does what the items say (it holds the text)
        $app->window()->setMenu([
            ['text' => 'File', 'items' => [
                ['id' => 'open', 'text' => 'Open...', 'accelerator' => 'CmdOrCtrl+O'],
                ['id' => 'save', 'text' => 'Save', 'accelerator' => 'CmdOrCtrl+S'],
                ['id' => 'saveAs', 'text' => 'Save as...', 'accelerator' => 'CmdOrCtrl+Shift+S'],
                '-',
                ['role' => 'quit'],
            ]],
            ['text' => 'Edit', 'items' => [['role' => 'undo'], ['role' => 'redo'], '-', ['role' => 'cut'], ['role' => 'copy'], ['role' => 'paste'], ['role' => 'select_all']]],
            ['text' => 'View', 'items' => [['id' => 'wrap', 'text' => 'Wrap lines', 'checked' => true]]],
        ]);
        $app->window()->on('menu', function (string $item, ?bool $checked): void {
            $this->menuClicks++;
            $this->app->window()->send('menu', ['item' => $item, 'checked' => $checked]);
        });

        $app->on('ready', fn () => $this->updateTitle());
    }

    /** @return array<string, mixed> */
    public function info(): array
    {
        return [
            'php' => PHP_VERSION,
            'os' => PHP_OS_FAMILY . ' ' . php_uname('r'),
            'sapi' => PHP_SAPI,
            'runtime' => $this->app->runtime()->version(),
            'native' => Native::name(),
            'loop' => (new \ReflectionClass($this->app->gameLoop->getLoop()))->getShortName(),
            'file' => $this->file,
        ];
    }

    public function file(): ?string
    {
        return $this->file;
    }

    public function menuClicks(): int
    {
        return $this->menuClicks;
    }

    public function isDirty(): bool
    {
        return $this->dirty;
    }

    private function open(): Task
    {
        $result = new Task();
        FilePicker::open(['title' => 'Open a note', 'filters' => ['Text' => ['txt', 'md', 'log', 'json', 'php'], 'All files' => ['*']]])
            ->then(function (mixed $path) use ($result): void {
                if (!is_string($path)) {
                    $result->settle(null);      // cancelled
                    return;
                }
                if (!is_file($path) || filesize($path) > self::MAX_FILE) {
                    $result->settle(null, new \RuntimeException(basename($path) . ': not a text file of 1 MB or less'));
                    return;
                }
                $this->file = $path;
                $this->dirty = false;
                $this->updateTitle();
                $result->settle(['path' => $path, 'name' => basename($path), 'text' => (string)file_get_contents($path)]);
            }, fn (\Throwable $e) => $result->settle(null, $e));
        return $result;
    }

    private function save(string $text, bool $choose): Task
    {
        $result = new Task();
        $write = function (?string $path) use ($text, $result): void {
            if ($path === null) {
                $result->settle(null);
                return;
            }
            if (@file_put_contents($path, $text) === false) {
                $result->settle(null, new \RuntimeException("cannot write $path"));
                return;
            }
            $this->file = $path;
            $this->dirty = false;
            $this->saves++;
            $this->updateTitle();
            $result->settle(['path' => $path, 'name' => basename($path), 'bytes' => strlen($text), 'saves' => $this->saves]);
        };
        if (!$choose && $this->file !== null) {
            $write($this->file);
            return $result;
        }
        FilePicker::save(['title' => 'Save the note', 'file_name' => $this->file !== null ? basename($this->file) : 'note.txt'])
            ->then(fn (mixed $path) => $write(is_string($path) ? $path : null), fn (\Throwable $e) => $result->settle(null, $e));
        return $result;
    }

    private function updateTitle(): void
    {
        $name = $this->file !== null ? basename($this->file) : 'Untitled';
        $this->app->window()->setTitle(($this->dirty ? '• ' : '') . $name . ' — Hello Desktop');
    }
}
