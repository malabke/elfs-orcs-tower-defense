<?php

namespace JumPHP\Ide\Plugin;

use JumPHP\Ide\Async\Deferred;
use JumPHP\Ide\Ide;
use JumPHP\Ide\Workspace\MemoryWorkspace;

/**
 * The web IDE's plugins, run in the visitor's browser (ide/ui/js/plugin-worker.js: the WebAssembly PHP of jumphp.net's
 * shared engine, in a Web Worker): never on the server. The same PluginHost as the desktop app, on a copy of the
 * project's files (BrowserWorkspace); the installed plugins are unpacked in the worker's /plugins (kept in the
 * browser), the project's own (.jumphp/plugins/) are written there from the project's files.
 *
 * One call: the page writes {name, payload} to a file, handle() answers {result | error, events, changes} in another.
 * It never throws (zend_eval_string has no try around it in the worker).
 */
final class BrowserHost
{
    public const USER_DIR = '/plugins';
    public const PROJECT_DIR = '/project-plugins';

    private static ?Ide $ide = null;
    private static ?BrowserWorkspace $workspace = null;
    /** @var list<array{0: string, 1: mixed}> */
    private static array $events = [];

    public static function handle(string $in, string $out): void
    {
        $answer = [];
        try {
            $request = json_decode((string)file_get_contents($in), true, 512, JSON_THROW_ON_ERROR);
            $answer['result'] = self::call((string)($request['name'] ?? ''), (array)($request['payload'] ?? []));
        } catch (\Throwable $e) {
            $answer['error'] = $e->getMessage();
        }
        $answer['events'] = self::$events;
        $answer['changes'] = self::$workspace?->changes() ?? [];
        self::$events = [];
        file_put_contents($out, json_encode($answer, JSON_INVALID_UTF8_SUBSTITUTE | JSON_PARTIAL_OUTPUT_ON_ERROR));
    }

    /** @param array<string, mixed> $p */
    private static function call(string $name, array $p): mixed
    {
        if ($name === 'host.boot') {
            return self::boot((array)($p['files'] ?? []), (string)($p['name'] ?? 'project'));
        }
        $ide = self::$ide ?? throw new \RuntimeException('the plugins are not started');
        $host = $ide->plugins ?? throw new \RuntimeException('no plugins');
        switch ($name) {
            case 'host.files':        // files loaded or changed by the page (null: deleted)
                foreach ((array)($p['files'] ?? []) as $path => $content) {
                    self::$workspace?->sync((string)$path, $content === null ? null : (string)$content);
                }
                return true;
            case 'host.saved':         // saved in the editor: the plugins' file.saved
                self::$workspace?->sync((string)$p['path'], (string)($p['content'] ?? ''));
                $host->dispatch('file.saved', (string)$p['path']);
                return true;
            case 'host.changed':
                $host->dispatch('files.changed');
                return true;
            case 'host.tick':
                $host->tick();
                return true;
            case 'host.load':          // a plugin's folder in the worker (an installed one, or the project's own)
                return $host->load((string)($p['dir'] ?? ''), (string)($p['source'] ?? 'user'));
            case 'host.loadProject':   // the project's .jumphp/plugins/<id>/ copied from its files, then loaded
                return self::loadProjectPlugins();
        }
        $result = $ide->handle($name, $p);
        if ($result instanceof Deferred) {
            throw new \RuntimeException("$name answers later: not available in the browser");
        }
        return $result;
    }

    /** @param array<string, string> $files the project's text files */
    private static function boot(array $files, string $name): array
    {
        self::$workspace = new BrowserWorkspace(new MemoryWorkspace(array_map('strval', $files), $name));
        self::$ide = new Ide(self::$workspace, null, static function (string $event, array $payload): void {
            self::$events[] = [$event, $payload];
        });
        @mkdir(self::USER_DIR, 0777, true);
        self::$ide->enablePlugins(self::USER_DIR);
        return ['plugins' => self::$ide->plugins?->listing() ?? []];
    }

    /** @return list<array<string, mixed>> */
    private static function loadProjectPlugins(): array
    {
        $ws = self::$workspace ?? throw new \RuntimeException('no project');
        $loaded = [];
        $ids = [];
        foreach (array_keys($ws->files(true)) as $path) {
            if (preg_match('#^\.jumphp/plugins/([a-z0-9][a-z0-9-]*)/(.+)$#', (string)$path, $m)) {
                $target = self::PROJECT_DIR . "/{$m[1]}/{$m[2]}";
                @mkdir(dirname($target), 0777, true);
                file_put_contents($target, $ws->read((string)$path));
                $ids[$m[1]] = true;
            }
        }
        foreach (array_keys($ids) as $id) {
            $loaded[] = self::$ide?->plugins?->load(self::PROJECT_DIR . "/$id", 'project');
        }
        return array_values(array_filter($loaded));
    }
}
