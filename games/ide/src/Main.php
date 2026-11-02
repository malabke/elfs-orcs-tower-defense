<?php

namespace App;

use JumPHP\Ide\Async\Deferred;
use JumPHP\Ide\Async\Environment;
use JumPHP\Ide\Async\Processes;
use JumPHP\Ide\Ide;
use JumPHP\Ide\Workspace\LocalWorkspace;
use JumPHP\Async\Task;
use JumPHP\Native\FilePicker;
use JumPHP\Native\Native;
use JumPHP\Native\Shell;
use JumPHP\Tauri\App;
use JumPHP\Tauri\AppMain;

/**
 * The desktop IDE's PHP side. The pages call one command, `ide` {name, payload}: the session and project commands
 * are answered here (sign-in with jumphp.net, project list, clone, create, open), the others by the opened
 * project's JumPHP\Ide\Ide (files, PHP intelligence, search, Git, runs). Events (run output, index progress)
 * go to the pages with $app->send().
 */
final class Main implements AppMain
{
    private App $app;
    private Playground $playground;
    private Processes $processes;
    private ?Ide $ide = null;
    private ?Loopback $loopback = null;

    /** @var array{token?: string, user?: array<string, mixed>, server?: string} */
    private array $session = [];

    /** @var array<string, callable(array): mixed> */
    private array $commands = [];

    private float $nextScan = 0;

    private ClaudeBridge $bridge;
    private SelfUpdate $updates;
    private string $filesSignature = '';

    public function boot(App $app): void
    {
        $this->app = $app;
        $this->processes = new Processes();
        // started from the desktop's menu, the IDE has a minimal PATH: get the user's (Docker, git, composer...)
        Environment::loadShellPath($this->processes);
        $this->session = (array)$app->storage->readJson('session.json', []);
        $server = (string)(getenv('JUMPHP_IDE_SERVER') ?: ($this->session['server'] ?? Playground::DEFAULT_SERVER));
        $this->playground = new Playground($app->http(), $server, $this->session['token'] ?? null);
        $this->bridge = new ClaudeBridge($this, $this->playground, $this->processes);
        // a crash is saved for JumPHP's developers and sent at the next start (CrashReports)
        $crashes = new CrashReports($app->storage, (string)$app->config->get('version'));
        $app->on('crash', fn (\Throwable $e) => $crashes->save($e));
        if (getenv('JUMPHP_IDE_CRASH_REPORTS') !== '0') {
            $crashes->send($this->playground);
        }
        // the IDE keeps itself up to date (SelfUpdate: game.yaml app.update.url, games/ide/tools/release.sh)
        $this->updates = new SelfUpdate($app);
        $this->registerCommands();
        $this->updates->start();
        // bin/jumphp claude:connect, or the option kept from last time: this computer's Claude answers the web IDE
        if (getenv('JUMPHP_IDE_BRIDGE') === '1' || $app->options->get('claudeBridge', false)) {
            if (!empty($this->session['token'])) {
                $this->bridge->enable(true);
            } elseif (getenv('JUMPHP_IDE_BRIDGE') === '1') {
                $this->login()->then(fn () => $this->bridge->enable(true), fn () => null);
            }
        }

        $app->command('ide', function (mixed $payload) {
            $payload = is_array($payload) ? $payload : [];
            return $this->toTask($this->handle((string)($payload['name'] ?? ''), $payload['payload'] ?? []));
        });
        $app->on('frame', fn () => $this->frame());
        // headless checks and screenshots drive the interface: JUMPHP_IDE_EVAL is JavaScript run once the page loaded
        $script = getenv('JUMPHP_IDE_EVAL');
        if (is_string($script) && $script !== '') {
            $app->window()->on('loaded', fn () => $app->window()->eval($script));
            $app->on('page:log', fn (mixed $p) => fwrite(STDERR, '[page] ' . json_encode($p, JSON_UNESCAPED_SLASHES) . "\n"));
        }

        // the last project opens again (JUMPHP_IDE_PROJECT: a given folder, for tests and screenshots)
        $start = getenv('JUMPHP_IDE_PROJECT') ?: null;
        $recent = $this->recent();
        if ($start === null && $recent && $app->options->get('reopen', true)) {
            $start = $recent[0]['path'];
        }
        if ($start !== null && is_dir($start)) {
            try {
                $this->openProject($start);
            } catch (\Throwable) {
                // a folder gone since: the welcome screen
            }
        }
    }

