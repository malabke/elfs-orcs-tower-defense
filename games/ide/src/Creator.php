<?php

namespace App;

use JumPHP\Ide\Async\Deferred;
use JumPHP\Ide\Async\Processes;
use JumPHP\Async\Task;

/**
 * New Project. Games (an original one, an Amstrad CPC remake, a port) are jumphp.net projects: the playground makes
 * the GitHub repository from the JumPHP template with the brief, the IDE waits for it, clones and opens it. Desktop
 * apps and hybrid games are local JumPHP projects: the template is cloned, then bin/jumphp app:new / new makes the
 * app's folder.
 */
final class Creator
{
    public const TEMPLATE = 'https://github.com/davidnurdin/jumPhp.git';

    public function __construct(private Main $main, private Playground $playground, private Processes $processes)
    {
    }

    /** @param array<string, mixed> $p {kind, title, description, brief, category, folder, dsk, source} */
    public function create(array $p): Deferred
    {
        $kind = (string)($p['kind'] ?? 'original');
        $title = trim((string)($p['title'] ?? ''));
        if ($title === '') {
            return Deferred::rejected(new \InvalidArgumentException('A title, please.'));
        }
        return in_array($kind, ['original', 'cpc', 'port'], true) ? $this->onPlayground($kind, $title, $p) : $this->local($kind, $title, $p);
    }

    private function onPlayground(string $kind, string $title, array $p): Deferred
    {
        $brief = trim((string)($p['brief'] ?? ''));
        if ($kind === 'port' && !empty($p['source'])) {
            $brief = "Port of the open-source game at " . $p['source'] . ".\n\n" . $brief;
        }
        if ($kind === 'cpc' && !empty($p['dsk'])) {
            // the disc stays on this computer: only its name goes into the brief
            $brief = "Remake of the Amstrad CPC game on the disc " . basename((string)$p['dsk']) . " (kept on the author's computer, never committed).\n\n" . $brief;
        }
        $this->progress('Creating the repository on jumphp.net…');
        return self::deferred($this->playground->create([
            'title' => $title, 'description' => (string)($p['description'] ?? ''), 'category' => $p['category'] ?? null,
            'kind' => $kind, 'brief' => $brief,
        ]))->then(fn (array $project) => $this->waitReady((string)$project['slug'], 0))
            ->then(function (array $project) use ($p, $kind) {
                $this->progress('Cloning ' . ($project['repoUrl'] ?? $project['slug']) . '…');
                return $this->main->cloneProject((string)$project['cloneUrl'], (string)$project['slug'], $this->folder($p))
                    ->then(function (string $path) use ($p, $kind) {
                        if ($kind === 'cpc' && !empty($p['dsk'])) {
                            $this->main->saveProjectSettings($path, ['dsk' => (string)$p['dsk']]);
                        }
                        return $this->main->openProject($path);
                    });
            });
    }

    /** jumphp.net makes the repository in the background: ask again every 2 s (a minute at most). */
    private function waitReady(string $slug, int $tries): Deferred
    {
        return self::deferred($this->playground->project($slug))->then(function (array $project) use ($slug, $tries) {
            if (($project['status'] ?? '') !== 'creating' && !empty($project['cloneUrl'])) {
                if (in_array($project['status'] ?? '', ['error', 'failed'], true)) {
                    throw new \RuntimeException('jumphp.net could not create the project: ' . ($project['statusMessage'] ?? 'unknown error'));
                }
                return $project;
            }
            if ($tries > 30) {
                throw new \RuntimeException('jumphp.net is still creating the repository: open it from the project list in a moment.');
            }
            $later = new Deferred();
            \JumPHP\Tauri\App::current()?->gameLoop->addTimer(2.0, fn () => $later->resolve(null));
            return $later->then(fn () => $this->waitReady($slug, $tries + 1));
        });
    }

    private function local(string $kind, string $title, array $p): Deferred
    {
        $id = trim((string)preg_replace('/[^a-z0-9]+/', '-', strtolower($title)), '-') ?: 'app';
        if (!preg_match('/^[a-z]/', $id)) {
            $id = 'app-' . $id;
        }
        $parent = $this->folder($p);
        @mkdir($parent, 0777, true);
        $root = "$parent/$id";
        if (file_exists($root)) {
            return Deferred::rejected(new \RuntimeException("$root already exists"));
        }
        $this->progress('Getting the JumPHP template…');
        return $this->run(['git', 'clone', '--depth', '1', self::TEMPLATE, $root], $parent)
            ->then(function () use ($root) {
                // a fresh history: the project is the user's
                exec('rm -rf ' . escapeshellarg("$root/.git"));
                return $this->run(['git', 'init', '-q', '-b', 'main'], $root);
            })
            ->then(function () use ($root, $id, $title, $kind) {
                $this->progress($kind === 'app' ? "bin/jumphp app:new {$id}…" : "bin/jumphp new {$id}…");
                return $this->run(['bin/jumphp', $kind === 'app' ? 'app:new' : 'new', $id, $title], $root);
            })
            ->then(function () use ($root, $id, $kind, $p) {
                $game = "$root/games/$id";
                if ($kind === 'hybrid' && is_file("$game/config/game.yaml")) {
                    $yaml = (string)file_get_contents("$game/config/game.yaml");
                    if (!preg_match('/^\s*ui:/m', $yaml)) {
                        file_put_contents("$game/config/game.yaml", preg_replace('/^game:\n/', "game:\n  ui: hybrid                  # an SDL game with HTML windows (docs/tauri.md)\n", $yaml, 1));
                    }
                }
                $brief = trim((string)($p['brief'] ?? ''));
                file_put_contents("$game/BRIEF.md", "# " . ($p['title'] ?? $id) . "\n\n" . ($p['description'] ?? '') . "\n\n" . ($brief !== '' ? $brief : 'What it should be: write it here.') . "\n");
                return $this->main->openProject($root);
            });
    }

    /** @param list<string> $command */
    private function run(array $command, string $cwd): Deferred
    {
        $started = $this->processes->start($command, $cwd, [], fn (string $chunk) => $this->main->send('clone.output', ['chunk' => $chunk]));
        return $started['done']->then(function (array $r) use ($command) {
            if ($r['code'] !== 0) {
                throw new \RuntimeException(implode(' ', array_slice($command, 0, 2)) . ' failed: ' . trim(substr($r['output'], -600)));
            }
            return $r;
        });
    }

    private function folder(array $p): string
    {
        $folder = trim((string)($p['folder'] ?? ''));
        return rtrim($folder !== '' ? $folder : $this->main->projectsDir(), '/');
    }

    private function progress(string $text): void
    {
        $this->main->send('clone.output', ['chunk' => $text]);
    }

    private static function deferred(Task $task): Deferred
    {
        $d = new Deferred();
        $task->then(fn ($v) => $d->resolve($v), fn (\Throwable $e) => $d->reject($e));
        return $d;
    }
}
