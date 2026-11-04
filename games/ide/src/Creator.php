<?php

namespace App;

use JumPHP\Ide\Async\Deferred;
use JumPHP\Ide\Async\Processes;
use JumPHP\Ide\Git\Git;
use JumPHP\Async\Task;

/**
 * New Project. Every project gets its own GitHub repository, on the user's account (never JumPHP's: the template is
 * only where its files come from). Games (an original one, an Amstrad CPC remake, a port, a hybrid game) and
 * applications (graphic, mobile, desktop, CLI) are jumphp.net projects: the playground makes the repository from the
 * JumPHP template with the skeleton of its kind and the brief, the IDE waits for it, clones and opens it; commit / push
 * go there and Publish puts it on jumphp.net. IDE plugins (shared from Tools > Plugins…, not published as a game):
 * the template is cloned, bin/jumphp plugin:new makes the plugin, and the IDE creates the user's public repository
 * with their GitHub token (through jumphp.net) and pushes it.
 */
final class Creator
{
    public const TEMPLATE = 'https://github.com/davidnurdin/jumPhp.git';

    /** The IDE's kinds -> jumphp.net's (hybrid: a game whose game.yaml gets ui: hybrid). */
    public const PLAYGROUND_KINDS = ['original' => 'original', 'cpc' => 'cpc', 'port' => 'port', 'hybrid' => 'original',
        'gui' => 'gui', 'gui-mobile' => 'mobile', 'app' => 'desktop', 'cli' => 'cli'];