    /** Runs a command: a host one, else the project's. */
    public function handle(string $name, mixed $payload): mixed
    {
        $payload = is_array($payload) ? $payload : [];
        if (isset($this->commands[$name])) {
            return ($this->commands[$name])($payload);
        }
        if ($this->ide === null) {
            throw new \RuntimeException('No project is open.');
        }
        return $this->ide->handle($name, $payload);
    }

    private function frame(): void
    {
        $this->processes->poll();
        $this->loopback?->poll();
        $this->bridge->tick();
        if ($this->ide === null) {
            return;
        }
        $this->ide->tick(0.006);
        // files changed by something else (a terminal, Claude Code, git): refresh what shows them
        if (microtime(true) >= $this->nextScan) {
            $this->nextScan = microtime(true) + 3.0;
            $signature = md5(json_encode($this->ide->workspace->files()) ?: '');
            if ($this->filesSignature !== '' && $signature !== $this->filesSignature) {
                $this->ide->filesChanged();
                $this->app->send('files.changed', []);
            }
            $this->filesSignature = $signature;
        }
    }

    /** A Deferred of the core becomes a Task the page waits for. */
    private function toTask(mixed $result): mixed
    {
        if ($result instanceof Deferred) {
            $task = new Task();
            $result->then(fn ($value) => $task->settle($value), fn (\Throwable $e) => $task->settle(null, $e));
            return $task;
        }
        return $result;
    }

    /** A Task of the engine (HTTP, dialogs) becomes a Deferred, which chains. */
    private static function deferred(Task $task): Deferred
    {
        $d = new Deferred();
        $task->then(fn ($v) => $d->resolve($v), fn (\Throwable $e) => $d->reject($e));
        return $d;
    }

