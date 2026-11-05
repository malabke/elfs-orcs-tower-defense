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
        if ($kind === 'extension') {
            return $this->localExtension($title, $p);
        }
        if ($kind === 'plugin') {
            if ($this->main->user() === null) {
                return Deferred::rejected(new \RuntimeException('Sign in with jumphp.net first: it creates the project\'s GitHub repository on your account.'));
            }
            return $this->local($kind, $title, $p);
        }
        if (!isset(self::PLAYGROUND_KINDS[$kind])) {
            return Deferred::rejected(new \InvalidArgumentException("Unknown kind of project: $kind"));
        }
        return $this->onPlayground($kind, $title, $p);
    }

    /**
     * A game or an application, made on this computer only: nothing is created on GitHub or jumphp.net until the user publishes
     * (Publish, or "Create its GitHub repository"): a project made to try the IDE leaves no trace online.
     */
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
        $id = trim((string)preg_replace('/[^a-z0-9]+/', '-', strtolower($title)), '-') ?: 'project';
        if (!preg_match('/^[a-z]/', $id)) {
            $id = "project-$id";
        }
        $parent = $this->folder($p);
        @mkdir($parent, 0777, true);
        $root = "$parent/$id";
        for ($n = 2; file_exists($root); $n++) {
            $root = "$parent/$id-$n";
        }
        $description = (string)($p['description'] ?? '');
        $login = (string)($this->main->user()['login'] ?? 'jumphp');
        return $this->template($root, $parent)
            ->then(function () use ($root, $kind, $id, $title, $description, $brief, $p) {
                $this->progress('The skeleton of your project…');
                $dir = $this->scaffold($root, self::PLAYGROUND_KINDS[$kind], $id, $title, $description, $brief);
                if ($kind === 'cpc' && !empty($p['dsk'])) {
                    $this->main->saveProjectSettings($root, array_filter(['dsk' => (string)$p['dsk'], 'roms' => (string)($p['roms'] ?? '')]));
                }
                if ($kind === 'hybrid') {
                    $yaml = "$root/$dir/config/project.yaml";
                    $this->makeHybrid(is_file($yaml) ? $yaml : "$root/$dir/config/game.yaml");
                }
                return $dir;
            })
            ->then(function (string $dir) use ($root, $title, $login, $kind) {
                $git = new Git($this->processes, $root);
                $git->setFallbackAuthor(['name' => $login, 'email' => $login . '@users.noreply.github.com']);
                return $git->commit($title . ': a new JumPHP ' . (in_array($kind, ['original', 'cpc', 'port', 'hybrid'], true) ? 'game' : 'application'), ['.']);
            })
            ->then(fn () => $this->main->openProject($root));
    }

    /**
     * What jumphp.net used to do on its server: the skeleton of the project's kind in games/<id> (or apps/<id>), named after the project.
     *
     * @return string the project's folder, relative to $root
     */
    private function scaffold(string $root, string $pk, string $id, string $title, string $description, string $brief): string
    {
        foreach ([...(glob("$root/games/*", GLOB_ONLYDIR) ?: []), ...(glob("$root/apps/*", GLOB_ONLYDIR) ?: []), ...(glob("$root/extensions/*", GLOB_ONLYDIR) ?: [])] as $example) {
            self::removeTree($example);
        }
        self::removeTree("$root/.github/workflows");   // JumPHP's own CI is not the project's (and pushing it needs a token scope)
        [$skeleton, $script, $extra] = match ($pk) {
            'gui' => ['skeleton-gui', 'new-gui.php', ['0']],
            'mobile' => ['skeleton-gui', 'new-gui.php', ['1']],
            'desktop' => ['skeleton-app', 'new-app.php', []],
            'cli' => ['skeleton-cli', 'new-app.php', []],
            default => ['skeleton', 'new-game.php', []],
        };
        if (!is_dir("$root/$skeleton") || !is_file("$root/tools/$script")) {
            throw new \RuntimeException("This version of JumPHP has no $skeleton/: an application needs a newer one");
        }
        // applications go to apps/<id> with a JumPHP that knows that folder (bin/jumphp's project_path), else games/
        $isApp = in_array($pk, ['gui', 'mobile', 'desktop', 'cli'], true);
        $dir = ($isApp && str_contains((string)@file_get_contents("$root/bin/jumphp"), 'project_path()') ? 'apps' : 'games') . "/$id";
        self::copyTree("$root/$skeleton", "$root/$dir");
        self::runScript("$root/tools/$script", "$root/$dir", [$id, $title, ...$extra]);
        $yamlFile = is_file("$root/$dir/config/project.yaml") ? "$root/$dir/config/project.yaml" : "$root/$dir/config/game.yaml";
        file_put_contents($yamlFile, preg_replace('/^(\s*description:\s*).*/m', '${1}' . json_encode($description, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            (string)file_get_contents($yamlFile), 1));
        file_put_contents("$root/$dir/BRIEF.md", "# $title\n\n" . ($description !== '' ? "$description\n\n" : '') . ($brief !== '' ? $brief : 'What it should be: write it here.') . "\n");
        file_put_contents("$root/README.md", "# $title\n\n" . ($description !== '' ? "$description\n\n" : '') . "Made with [JumPHP](https://jumphp.net).\n");
        return $dir;
    }

    /** tools/new-game.php, new-gui.php, new-app.php: small scripts that read their folder and argv; run here, in this process, so that no PHP or Docker is needed. */
    private static function runScript(string $script, string $dir, array $args): void
    {
        $cwd = getcwd();
        chdir($dir);
        ob_start();
        try {
            (static function () use ($script, $args): void {
                $argv = [$script, ...$args];
                include $script;
            })();
        } finally {
            ob_end_clean();
            $cwd !== false && chdir($cwd);
        }
    }

    public static function copyTree(string $from, string $to): void
    {
        @mkdir($to, 0777, true);
        foreach (scandir($from) ?: [] as $name) {
            if ($name === '.' || $name === '..') {
                continue;
            }
            is_dir("$from/$name") && !is_link("$from/$name") ? self::copyTree("$from/$name", "$to/$name") : copy("$from/$name", "$to/$name");
        }
    }

    public static function removeTree(string $dir): void
    {
        foreach (scandir($dir) ?: [] as $name) {
            if ($name !== '.' && $name !== '..') {
                if (is_dir("$dir/$name") && !is_link("$dir/$name")) {
                    self::removeTree("$dir/$name");
                } else {
                    @chmod("$dir/$name", 0666);   // git's packs are read-only: Windows refuses to delete them
                    @unlink("$dir/$name");
                }
            }
        }
        @rmdir($dir);
    }

    /**
     * An engine extension, made on this computer only (like a game): the package in extensions/<id>, its example game in games/<id>-demo, one commit.
     * Publishing (Main::publishExtension) makes the package's own repository and tags it.
     */
    private function localExtension(string $title, array $p): Deferred
    {
        $id = trim((string)preg_replace('/[^a-z0-9]+/', '-', strtolower($title)), '-') ?: 'extension';
        if (!preg_match('/^[a-z]/', $id)) {
            $id = "extension-$id";
        }
        $parent = $this->folder($p);
        @mkdir($parent, 0777, true);
        $root = "$parent/$id";
        for ($n = 2; file_exists($root); $n++) {
            $root = "$parent/$id-$n";
        }
        $description = (string)($p['description'] ?? '');
        $brief = trim((string)($p['brief'] ?? ''));
        $login = (string)($this->main->user()['login'] ?? 'jumphp');
        return $this->template($root, $parent)
            ->then(function () use ($root, $id, $title, $description, $brief) {
                $this->progress('The skeleton of your extension…');
                foreach (["extensions/$id" => 'skeleton-extension/package', "games/$id-demo" => 'skeleton-extension/example'] as $to => $from) {
                    if (!is_dir("$root/$from")) {
                        throw new \RuntimeException('This version of JumPHP has no ' . $from . ': update JumPHP first');
                    }
                    self::copyTree("$root/$from", "$root/$to");
                }
                foreach (['app.php', 'phpstan.neon'] as $f) {
                    is_file("$root/skeleton/$f") && copy("$root/skeleton/$f", "$root/games/$id-demo/$f");
                }
                self::runScript("$root/tools/new-extension.php", $root, [$id, $title]);
                $composer = "$root/extensions/$id/composer.json";
                $json = json_decode((string)file_get_contents($composer), true) ?: [];
                if ($description !== '') {
                    $json['description'] = $description;
                }
                file_put_contents($composer, json_encode($json, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) . "\n");
                file_put_contents("$root/extensions/$id/BRIEF.md", "# $title\n\n" . ($description !== '' ? "$description\n\n" : '') . ($brief !== '' ? $brief : 'What it should be: write it here.') . "\n");
                file_put_contents("$root/README.md", "# $title\n\n" . ($description !== '' ? "$description\n\n" : '') . "An engine extension for [JumPHP](https://jumphp.net): extensions/$id (the package) and games/$id-demo (its example game).\n");
                foreach (["$root/.github/workflows"] as $dir) {
                    self::removeTree($dir);
                }
                return null;
            })
            ->then(function () use ($root, $login, $title) {
                $git = new Git($this->processes, $root);
                $git->setFallbackAuthor(['name' => $login, 'email' => $login . '@users.noreply.github.com']);
                return $git->commit($title . ': a JumPHP engine extension', ['.']);
            })
            ->then(fn () => $this->main->openProject($root));
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
        return $this->template($root, $parent)
            ->then(function () use ($root, $id, $title, $extension) {
                $command = $extension ? 'extension:new' : 'plugin:new';
                $this->progress("bin/jumphp {$command}…");
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

    /** A new project folder made of JumPHP's files: the template cloned, its history dropped, cleaned of JumPHP's own extensions / examples, a fresh git repository. */
    private function template(string $root, string $parent): Deferred
    {
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
                self::removeTree("$root/.git");
                // a project of its own: JumPHP's examples, extensions and development files are not in it (tools/project-clean.sh, the same for every
                // New Project); an older JumPHP without that script: its examples at least
                if (is_file("$root/tools/project-clean.sh")) {
                    exec('cd ' . escapeshellarg($root) . ' && bash tools/project-clean.sh --new >/dev/null 2>&1', $out, $code);
                } else {
                    $code = 1;
                }
                if ($code !== 0) {   // no bash here (Windows without Git Bash), or an older JumPHP: the examples at least
                    foreach ([...(glob("$root/games/*", GLOB_ONLYDIR) ?: []), ...(glob("$root/apps/*", GLOB_ONLYDIR) ?: []), ...(glob("$root/extensions/*", GLOB_ONLYDIR) ?: [])] as $example) {
                        self::removeTree($example);
                    }
                }
                return $this->run(['git', 'init', '-q', '-b', 'main'], $root)
                    ->then(fn () => $this->run(['git', 'remote', 'add', 'jumphp', self::TEMPLATE], $root))
                    ->then(fn () => $this->noPushToJumphp($root));
            });
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
            } elseif ($r->status === 401) {
                $d->reject(new \RuntimeException('GitHub refuses the saved token (“Bad credentials”: revoked or expired). Sign out of jumphp.net in the IDE and sign in with GitHub again, then retry.'));
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