    /** The framework's remote gives updates only (bin/jumphp update): nothing can be pushed there. */
    public const NO_PUSH = 'no-push://the-jumphp-framework-only-gives-updates';

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
        if ($this->main->user() === null) {
            return Deferred::rejected(new \RuntimeException('Sign in with jumphp.net first: it creates the project\'s GitHub repository on your account.'));
        }
        if ($kind === 'plugin' || $kind === 'extension') {
            return $this->local($kind, $title, $p);
        }
        if (!isset(self::PLAYGROUND_KINDS[$kind])) {
            return Deferred::rejected(new \InvalidArgumentException("Unknown kind of project: $kind"));
        }
        return $this->onPlayground($kind, $title, $p);
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
            'kind' => self::PLAYGROUND_KINDS[$kind], 'brief' => $brief,
        ]))->then(fn (array $project) => $this->waitReady((string)$project['slug'], 0))
            ->then(function (array $project) use ($p, $kind) {
                $this->progress('Cloning ' . ($project['repoUrl'] ?? $project['slug']) . '…');
                return $this->main->cloneProject((string)$project['cloneUrl'], (string)$project['slug'], $this->folder($p))
                    ->then(function (string $path) use ($p, $kind, $project) {
                        if ($kind === 'cpc' && !empty($p['dsk'])) {
                            $this->main->saveProjectSettings($path, array_filter(['dsk' => (string)$p['dsk'], 'roms' => (string)($p['roms'] ?? '')]));
                        }
                        $this->noPushToJumphp($path);
                        $yaml = $path . '/' . trim((string)($project['gameDir'] ?? 'games/' . $project['slug']), '/') . '/config/project.yaml';
                        $yaml = is_file($yaml) ? $yaml : dirname($yaml) . '/game.yaml';
                        if ($kind !== 'hybrid' || !$this->makeHybrid($yaml)) {
                            return $this->main->openProject($path);
                        }
                        $this->progress('ui: hybrid in game.yaml, pushed…');
                        return $this->git($path)
                            ->then(fn (Git $git) => $git->commit('A hybrid game: SDL + HTML windows (ui: hybrid)', ['.'])->then(fn () => $git->push()))
                            ->then(fn () => $this->main->openProject($path));
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

    /** An IDE plugin: the template, bin/jumphp plugin:new, then the user's own public repository (created here) and a push. */
    private function local(string $kind, string $title, array $p): Deferred
    {
        $extension = $kind === 'extension';
        $id = trim((string)preg_replace('/[^a-z0-9]+/', '-', strtolower($title)), '-') ?: $kind;
        if (!preg_match('/^[a-z]/', $id)) {
            $id = "$kind-$id";
        }
        $parent = $this->folder($p);
        @mkdir($parent, 0777, true);
        $root = "$parent/$id";
        if (file_exists($root)) {
            return Deferred::rejected(new \RuntimeException("$root already exists"));
        }
        $this->progress('Getting the JumPHP template…');
        return $this->run(['git', 'clone', '--depth', '1', self::TEMPLATE, $root], $parent)
            ->then(fn () => $this->run(['git', 'rev-parse', 'HEAD'], $root))
            ->then(function ($head) use ($root) {
                // the JumPHP commit its files come from: the IDE keeps them up to date from there (JumphpUpdater)
                $commit = trim((string)(is_array($head) ? ($head['stdout'] ?? '') : $head));
                if (preg_match('/^[0-9a-f]{40}$/', $commit)) {
                    @mkdir("$root/.jumphp", 0777, true);
                    file_put_contents("$root/" . \JumPHP\Ide\Project\JumphpUpdater::RECORD, json_encode(['repository' => self::TEMPLATE,
                        'commit' => $commit, 'updated' => gmdate('c')], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n");
                }
                // a fresh history: the project is the user's (the IDE, or bin/jumphp update, brings new versions of JumPHP)
                exec('rm -rf ' . escapeshellarg("$root/.git"));
                // one project, one plugin: not JumPHP's examples (the playground's projects start the same way)
                foreach ([...(glob("$root/games/*", GLOB_ONLYDIR) ?: []), ...(glob("$root/apps/*", GLOB_ONLYDIR) ?: []), ...(glob("$root/extensions/*", GLOB_ONLYDIR) ?: [])] as $example) {
                    exec('rm -rf ' . escapeshellarg($example));
                }
                return $this->run(['git', 'init', '-q', '-b', 'main'], $root)
                    ->then(fn () => $this->run(['git', 'remote', 'add', 'jumphp', self::TEMPLATE], $root))
                    ->then(fn () => $this->noPushToJumphp($root));
            })
            ->then(function () use ($root, $id, $title, $extension) {
                $command = $extension ? 'extension:new' : 'plugin:new';
                $this->progress("bin/jumphp $command…");
                return $this->run(['bin/jumphp', $command, $id, $title], $root);
            })
            ->then(function () use ($root, $id, $p, $extension) {
                $brief = trim((string)($p['brief'] ?? ''));
                file_put_contents(($extension ? "$root/extensions/$id" : "$root/apps/$id") . "/BRIEF.md", "# " . ($p['title'] ?? $id) . "\n\n" . ($p['description'] ?? '') . "\n\n" . ($brief !== '' ? $brief : 'What it should be: write it here.') . "\n");
                $this->progress('Creating your GitHub repository…');
                return $this->git($root)->then(fn (Git $git) => $this->createRepository($git, $id, (string)($p['description'] ?? ''))
                    ->then(fn (array $repo) => $git->run(['remote', 'add', 'origin', (string)$repo['clone_url']])->then(fn () => $repo))
                    ->then(fn (array $repo) => $git->commit(($p['title'] ?? $id) . ($extension ? ': a JumPHP engine extension' : ': a JumPHP IDE plugin'), ['.'])->then(fn () => $repo))
                    ->then(function (array $repo) use ($git) {
                        $this->progress('Pushing to ' . $repo['html_url'] . '…');
                        return $git->push();
                    }));
            })
            ->then(fn () => $this->main->openProject($root));
    }

    /** The user's public repository <login>/<name> (GitHub's API, the user's token): <name>-2, -3... when taken. */
    public function createRepository(Git $git, string $name, string $description, int $try = 1): Deferred
    {
        $token = $git->token();
        $http = \JumPHP\Tauri\App::current()?->http();
        if ($token === null || $token === '' || $http === null) {
            return Deferred::rejected(new \RuntimeException('No GitHub access: sign in with jumphp.net again.'));
        }
        $repoName = $try === 1 ? $name : "$name-$try";
        $d = new Deferred();
        $http->request('POST', 'https://api.github.com/user/repos', ['name' => $repoName, 'description' => $description, 'private' => false, 'auto_init' => false], [
            'Authorization' => "Bearer $token", 'Accept' => 'application/vnd.github+json', 'User-Agent' => 'jumphp-ide',
        ], 30.0)->then(function ($r) use ($d, $git, $name, $description, $try) {
            $body = $r->json();
            if ($r->status === 201 && is_array($body) && !empty($body['clone_url'])) {
                $d->resolve($body);
            } elseif ($r->status === 422 && $try < 9) {
                $this->createRepository($git, $name, $description, $try + 1)->then(fn ($v) => $d->resolve($v), fn (\Throwable $e) => $d->reject($e));
            } else {
                $d->reject(new \RuntimeException('GitHub did not create the repository (HTTP ' . $r->status . '): ' . (is_array($body) ? ($body['message'] ?? '') : '')));
            }
        }, fn (\Throwable $e) => $d->reject($e));
        return $d;
    }

    /** git for a project, with the user's GitHub token (through jumphp.net) to push. */
    public function git(string $root): Deferred
    {
        $git = new Git($this->processes, $root);
        $login = (string)($this->main->user()['login'] ?? 'jumphp');
        $git->setFallbackAuthor(['name' => $login, 'email' => $login . '@users.noreply.github.com']);
        return self::deferred($this->playground->githubToken())->then(function (array $r) use ($git) {
            $git->setToken($r['token'] ?? null);
            return $git;
        });
    }

    /** The remote "jumphp" (if any) fetches JumPHP's updates and can never be pushed to. */
    public function noPushToJumphp(string $root): Deferred
    {
        return $this->run(['git', 'remote'], $root)->then(function ($r) use ($root) {
            $remotes = preg_split('/\s+/', trim((string)(is_array($r) ? ($r['output'] ?? $r['stdout'] ?? '') : $r))) ?: [];
            return in_array('jumphp', $remotes, true) ? $this->run(['git', 'remote', 'set-url', '--push', 'jumphp', self::NO_PUSH], $root) : null;
        });
    }

    /** ui: hybrid in a game's game.yaml; false when it has it already or there is no game.yaml. */
    private function makeHybrid(string $yamlFile): bool
    {
        $yaml = is_file($yamlFile) ? (string)file_get_contents($yamlFile) : '';
        if ($yaml === '' || preg_match('/^\s*ui:/m', $yaml)) {
            return false;
        }
        file_put_contents($yamlFile, preg_replace('/^game:\n/', "game:\n  ui: hybrid                  # an SDL game with HTML windows (docs/tauri.md)\n", $yaml, 1));
        return true;
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