    private function registerCommands(): void
    {
        $c = &$this->commands;
        $c['session.state'] = fn () => [
            'user' => $this->session['user'] ?? null,
            'server' => parse_url($this->playground->server(), PHP_URL_HOST),
            'recent' => $this->recent(),
            'projectsDir' => $this->projectsDir(),
            'project' => $this->ide !== null ? $this->info() : null,
            'version' => $this->app->config->get('version'),
        ];
        $c['update.state'] = fn () => $this->updates->state();
        $c['update.check'] = fn () => $this->updates->check(true);
        $c['update.install'] = fn () => $this->updates->install();
        $c['update.restart'] = fn () => $this->updates->restart();
        $c['update.auto'] = fn (array $p) => $this->updates->setAuto(!empty($p['on']));
        $c['session.login'] = fn () => $this->login();
        $c['session.cancelLogin'] = function () {
            $this->loopback?->fail('sign-in cancelled');
            return true;
        };
        $c['session.logout'] = function () {
            $this->bridge->enable(false);
            $this->session = ['server' => $this->playground->server()];
            $this->app->storage->writeJson('session.json', $this->session);
            $this->playground->setToken(null);
            $this->ide?->git?->setToken(null);
            return true;
        };
        $c['session.user'] = fn () => $this->session['user'] ?? null;
        // Claude for jumphp.net (ClaudeBridge): the web IDE uses this computer's Claude
        $c['bridge.status'] = fn () => $this->bridge->status() + ['signedIn' => !empty($this->session['token'])];
        $c['bridge.enable'] = function (array $p) {
            $on = !empty($p['on']);
            $this->app->options->set('claudeBridge', $on);
            if ($on && empty($this->session['token'])) {
                return $this->login()->then(fn () => $this->bridge->enable(true));
            }
            return $this->bridge->enable($on);
        };

        $c['projects.remote'] = fn () => self::deferred($this->playground->projects())->then(fn (array $list) => array_map(function (array $p) {
            $p['localPath'] = $this->localPathOf($p);
            return $p;
        }, $list));
        $c['projects.categories'] = fn () => self::deferred($this->playground->categories());
        $c['projects.clone'] = fn (array $p) => $this->cloneProject((string)($p['cloneUrl'] ?? ''), isset($p['slug']) ? (string)$p['slug'] : null)
            ->then(fn (string $path) => $this->openProject($path));
        $c['projects.open'] = fn (array $p) => $this->openProject((string)($p['path'] ?? ''));
        $c['projects.close'] = function () {
            $this->ide?->agent?->close();
            $this->ide = null;
            $this->app->options->set('reopen', false);
            return true;
        };
        $c['projects.forget'] = function (array $p) {
            $this->app->storage->writeJson('recent.json', array_values(array_filter($this->recent(), fn ($r) => $r['path'] !== ($p['path'] ?? null))));
            return true;
        };
        $c['projects.create'] = fn (array $p) => (new Creator($this, $this->playground, $this->processes))->create($p);
        $c['playground.publish'] = fn () => $this->publish();
        // the open project's page on jumphp.net (the jumphp.net tool window): settings, numbers, messages, downloads
        $api = fn (string $method, string $path = '', ?array $body = null) => $this->withSlug(fn (string $slug) =>
            self::deferred($this->playground->request($method, '/api/ide/projects/' . rawurlencode($slug) . $path, $body)));
        $c['playground.project'] = fn () => $api('GET');
        $c['playground.update'] = fn (array $p) => $api('PATCH', '', (array)($p['settings'] ?? []));
        $c['playground.stats'] = fn () => $api('GET', '/stats');
        $c['playground.feedback'] = fn () => $api('GET', '/feedback');
        $c['playground.downloads'] = fn () => $api('GET', '/downloads');
        $c['playground.removeDownload'] = fn (array $p) => $api('DELETE', '/downloads/' . rawurlencode((string)($p['platform'] ?? '')));
        $c['playground.installer'] = fn () => $api('POST', '/installer', []);
        $c['playground.upload'] = fn (array $p) => $this->withSlug(fn (string $slug) => $this->upload($slug, (string)($p['platform'] ?? ''),
            (string)($p['path'] ?? ''), (string)($p['version'] ?? '')));
        // what the game's builds left in its dist/: the newest file per system
        $c['playground.localFiles'] = function (array $p) {
            $root = (string)$this->ide?->workspace->localPath();
            $dir = trim(str_replace('..', '', (string)($p['dir'] ?? '')), '/');
            $base = $root . ($dir !== '' ? "/$dir" : '') . '/dist';
            $out = [];
            foreach (['windows' => ['standalone/*-windows-x86_64.exe'], 'linux' => ['standalone/*-linux-x86_64'],
                'macos' => ['apple/*-macos.zip', 'standalone/*-macos.zip'], 'android' => ['android-native/*.apk', 'android/*.apk']] as $platform => $patterns) {
                $files = array_merge(...array_map(fn (string $g) => glob("$base/$g") ?: [], $patterns));
                usort($files, fn (string $a, string $b) => filemtime($b) <=> filemtime($a));
                if ($files !== []) {
                    $out[$platform] = ['path' => substr($files[0], strlen($root) + 1), 'size' => filesize($files[0]), 'time' => filemtime($files[0])];
                }
            }
            return $out;
        };
        $c['playground.link'] = function (array $p) {
            $root = (string)$this->ide?->workspace->localPath();
            $this->saveProjectSettings($root, ['slug' => (string)($p['slug'] ?? '') ?: null]);
            return $this->info();
        };

        // the system's dialogs and browser, for the pages (the built-in native.* commands, through the same door)
        $c['native.open'] = fn (array $p) => Native::supports('open')
            ? self::deferred(!empty($p['folder']) ? FilePicker::folder($p) : FilePicker::open($p))
            : null;
        $c['native.openUrl'] = function (array $p) {
            $url = (string)($p['url'] ?? '');
            if (!preg_match('#^https?://#', $url)) {
                throw new \InvalidArgumentException('not a web address');
            }
            Shell::openUrl($url);
            return true;
        };
    }

    // session ----------------------------------------------------------------------------------------------

    private function login(): Deferred
    {
        $this->loopback?->fail('sign-in started again');
        $loopback = $this->loopback = new Loopback();
        Shell::openUrl($this->playground->connectUrl($loopback->redirectUri(), $loopback->state));
        return self::deferred($loopback->done)->then(function (string $token) {
            $this->playground->setToken($token);
            return self::deferred($this->playground->me())->then(function (array $user) use ($token) {
                $this->session = ['token' => $token, 'user' => $user, 'server' => $this->playground->server()];
                $this->app->storage->writeJson('session.json', $this->session);
                $this->loopback = null;
                $this->authorizeGit();
                return $user;
            });
        });
    }

    public function user(): ?array
    {
        return $this->session['user'] ?? null;
    }

