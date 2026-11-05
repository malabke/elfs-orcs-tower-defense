<?php

namespace App;

use JumPHP\Ide\Async\Deferred;
use JumPHP\Ide\Async\Environment;
use JumPHP\Ide\Async\Processes;
use JumPHP\Ide\Log\LogBuffer;
use JumPHP\Ide\Ide;
use JumPHP\Ide\Workspace\LocalWorkspace;
use JumPHP\Async\Task;
use JumPHP\Ide\Plugin\PluginPackage;
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
    /** jumphp.net's "Install in my IDE" buttons come in here (LocalLink) */
    private ?LocalLink $link = null;
    /** the logs of the IDE, across projects (Logs window) */
    private LogBuffer $log;

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
        // the detailed logs (Logs window, <storage>/logs/ide.log for bug reports): what the IDE does, the programs it runs, what fails
        $this->log = new LogBuffer($app->storage->path('logs') . '/ide.log');
        $this->log->onEntry(function (array $entry) use ($app): void {
            $app->send('log.entry', $entry);
        });
        $this->processes->logger = function (string $level, string $source, string $message): void {
            $this->log->add($level, $source, $message);
        };
        $this->log->add('info', 'ide', 'JumPHP IDE ' . $app->config->get('version') . ' started (PHP ' . PHP_VERSION . ', ' . PHP_OS_FAMILY . ')');
        // a crash is saved for JumPHP's developers and sent at the next start (CrashReports)
        $crashes = new CrashReports($app->storage, (string)$app->config->get('version'));
        $app->on('crash', function (\Throwable $e) use ($crashes): void {
            $this->log->add('error', 'ide', 'crash: ' . $e->getMessage() . ' (' . basename($e->getFile()) . ':' . $e->getLine() . ')');
            $crashes->save($e);
        });
        if (getenv('JUMPHP_IDE_CRASH_REPORTS') !== '0') {
            $crashes->send($this->playground);
        }
        // the IDE keeps itself up to date (SelfUpdate: game.yaml app.update.url, games/ide/tools/release.sh)
        $this->updates = new SelfUpdate($app);
        $this->registerCommands();
        $this->updates->start();
        // jumphp.net's "Install in my IDE" (plugins, engine extensions): a listener on 127.0.0.1, the interface asks before it installs
        if (getenv('JUMPHP_IDE_LINK') !== '0') {
            $origins = array_values(array_unique(['https://jumphp.net', rtrim($this->playground->server(), '/')]));
            $this->link = new LocalLink($origins, (string)$app->config->get('version'), function (string $kind, string $id) use ($app): void {
                $app->send('link.install', ['kind' => $kind, 'id' => $id]);
            });
        }
        // the plugins installed from jumphp.net: a check a little after the start, then every 6 hours (event
        // plugins.updates; with "Update plugins automatically" they are installed at once, used at the next start)
        if (getenv('JUMPHP_IDE_UPDATES') !== '0') {
            $app->gameLoop->addTimer(30, fn () => $this->checkPluginUpdates());
            $app->gameLoop->addPeriodicTimer(6 * 3600, fn () => $this->checkPluginUpdates());
        }
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
        // the IDE closed: its other windows (conversations with Claude) with it
        $app->window()->on('closed', function () use ($app): void {
            foreach ($app->windows() as $label => $window) {
                if ($label !== 'main') {
                    $window->close();
                }
            }
        });
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
        $this->link?->poll();
        $this->bridge->tick();
        if ($this->ide === null) {
            return;
        }
        $this->ide->tick(0.006);
        // files changed by something else (a terminal, Claude Code, git): refresh what shows them
        if (microtime(true) >= $this->nextScan) {
            $this->nextScan = microtime(true) + 3.0;
            $signature = md5((json_encode($this->ide->workspace->files()) ?: '') . $this->ide->librariesSignature());
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
        // the plugins of jumphp.net (Tools > Plugins…): search, install / update, publish the project's own
        $c['market.list'] = fn (array $p) => $this->marketList((string)($p['q'] ?? ''));
        $c['market.install'] = fn (array $p) => $this->marketInstall((string)($p['id'] ?? ''), isset($p['version']) ? (string)$p['version'] : null,
            ($p['via'] ?? '') === 'update' ? 'update' : 'install');
        // the engine extensions of Packagist (Tools > Engine Extensions…)
        $c['extensions.list'] = fn (array $p) => $this->extensionsList((string)($p['q'] ?? ''));
        // what is new (jumphp.net/nouveautes), in the user's language: shown once after an update, then from Help > What's New
        $c['whatsnew.get'] = fn (array $p) => $this->whatsNew((string)($p['lang'] ?? ''));
        // a mark (1 to 5) and, for the user's own plugins, their statistics
        $c['market.rate'] = fn (array $p) => self::deferred($this->playground->request('POST', '/api/ide/plugins/' . rawurlencode((string)($p['id'] ?? '')) . '/rating',
            ['score' => (int)($p['score'] ?? 0)]));
        $c['market.stats'] = fn (array $p) => self::deferred($this->playground->request('GET', '/api/ide/plugins/' . rawurlencode((string)($p['id'] ?? '')) . '/stats'));
        $c['market.updates'] = fn (array $p) => $this->pluginUpdates(!empty($p['install']));
        $c['market.auto'] = function (array $p) {
            if (isset($p['on'])) {
                $this->app->options->set('pluginsAutoUpdate', (bool)$p['on']);
            }
            return ['auto' => (bool)$this->app->options->get('pluginsAutoUpdate', false)];
        };
        $c['market.publish'] = fn (array $p) => $this->marketPublish((string)($p['dir'] ?? ''));
        // a plugin updated or being developed: PHP cannot unload its classes, the IDE starts again with them
        $c['plugins.restart'] = function () {
            $this->app->updater()->relaunch();
            return true;
        };
        $c['update.auto'] = fn (array $p) => $this->updates->setAuto(!empty($p['on']));
        $c['session.login'] = fn (array $p = []) => $this->login(!empty($p['another']));
        // several GitHub accounts (each one a jumphp.net sign-in), one assigned to each project
        // the user and token of any https host (GitLab, a company's server, a second GitHub...): git here uses them for that host (the tokens stay in this computer's storage)
        $c['git.credentials'] = fn () => array_map(fn ($host, $c) => ['host' => $host, 'user' => $c[0]], array_keys($this->gitCredentials()), $this->gitCredentials());
        $c['git.credentialSet'] = function (array $p) {
            $host = strtolower(trim((string)($p['host'] ?? '')));
            if (!preg_match('/^[a-z0-9.-]+(:\d+)?$/', $host) || trim((string)($p['token'] ?? '')) === '') {
                throw new \InvalidArgumentException('A host (e.g. gitlab.com) and a token are needed.');
            }
            $all = $this->gitCredentials();
            $all[$host] = [trim((string)($p['user'] ?? '')) ?: 'oauth2', trim((string)$p['token'])];
            $this->app->storage->writeJson('git-credentials.json', $all);
            $this->applyGitCredentials();
            return true;
        };
        $c['git.credentialRemove'] = function (array $p) {
            $all = $this->gitCredentials();
            unset($all[strtolower((string)($p['host'] ?? ''))]);
            $this->app->storage->writeJson('git-credentials.json', $all);
            $this->applyGitCredentials();
            return true;
        };
        $c['session.accounts'] = fn () => $this->accountsState();
        $c['session.switch'] = function (array $p) {
            if (!$this->useAccount((string)($p['login'] ?? ''))) {
                throw new \RuntimeException('That account is not signed in on this computer.');
            }
            return $this->accountsState();
        };
        $c['extension.publish'] = fn (array $p) => $this->publishExtension((string)($p['version'] ?? ''));
        $c['project.setAccount'] = function (array $p) {
            $root = $this->ide !== null ? (string)$this->ide->workspace->localPath() : '';
            $login = (string)($p['login'] ?? '');
            if ($root === '' || !$this->useAccount($login)) {
                throw new \RuntimeException('That account is not signed in on this computer.');
            }
            $this->saveProjectSettings($root, ['account' => $login]);
            return $this->accountsState();
        };
        $c['session.cancelLogin'] = function () {
            $this->loopback?->fail('sign-in cancelled');
            return true;
        };
        $c['session.logout'] = function () {
            $this->bridge->enable(false);
            // this account leaves; another one signed in takes its place
            $login = $this->session['user']['login'] ?? null;
            $others = array_values(array_filter($this->accounts(), fn ($a) => ($a['user']['login'] ?? null) !== $login));
            $this->app->storage->writeJson('accounts.json', $others);
            $this->session = ['server' => $this->playground->server()];
            $this->app->storage->writeJson('session.json', $this->session);
            $this->playground->setToken(null);
            $this->ide?->git?->setToken(null);
            if ($others !== []) {
                $this->useAccount((string)$others[0]['user']['login']);
            }
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

        // jumphp.net's MCP server in this computer's Claude Code (the Claude panel offers it when it is missing)
        $mcp = fn () => new \JumPHP\Ide\Claude\McpSetup($this->processes, $this->ide?->workspace->localPath() ?? (getenv('HOME') ?: sys_get_temp_dir()));
        $c['claude.mcp.status'] = fn () => $mcp()->status($this->playground->server()) + [
            'signedIn' => !empty($this->session['token']), 'server' => parse_url($this->playground->server(), PHP_URL_HOST)];
        $c['claude.mcp.install'] = function () use ($mcp) {
            $install = fn () => $mcp()->install($this->playground->server(), (string)$this->session['token']);
            return empty($this->session['token']) ? $this->login()->then(fn () => $install()) : $install();
        };

        $c['projects.remote'] = fn () => self::deferred($this->playground->projects())->then(fn (array $list) => array_map(function (array $p) {
            $p['localPath'] = $this->localPathOf($p);
            return $p;
        }, $list));
        $c['projects.categories'] = fn () => self::deferred($this->playground->categories());
        $c['projects.clone'] = fn (array $p) => $this->cloneProject((string)($p['cloneUrl'] ?? ''), isset($p['slug']) ? (string)$p['slug'] : null, isset($p['folder']) && $p['folder'] !== '' ? (string)$p['folder'] : null)
            ->then(fn (string $path) => $this->openProject($path));
        $c['projects.open'] = fn (array $p) => $this->openProject((string)($p['path'] ?? ''));
        $c['projects.close'] = function () {
            $this->ide?->agent?->close();
            $this->ide = null;
            $this->app->options->set('reopen', false);
            return true;
        };
        $c['projects.delete'] = fn (array $p) => $this->deleteProject((string)($p['path'] ?? ''), !empty($p['repository']));
        $c['projects.forget'] = function (array $p) {
            $this->app->storage->writeJson('recent.json', array_values(array_filter($this->recent(), fn ($r) => $r['path'] !== ($p['path'] ?? null))));
            return true;
        };
        $c['projects.create'] = fn (array $p) => (new Creator($this, $this->playground, $this->processes))->create($p);
        $c['playground.publish'] = fn () => $this->publish();
        $c['project.createRepository'] = fn (array $p) => $this->createRepository((string)($p['name'] ?? ''));
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
                if ($platform === 'macos' && $files === []) {
                    $files = glob("$base/standalone/*-macos-arm64") ?: [];     // a CLI app's executables (no .app)
                }
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

        // the IDE's windows talk to each other through here (every window gets ui.broadcast)
        $c['ui.broadcast'] = function (array $p): bool {
            $this->app->send('ui.broadcast', $p);
            return true;
        };
        // a Claude conversation in a window of its own (its process goes on); closed, it goes back into the Claude window
        $c['claude.window'] = function (array $p): bool {
            $conv = (string)($p['conv'] ?? '');
            if (!preg_match('/^[A-Za-z0-9_-]{4,64}$/', $conv)) {
                throw new \InvalidArgumentException('not a conversation');
            }
            $label = 'claude-' . $conv;
            if (isset($this->app->windows()[$label])) {
                $this->app->windows()[$label]->focus();
                return true;
            }
            $query = ['claude' => $conv] + (!empty($p['live']) ? ['live' => (int)$p['live']] : []) + (!empty($p['running']) ? ['running' => 1] : []);
            $title = mb_strimwidth(trim((string)($p['title'] ?? '')), 0, 60, '…');
            $window = $this->app->createWindow($label, ['title' => ($title !== '' ? "$title – " : '') . 'Claude', 'url' => 'index.html?' . http_build_query($query),
                'width' => 560, 'height' => 780]);
            $window->on('closed', function () use ($conv): void {
                $state = $this->ide?->claude?->state($conv);
                $this->app->send('ui.broadcast', ['type' => 'claude.dock', 'conv' => $conv, 'live' => $state['id'] ?? null, 'running' => $state['busy'] ?? false]);
            });
            return true;
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

    /** @return list<array{token: string, user: array<string, mixed>, server: string}> every account signed in on this computer */
    private function accounts(): array
    {
        $list = array_values(array_filter((array)$this->app->storage->readJson('accounts.json', []), fn ($a) => is_array($a) && !empty($a['token']) && isset($a['user']['login'])));
        // the account of an older IDE (session.json only) is the first one
        if (!empty($this->session['token']) && isset($this->session['user']['login'])
            && !array_filter($list, fn ($a) => $a['user']['login'] === $this->session['user']['login'])) {
            array_unshift($list, $this->session);
        }
        return $list;
    }

    private function rememberAccount(): void
    {
        $list = array_values(array_filter($this->accounts(), fn ($a) => $a['user']['login'] !== ($this->session['user']['login'] ?? null)));
        $list[] = $this->session;
        $this->app->storage->writeJson('accounts.json', $list);
    }

    /** Makes an account signed in on this computer the one that pushes / publishes now (the token itself never leaves this computer's storage). */
    private function useAccount(string $login): bool
    {
        foreach ($this->accounts() as $account) {
            if (strcasecmp((string)$account['user']['login'], $login) === 0) {
                if (($this->session['user']['login'] ?? null) !== $account['user']['login']) {
                    $this->session = $account;
                    $this->app->storage->writeJson('session.json', $this->session);
                    $this->playground->setToken((string)$account['token']);
                    $this->ide?->git?->setToken(null);
                    $this->authorizeGit();
                }
                return true;
            }
        }
        return false;
    }

    /** @return array<string, mixed> */
    private function accountsState(): array
    {
        $root = $this->ide !== null ? (string)$this->ide->workspace->localPath() : '';
        return [
            'accounts' => array_map(fn ($a) => ['login' => $a['user']['login'], 'name' => $a['user']['name'] ?? null, 'avatar' => $a['user']['avatar'] ?? null], $this->accounts()),
            'active' => $this->session['user']['login'] ?? null,
            'projectAccount' => $root !== '' ? ($this->projectSettings($root)['account'] ?? null) : null,
        ];
    }

    private function login(bool $another = false): Deferred
    {
        $this->loopback?->fail('sign-in started again');
        $loopback = $this->loopback = new Loopback();
        Shell::openUrl($this->playground->connectUrl($loopback->redirectUri(), $loopback->state, $another));
        return self::deferred($loopback->done)->then(function (string $token) {
            $this->playground->setToken($token);
            return self::deferred($this->playground->me())->then(function (array $user) use ($token) {
                $this->session = ['token' => $token, 'user' => $user, 'server' => $this->playground->server()];
                $this->app->storage->writeJson('session.json', $this->session);
                $this->rememberAccount();
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

    /** @return array<string, array{0: string, 1: string}> host => [user, token] */
    private function gitCredentials(): array
    {
        return array_filter((array)$this->app->storage->readJson('git-credentials.json', []), fn ($c) => is_array($c) && count($c) === 2);
    }

    private function applyGitCredentials(): void
    {
        $this->ide?->git?->setCredentials($this->gitCredentials());
    }

    /** The user's GitHub token (through jumphp.net) for git push / pull of their repositories. */
    private function authorizeGit(): void
    {
        $this->applyGitCredentials();
        $git = $this->ide?->git;
        if ($git === null || empty($this->session['token'])) {
            return;
        }
        $login = (string)($this->session['user']['login'] ?? 'jumphp');
        // only used when git has no author configured on this computer
        $git->setFallbackAuthor(['name' => $login, 'email' => $login . '@users.noreply.github.com']);
        self::deferred($this->playground->githubToken())->then(fn (array $r) => $git->setToken($r['token'] ?? null), fn () => null);
        $git->pinAccount($login)->then(null, fn () => null);
    }

    // projects ---------------------------------------------------------------------------------------------

    public function projectsDir(): string
    {
        // the home folder (Windows: USERPROFILE, HOME is not set there: the temporary folder, which Windows empties, must not be the default)
        $dir = getenv('JUMPHP_IDE_PROJECTS') ?: ((getenv('HOME') ?: getenv('USERPROFILE') ?: sys_get_temp_dir()) . '/JumPHP-Projects');
        return rtrim((string)$this->app->options->get('projectsDir', $dir), '/\\');
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
        $this->ide?->terminals?->closeAll();
        $this->ide = new Ide($workspace, $this->app->storage->path('cache'), function (string $event, array $payload): void {
            $this->app->send($event, $payload);
        }, $this->processes);
        // games kept in their own repository (a jumphp.net project made of the game alone) run through this copy
        $this->ide->log = $this->log;
        $this->ide->setToolkit($this->app->storage->path('jumphp'), Creator::TEMPLATE);
        // the plugins (PHP): the user's (Settings > Plugins installs them there) and the project's .jumphp/plugins/
        $this->ide->enablePlugins($this->app->storage->path('plugins'));
        $this->ide->enableTerminal();
        $this->filesSignature = '';
        // the project's own GitHub account, when it has one and it is signed in here (else the active account)
        $assigned = (string)($this->projectSettings($root)['account'] ?? '');
        $assigned !== '' && $this->useAccount($assigned);
        $this->authorizeGit();
        $this->app->options->set('reopen', true);
        $settings = $this->projectSettings($root);
        $recent = array_values(array_filter($this->recent(), fn ($r) => $r['path'] !== $root));
        array_unshift($recent, ['path' => $root, 'name' => basename($root), 'opened' => time(), 'slug' => $settings['slug'] ?? null,
            'kind' => $this->extensionOf($root) !== null ? 'extension' : null]);
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
        $info['extension'] = ($ext = $this->extensionOf((string)$info['root'])) === null ? null
            : $ext + ['version' => (string)($settings['extensionVersion'] ?? ''), 'nextVersion' => self::nextVersion((string)($settings['extensionVersion'] ?? ''))];
        $info['repository'] = self::ownRepository((string)$info['root']);
        // a JumPHP project (not JumPHP's own checkout) with no repository of its own: the IDE offers to create it
        $info['needsRepository'] = $info['repository'] === null && is_file($info['root'] . '/bin/jumphp')
            && (is_file($info['root'] . '/' . \JumPHP\Ide\Project\JumphpUpdater::RECORD) || self::remote((string)$info['root'], 'jumphp') !== null
                || !is_dir($info['root'] . '/.git'));
        return $info;
    }

    /** owner/name of the project's GitHub repository (remote origin), never JumPHP's own. */
    public static function ownRepository(string $root): ?string
    {
        $url = self::remote($root, 'origin');
        if ($url === null || !preg_match('#github\.com[/:]([A-Za-z0-9-]+/[A-Za-z0-9._-]+?)(?:\.git)?/?$#', $url, $m)) {
            return null;
        }
        return strtolower($m[1]) === 'davidnurdin/jumphp' && (self::remote($root, 'jumphp') !== null || is_file("$root/" . \JumPHP\Ide\Project\JumphpUpdater::RECORD)) ? null : $m[1];
    }

    private static function remote(string $root, string $name): ?string
    {
        if (!is_dir("$root/.git")) {
            return null;
        }
        $out = [];
        exec('git -C ' . escapeshellarg($root) . ' remote get-url ' . escapeshellarg($name) . ' 2>/dev/null', $out, $code);
        return $code === 0 && isset($out[0]) ? trim($out[0]) : null;
    }

    /**
     * A project made before New Project created repositories (no origin, or origin = JumPHP): its own public repository
     * on the user's account, everything committed and pushed there, then a jumphp.net project (Publish works).
     */
    private function createRepository(string $name): Deferred
    {
        if ($this->user() === null) {
            return Deferred::rejected(new \RuntimeException('Sign in with jumphp.net first: it gives the IDE access to your GitHub account.'));
        }
        $info = $this->info();
        $root = (string)$info['root'];
        if ($info['repository'] !== null) {
            return Deferred::rejected(new \RuntimeException('This project already has its repository: ' . $info['repository']));
        }
        $module = $info['modules'][0] ?? null;
        $name = trim((string)preg_replace('/[^a-z0-9._-]+/', '-', strtolower($name !== '' ? $name : (string)($module['id'] ?? basename($root)))), '-.');
        if ($name === '') {
            return Deferred::rejected(new \InvalidArgumentException('A name for the repository, please.'));
        }
        $creator = new Creator($this, $this->playground, $this->processes);
        $sh = fn (string $command) => exec('cd ' . escapeshellarg($root) . " && $command 2>&1");
        if (!is_dir("$root/.git")) {
            $sh('git init -q -b main');
        }
        // origin was JumPHP itself (a clone of the template): it becomes "jumphp", the updates' remote
        if (self::remote($root, 'origin') !== null) {
            $sh(self::remote($root, 'jumphp') === null ? 'git remote rename origin jumphp' : 'git remote remove origin');
        }
        return $creator->noPushToJumphp($root)
            ->then(fn () => $creator->git($root))
            ->then(fn (\JumPHP\Ide\Git\Git $git) => $creator->createRepository($git, $name, (string)($module['title'] ?? $name))
                ->then(fn (array $r) => $git->run(['remote', 'add', 'origin', (string)$r['clone_url']])
                    ->then(fn () => $git->status())
                    ->then(fn (array $st) => $st['files'] !== [] ? $git->commit(($module['title'] ?? basename($root)) . ': its own repository', ['.']) : null)
                    ->then(fn () => $git->push())
                    ->then(fn () => $r)))
            ->then(function (array $repo) use ($root, $module) {
                $kind = match ($module['kind'] ?? 'game') { 'app' => 'desktop', 'cli' => 'cli', 'gui' => 'gui', 'plugin' => null, default => 'original' };
                if ($this->extensionOf($root) !== null) {
                    $kind = null;   // an engine extension is not a jumphp.net game: Publish extension makes its own repository
                }
                if ($kind === null || $module === null) {
                    return ['repository' => $repo['full_name'], 'url' => $repo['html_url'], 'playground' => null, 'message' => null];
                }
                $login = preg_replace('/[^a-z0-9]+/', '-', strtolower((string)($this->user()['login'] ?? 'me'))) ?: 'me';
                return $this->importAs((string)$repo['full_name'], [(string)$module['id'], $module['id'] . '-' . $login, $module['id'] . '-2'], $kind)
                    ->then(function (array $project) use ($root, $repo) {
                        $this->saveProjectSettings($root, ['slug' => (string)$project['slug'], 'account' => (string)($this->session['user']['login'] ?? '')]);
                        return ['repository' => $repo['full_name'], 'url' => $repo['html_url'], 'playground' => $project['slug'], 'message' => null];
                    }, fn (\Throwable $e) => ['repository' => $repo['full_name'], 'url' => $repo['html_url'], 'playground' => null,
                        'message' => 'The repository is made and pushed, but jumphp.net did not take the project: ' . $e->getMessage()]);
            });
    }

    /**
     * The trash of the welcome screen: the project's folder is deleted from this computer; with $repository also its jumphp.net project and its
     * GitHub repository (jumphp.net does it; GitHub may refuse: its settings page is then given). Only a project of the recent list.
     *
     * @return Deferred resolves with {deleted, repository, repositoryUrl, message}
     */
    private function deleteProject(string $path, bool $repository): Deferred
    {
        $real = realpath($path);
        $known = array_filter($this->recent(), fn ($r) => realpath((string)$r['path']) === $real);
        $home = realpath((string)(getenv('HOME') ?: getenv('USERPROFILE')));
        if ($real === false || !$known || !is_dir($real) || $real === $home || strlen($real) < 8 || dirname($real) === $real
            || (!is_dir("$real/.git") && !is_dir("$real/.jumphp") && !is_file("$real/bin/jumphp"))) {
            return Deferred::rejected(new \RuntimeException('Only a project of the recent list can be deleted, and never a folder that is not a project.'));
        }
        $slug = $this->projectSettings($real)['slug'] ?? null;
        if ($repository && $slug !== null) {
            // GitHub's delete right is asked only now, by the user, in the browser: nothing is deleted until jumphp.net has it
            return self::deferred($this->playground->me())->then(function (array $me) use ($real, $slug) {
                if (empty($me['canDeleteRepo'])) {
                    return ['needsAccess' => true, 'url' => (string)($me['deleteAccessUrl'] ?? ''), 'deleted' => false];
                }
                return $this->removeProject($real, (string)$slug, true);
            });
        }
        return $this->removeProject($real, $slug !== null ? (string)$slug : null, false);
    }

    /** @return Deferred the folder gone, and with $repository its jumphp.net project and GitHub repository */
    private function removeProject(string $real, ?string $slug, bool $repository): Deferred
    {
        $remote = $repository && $slug !== null
            ? self::deferred($this->playground->request('DELETE', '/api/ide/projects/' . rawurlencode((string)$slug), ['repository' => true], 60.0))
            : Deferred::resolved(['project' => false, 'repository' => false, 'repositoryUrl' => null, 'message' => null]);
        return $remote->then(function (array $r) use ($real) {
            $kept = array_values(array_filter($this->recent(), fn ($x) => realpath((string)$x['path']) !== $real));   // before the folder is gone
            Creator::removeTree($real);
            $this->app->storage->writeJson('recent.json', $kept);
            return ['deleted' => !file_exists($real), 'repository' => (bool)($r['repository'] ?? false), 'repositoryUrl' => $r['repositoryUrl'] ?? null, 'message' => $r['message'] ?? null]
                + ($r['project'] ?? false ? ['jumphp' => true] : []);
        });
    }

    /** The engine extension a project holds (extensions/<id>/composer.json of type jumphp-extension), or null. @return array{id: string, dir: string, name: string, description: string}|null */
    private function extensionOf(string $root): ?array
    {
        foreach (glob("$root/extensions/*/composer.json") ?: [] as $file) {
            $json = json_decode((string)file_get_contents($file), true);
            if (is_array($json) && ($json['type'] ?? '') === 'jumphp-extension') {
                $dir = dirname($file);
                return ['id' => basename($dir), 'dir' => 'extensions/' . basename($dir), 'name' => (string)($json['name'] ?? ''), 'description' => (string)($json['description'] ?? '')];
            }
        }
        return null;
    }

    private static function nextVersion(string $last): string
    {
        return preg_match('/^v?(\d+)\.(\d+)\.(\d+)$/', $last, $m) ? "$m[1].$m[2]." . ((int)$m[3] + 1) : '0.1.0';
    }

    /**
     * Publish extension: the package (extensions/<id>) goes, on its own, to the user's GitHub repository <login>/jumphp-<id> (made the first time),
     * its composer name becoming <login>/<id>, tagged v<version>: what Packagist reads (the submit page is given). The project's own repository
     * is not the package's: Composer wants composer.json at the root.
     *
     * @return Deferred resolves with {repository, url, package, tag, submitUrl}
     */
    private function publishExtension(string $version): Deferred
    {
        $root = $this->ide !== null ? (string)$this->ide->workspace->localPath() : '';
        $ext = $root !== '' ? $this->extensionOf($root) : null;
        $user = $this->user();
        $version = ltrim(trim($version), 'v');
        if ($ext === null || $user === null) {
            return Deferred::rejected(new \RuntimeException($ext === null ? 'This project holds no engine extension.' : 'Sign in with jumphp.net first: it gives the IDE access to your GitHub account.'));
        }
        if (!preg_match('/^\d+\.\d+\.\d+$/', $version)) {
            return Deferred::rejected(new \InvalidArgumentException('A version like 0.1.0, please.'));
        }
        $login = (string)$user['login'];
        $vendor = preg_replace('/[^a-z0-9_.-]+/', '-', strtolower($login)) ?: 'me';
        $package = "$vendor/{$ext['id']}";
        $name = 'jumphp-' . $ext['id'];
        $work = sys_get_temp_dir() . '/jumphp-extension-' . bin2hex(random_bytes(4));
        $creator = new Creator($this, $this->playground, $this->processes);
        $http = $this->app->http();
        $existing = new Deferred();
        $http->request('GET', "https://api.github.com/repos/$login/$name", null, ['Accept' => 'application/vnd.github+json', 'User-Agent' => 'jumphp-ide'], 20.0)
            ->then(fn ($r) => $existing->resolve($r->status === 200 ? $r->json() : null), fn (\Throwable $e) => $existing->reject($e));
        $git = new \JumPHP\Ide\Git\Git($this->processes, sys_get_temp_dir());
        $git->setFallbackAuthor(['name' => $login, 'email' => $login . '@users.noreply.github.com']);
        return $existing->then(function (?array $repo) use ($git, $creator, $name, $ext, $work) {
            return self::deferred($this->playground->githubToken())->then(function (array $r) use ($git, $repo, $creator, $name, $ext, $work) {
                $git->setToken($r['token'] ?? null);
                if ($repo !== null) {
                    return $git->cloneInto((string)$repo['clone_url'], $work)->then(fn () => $repo);
                }
                return $creator->createRepository($git, $name, $ext['description'])->then(function (array $created) use ($git, $work) {
                    @mkdir($work, 0777, true);
                    return (new \JumPHP\Ide\Git\Git($this->processes, $work, $git->token()))->run(['init', '-q', '-b', 'main'])
                        ->then(fn () => (new \JumPHP\Ide\Git\Git($this->processes, $work))->run(['remote', 'add', 'origin', (string)$created['clone_url']]))
                        ->then(fn () => $created);
                });
            });
        })->then(function (array $repo) use ($root, $ext, $work, $package, $version, $login) {
            // the package's files replace the repository's (its history stays), the composer name is the user's
            foreach (scandir($work) ?: [] as $f) {
                if ($f !== '.' && $f !== '..' && $f !== '.git') {
                    is_dir("$work/$f") ? Creator::removeTree("$work/$f") : @unlink("$work/$f");
                }
            }
            Creator::copyTree("$root/{$ext['dir']}", $work);
            Creator::removeTree("$work/vendor");
            $composer = json_decode((string)file_get_contents("$work/composer.json"), true) ?: [];
            $composer['name'] = $package;
            unset($composer['version']);
            file_put_contents("$work/composer.json", json_encode($composer, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) . "\n");
            $git = new \JumPHP\Ide\Git\Git($this->processes, $work);
            $git->setFallbackAuthor(['name' => $login, 'email' => $login . '@users.noreply.github.com']);
            return self::deferred($this->playground->githubToken())->then(function (array $r) use ($git, $repo, $version, $package) {
                $git->setToken($r['token'] ?? null);
                return $git->status()->then(fn (array $st) => $st['files'] !== [] || $st['branch'] === null ? $git->commit("Release v$version", ['.']) : null)
                    ->then(fn () => $git->createTag("v$version", null, "$package $version"))
                    ->then(fn () => $git->run(['push', '-u', 'origin', 'main'], true))
                    ->then(fn () => $git->pushTag("v$version"))
                    ->then(fn () => ['repository' => (string)$repo['full_name'], 'url' => (string)$repo['html_url'], 'package' => $package, 'tag' => "v$version",
                        'submitUrl' => 'https://packagist.org/packages/submit']);
            });
        })->then(function (array $result) use ($root, $version, $work) {
            Creator::removeTree($work);
            $this->saveProjectSettings($root, ['extensionVersion' => $version]);
            return $result;
        }, function (\Throwable $e) use ($work) {
            Creator::removeTree($work);
            throw $e;
        });
    }

    /** jumphp.net's slug is taken (names are shared by everyone): the next candidate, a few times. Resolves with the project. */
    private function importAs(string $repository, array $slugs, string $kind): Deferred
    {
        $slug = array_shift($slugs);
        return self::deferred($this->playground->import(['repository' => $repository, 'slug' => $slug, 'kind' => $kind]))
            ->then(null, fn (\Throwable $e) => $slugs === [] ? throw $e : $this->importAs($repository, $slugs, $kind));
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
                $this->saveProjectSettings($target, ['slug' => $slug, 'account' => (string)($this->session['user']['login'] ?? '')]);
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

    /** jumphp.net's IDE plugins (its public API: no sign-in needed). */
    /** @return Deferred resolved with the entries of jumphp.net's /api/whats-new in $lang (two letters; else the server's guess) */
    private function whatsNew(string $lang): Deferred
    {
        $lang = preg_match('/^[a-z]{2}$/', $lang) ? $lang : '';
        $url = $this->playground->server() . '/api/whats-new' . ($lang !== '' ? '?lang=' . $lang : '');
        return $this->processes->start(['curl', '-sS', '--max-time', '15', '-H', 'Accept: application/json', '-A', 'jumphp-ide', $url], sys_get_temp_dir())['done']
            ->then(function (array $r) {
                $data = json_decode((string)$r['stdout'], true);
                if ($r['code'] !== 0 || !is_array($data) || !isset($data['entries'])) {
                    throw new \RuntimeException('jumphp.net cannot be reached: ' . trim((string)($r['stderr'] ?: $r['stdout'])));
                }
                return $data['entries'];
            });
    }

    /** Packagist's packages of type jumphp-extension (https://packagist.org/search.json), what jumphp.net/extensions lists too. */
    private function extensionsList(string $query): Deferred
    {
        $url = 'https://packagist.org/search.json?' . http_build_query(['type' => 'jumphp-extension', 'q' => $query, 'per_page' => 30]);
        return $this->processes->start(['curl', '-sS', '--max-time', '20', '-H', 'Accept: application/json', '-A', 'jumphp-ide', $url], sys_get_temp_dir())['done']
            ->then(function (array $r) {
                $data = json_decode((string)$r['stdout'], true);
                if ($r['code'] !== 0 || !is_array($data) || !isset($data['results'])) {
                    throw new \RuntimeException('Packagist cannot be reached: ' . trim((string)($r['stderr'] ?: $r['stdout'])));
                }
                return array_map(fn (array $x) => ['name' => (string)$x['name'], 'description' => (string)($x['description'] ?? ''), 'url' => (string)($x['url'] ?? ''),
                    'downloads' => (int)($x['downloads'] ?? 0), 'favers' => (int)($x['favers'] ?? 0)], $data['results']);
            });
    }

    private function marketList(string $query): Deferred
    {
        $url = $this->playground->server() . '/api/plugins' . ($query !== '' ? '?' . http_build_query(['q' => $query]) : '');
        return $this->processes->start(['curl', '-sS', '--max-time', '20', '-H', 'Accept: application/json', '-A', 'jumphp-ide', $url], sys_get_temp_dir())['done']
            ->then(function (array $r) {
                $data = json_decode((string)$r['stdout'], true);
                if ($r['code'] !== 0 || !is_array($data) || !isset($data['plugins'])) {
                    throw new \RuntimeException('jumphp.net cannot be reached: ' . trim((string)($r['stderr'] ?: $r['stdout'])));
                }
                return $data['plugins'];
            });
    }

    /**
     * The installed plugins jumphp.net has a newer version of; $install: installs them too (each takes effect at the
     * next start: PHP keeps the classes it loaded).
     *
     * @return Deferred resolved with {updates: [{id, name, installed, version}], installed: [ids], failed: {id: message}}
     */
    private function pluginUpdates(bool $install): Deferred
    {
        if ($this->ide?->plugins === null) {
            return Deferred::resolved(['updates' => [], 'installed' => [], 'failed' => []]);
        }
        $plugins = $this->ide->plugins;
        return $this->marketList('')->then(function (array $market) use ($plugins, $install) {
            $byId = array_column($market, null, 'id');
            $updates = [];
            foreach ($plugins->listing() as $p) {
                $m = $byId[$p['id']] ?? null;
                // the user's plugins only (the project's own come with the project), a newer version than the one on disk
                if ($m !== null && $p['source'] === 'user' && version_compare((string)$m['version'], (string)$p['version'], '>')
                    && version_compare((string)$m['version'], (string)($p['installedVersion'] ?? $p['version']), '>')) {
                    $updates[] = ['id' => $p['id'], 'name' => $p['name'], 'installed' => $p['version'], 'version' => $m['version']];
                }
            }
            $result = ['updates' => $updates, 'installed' => [], 'failed' => []];
            if (!$install || !$updates) {
                return $result;
            }
            // one after the other
            $chain = Deferred::resolved(null);
            foreach ($updates as $u) {
                // a closure (not fn): $result shared by reference, not copied
                $chain = $chain->then(function () use (&$result, $u) {
                    return $this->marketInstall($u['id'], $u['version'], 'update')->then(
                        function () use (&$result, $u) {
                            $result['installed'][] = $u['id'];
                        },
                        function (\Throwable $e) use (&$result, $u) {
                            $result['failed'][$u['id']] = $e->getMessage();
                        },
                    );
                });
            }
            return $chain->then(function () use (&$result) {
                return $result;
            });
        });
    }

    /** The periodic check: tells the interface (plugins.updates), installs them first when the user wants it. */
    private function checkPluginUpdates(): void
    {
        if ($this->ide?->plugins === null || !$this->ide->plugins->listing()) {
            return;
        }
        $this->pluginUpdates((bool)$this->app->options->get('pluginsAutoUpdate', false))->then(
            fn (array $r) => $r['updates'] ? $this->app->send('plugins.updates', $r) : null,
            fn () => null,      // offline: next time
        );
    }

    /** Downloads a plugin's zip from jumphp.net and installs it (the newest version, or $version). */
    private function marketInstall(string $id, ?string $version, string $via = 'install'): Deferred
    {
        if ($this->ide?->plugins === null) {
            return Deferred::rejected(new \RuntimeException('Open a project first.'));
        }
        if (!preg_match('/^[a-z0-9][a-z0-9-]*$/', $id)) {
            return Deferred::rejected(new \InvalidArgumentException("No plugin '$id'."));
        }
        $work = sys_get_temp_dir() . '/jumphp-plugin-' . bin2hex(random_bytes(4));
        @mkdir($work, 0700, true);
        // via: the plugin's statistics tell the IDE's installs and updates apart
        $url = $this->playground->server() . '/api/plugins/' . $id . '/download?' . http_build_query(array_filter(['version' => $version, 'via' => $via]));
        $clean = fn () => exec((PHP_OS_FAMILY === 'Windows' ? 'rmdir /s /q ' : 'rm -rf ') . escapeshellarg($work));
        return $this->processes->start(['curl', '-sSfL', '--max-time', '120', '-A', 'jumphp-ide', '-o', "$work/plugin.zip", $url], $work)['done']
            ->then(function (array $r) use ($work, $clean) {
                try {
                    if ($r['code'] !== 0 || !is_file("$work/plugin.zip")) {
                        throw new \RuntimeException('The download failed: ' . trim((string)$r['stderr']));
                    }
                    $dir = PluginPackage::unpack("$work/plugin.zip", "$work/unpacked");
                    return $this->ide->plugins->install($dir);
                } finally {
                    $clean();
                }
            }, function (\Throwable $e) use ($clean) {
                $clean();
                throw $e;
            });
    }

    /** Packs a plugin's folder (the project's) and publishes that version on jumphp.net. */
    private function marketPublish(string $dir): Deferred
    {
        $token = $this->session['token'] ?? null;
        if ($token === null) {
            return Deferred::rejected(new \RuntimeException('Sign in with jumphp.net first (the account menu, top right).'));
        }
        try {
            $zip = PluginPackage::pack($dir, sys_get_temp_dir() . '/jumphp-plugin-' . bin2hex(random_bytes(4)) . '.zip');
        } catch (\Throwable $e) {
            return Deferred::rejected($e);
        }
        $headers = (string)tempnam(sys_get_temp_dir(), 'jph');
        chmod($headers, 0600);
        file_put_contents($headers, "Authorization: Bearer $token\nAccept: application/json\nContent-Type: application/zip\nUser-Agent: jumphp-ide\n");
        $started = $this->processes->start(['curl', '-sS', '-X', 'POST', '-H', "@$headers", '--data-binary', '@' . $zip['file'], '-w', '\n%{http_code}',
            $this->playground->server() . '/api/ide/plugins'], dirname($zip['file']));
        $clean = function () use ($headers, $zip): void {
            @unlink($headers);
            @unlink($zip['file']);
        };
        return $started['done']->then(function (array $r) use ($clean) {
            $clean();
            $lines = explode("\n", rtrim((string)$r['stdout']));
            $status = (int)array_pop($lines);
            $data = json_decode(implode("\n", $lines), true);
            if ($r['code'] !== 0 || $status < 200 || $status >= 300) {
                throw new \RuntimeException(is_array($data) && isset($data['error']) ? (string)$data['error']
                    : 'Publishing failed (' . ($status ?: 'curl: ' . trim((string)$r['stderr'])) . ')');
            }
            return $data;
        }, function (\Throwable $e) use ($clean) {
            $clean();
            throw $e;
        });
    }

    private function publish(): Deferred
    {
        $slug = $this->ide !== null ? ($this->projectSettings((string)$this->ide->workspace->localPath())['slug'] ?? null) : null;
        if ($slug === null) {
            return Deferred::rejected(new \RuntimeException('This project is not one of your jumphp.net projects.'));
        }
        // the commits not pushed yet go first: jumphp.net publishes the last commit of the repository's branch
        $git = $this->ide->git;
        $pushed = $git !== null && $git->isRepository()
            ? $git->status()->then(fn (array $st) => $st['branch'] !== null && ($st['upstream'] === null || $st['ahead'] > 0) ? $git->push() : null)
            : Deferred::resolved(null);
        return $pushed->then(fn () => self::deferred($this->playground->sync((string)$slug)))->then(fn (array $r) => [
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