    /** The user's GitHub token (through jumphp.net) for git push / pull of their repositories. */
    private function authorizeGit(): void
    {
        $git = $this->ide?->git;
        if ($git === null || empty($this->session['token'])) {
            return;
        }
        $login = (string)($this->session['user']['login'] ?? 'jumphp');
        // only used when git has no author configured on this computer
        $git->setFallbackAuthor(['name' => $login, 'email' => $login . '@users.noreply.github.com']);
        self::deferred($this->playground->githubToken())->then(fn (array $r) => $git->setToken($r['token'] ?? null), fn () => null);
    }

    // projects ---------------------------------------------------------------------------------------------

    public function projectsDir(): string
    {
        $dir = getenv('JUMPHP_IDE_PROJECTS') ?: ((getenv('HOME') ?: sys_get_temp_dir()) . '/JumPHP-Projects');
        return rtrim((string)$this->app->options->get('projectsDir', $dir), '/');
    }

    public function processes(): Processes
    {
        return $this->processes;
    }

    /** @return array<string, mixed> project.info, with what the desktop knows more */
    public function openProject(string $path): array
    {
        $workspace = new LocalWorkspace($path);
        $root = (string)$workspace->localPath();
        $this->ide?->agent?->close();
        $this->ide = new Ide($workspace, $this->app->storage->path('cache'), function (string $event, array $payload): void {
            $this->app->send($event, $payload);
        }, $this->processes);
        // games kept in their own repository (a jumphp.net project made of the game alone) run through this copy
        $this->ide->setToolkit($this->app->storage->path('jumphp'), Creator::TEMPLATE);
        $this->filesSignature = '';
        $this->authorizeGit();
        $this->app->options->set('reopen', true);
        $settings = $this->projectSettings($root);
        $recent = array_values(array_filter($this->recent(), fn ($r) => $r['path'] !== $root));
        array_unshift($recent, ['path' => $root, 'name' => basename($root), 'opened' => time(), 'slug' => $settings['slug'] ?? null]);
        $this->app->storage->writeJson('recent.json', array_slice($recent, 0, 30));
        $this->app->window()->setTitle(basename($root) . ' – JumPHP IDE');
        return $this->info();
    }

    /** @return array<string, mixed> */
    private function info(): array
    {
        $info = $this->ide->handle('project.info', []);
        $settings = $this->projectSettings((string)$info['root']);
        $info['playground'] = $settings['slug'] ?? null;
        return $info;
    }

    /** @return list<array{path: string, name: string, opened: int, slug: ?string}> */
    private function recent(): array
    {
        return array_values(array_filter((array)$this->app->storage->readJson('recent.json', []), fn ($r) => is_array($r) && isset($r['path'])));
    }

    /** The IDE's own notes about a project (.jumphp/ide.json: its jumphp.net slug, a CPC disc...); never committed. */
    public function projectSettings(string $root): array
    {
        $file = "$root/.jumphp/ide.json";
        return is_file($file) ? (array)json_decode((string)file_get_contents($file), true) : [];
    }

    public function saveProjectSettings(string $root, array $settings): void
    {
        @mkdir("$root/.jumphp", 0777, true);
        file_put_contents("$root/.jumphp/ide.json", json_encode($settings + $this->projectSettings($root), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n");
        // keep it out of git without touching the project's .gitignore
        $exclude = "$root/.git/info/exclude";
        if (is_dir("$root/.git/info") && !str_contains((string)@file_get_contents($exclude), '/.jumphp/')) {
            file_put_contents($exclude, "\n/.jumphp/\n", FILE_APPEND);
        }
    }

    /** @param array<string, mixed> $project */
    private function localPathOf(array $project): ?string
    {
        foreach ($this->recent() as $r) {
            if (($r['slug'] ?? null) === ($project['slug'] ?? '') && is_dir($r['path'])) {
                return $r['path'];
            }
        }
        $guess = $this->projectsDir() . '/' . self::repoName((string)($project['cloneUrl'] ?? $project['slug'] ?? ''));
        return is_dir("$guess/.git") ? $guess : null;
    }

    public static function repoName(string $url): string
    {
        $name = basename(rtrim($url, '/'));
        return preg_replace('/\.git$/', '', $name) ?: 'project';
    }

    /** Clones into the projects folder (or finds the clone already there); resolves with its path. */
    public function cloneProject(string $url, ?string $slug, ?string $folder = null): Deferred
    {
        if (!preg_match('#^(https://|git@)#', $url)) {
            return Deferred::rejected(new \InvalidArgumentException('not a git URL'));
        }
        $parent = $folder ?: $this->projectsDir();
        @mkdir($parent, 0777, true);
        $target = $parent . '/' . self::repoName($url);
        $done = function () use ($target, $slug) {
            if ($slug !== null) {
                $this->saveProjectSettings($target, ['slug' => $slug]);
            }
            return $target;
        };
        if (is_dir("$target/.git")) {
            return Deferred::resolved($done());
        }
        $git = new \JumPHP\Ide\Git\Git($this->processes, $parent);
        $token = $this->session['token'] ?? null;
        $start = $token !== null
            ? self::deferred($this->playground->githubToken())->then(fn (array $r) => $git->setToken($r['token'] ?? null), fn () => null)
            : Deferred::resolved(null);
        return $start->then(fn () => $git->cloneInto($url, $target, fn (string $chunk) => $this->app->send('clone.output', ['chunk' => $chunk])))->then($done);
    }

    /** The open project's jumphp.net slug, or a rejection. */
    private function withSlug(callable $work): Deferred
    {
        $slug = $this->ide !== null ? ($this->projectSettings((string)$this->ide->workspace->localPath())['slug'] ?? null) : null;
        return $slug === null ? Deferred::rejected(new \RuntimeException('This project is not one of your jumphp.net projects.')) : $work((string)$slug);
    }

    /**
     * Sends a file of the project (an executable, the Android app) to the game's downloads on jumphp.net: curl, the token
     * in a header file (not in the command line), progress as playground.upload events.
     */
    private function upload(string $slug, string $platform, string $path, string $version): Deferred
    {
        $root = (string)$this->ide?->workspace->localPath();
        $file = str_starts_with($path, '/') ? $path : "$root/$path";
        $token = $this->session['token'] ?? null;
        if (!is_file($file)) {
            return Deferred::rejected(new \RuntimeException("No file $path: build it first."));
        }
        if ($token === null) {
            return Deferred::rejected(new \RuntimeException('Sign in with jumphp.net first.'));
        }
        $headers = (string)tempnam(sys_get_temp_dir(), 'jph');
        chmod($headers, 0600);
        file_put_contents($headers, "Authorization: Bearer $token\nAccept: application/json\nContent-Type: application/octet-stream\nUser-Agent: jumphp-ide\n");
        $url = $this->playground->server() . '/api/ide/projects/' . rawurlencode($slug) . '/downloads/' . rawurlencode($platform)
            . '?' . http_build_query(['name' => basename($file), 'version' => $version]);
        $this->app->send('playground.upload', ['platform' => $platform, 'state' => 'start', 'size' => filesize($file)]);
        $started = $this->processes->start(['curl', '-sS', '-X', 'PUT', '-H', "@$headers", '--data-binary', "@$file", '-w', '\n%{http_code}', $url], $root);
        return $started['done']->then(function (array $r) use ($headers, $platform) {
            @unlink($headers);
            $lines = explode("\n", rtrim((string)$r['stdout']));
            $status = (int)array_pop($lines);
            $data = json_decode(implode("\n", $lines), true);
            $this->app->send('playground.upload', ['platform' => $platform, 'state' => 'end']);
            if ($r['code'] !== 0 || $status < 200 || $status >= 300) {
                throw new \RuntimeException(is_array($data) && isset($data['error']) ? (string)$data['error']
                    : 'The upload failed (' . ($status ?: 'curl: ' . trim((string)$r['stderr'])) . ')');
            }
            return $data;
        }, function (\Throwable $e) use ($headers) {
            @unlink($headers);
            throw $e;
        });
    }

    private function publish(): Deferred
    {
        $slug = $this->ide !== null ? ($this->projectSettings((string)$this->ide->workspace->localPath())['slug'] ?? null) : null;
        if ($slug === null) {
            return Deferred::rejected(new \RuntimeException('This project is not one of your jumphp.net projects.'));
        }
        return self::deferred($this->playground->sync((string)$slug))->then(fn (array $r) => [
            'ok' => !in_array($r['status'] ?? '', ['error', 'failed'], true),
            'message' => (string)($r['message'] ?? $r['statusMessage'] ?? ('Status: ' . ($r['status'] ?? 'published'))),
            'url' => $r['url'] ?? null,
        ]);
    }

    public function send(string $event, array $payload): void
    {
        $this->app->send($event, $payload);
    }
}
