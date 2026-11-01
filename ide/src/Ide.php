<?php

namespace JumPHP\Ide;

use JumPHP\Ide\Async\Deferred;
use JumPHP\Ide\Async\Processes;
use JumPHP\Ide\Claude\ClaudeCode;
use JumPHP\Ide\Git\Git;
use JumPHP\Ide\Gui\FormCode;
use JumPHP\Ide\Gui\FormFile;
use JumPHP\Ide\Php\Index;
use JumPHP\Ide\Php\Intelligence;
use JumPHP\Ide\Php\Source;
use JumPHP\Ide\Project\ProjectInfo;
use JumPHP\Ide\Run\Runner;
use JumPHP\Ide\Search\Search;
use JumPHP\Ide\Workspace\Paths;
use JumPHP\Ide\Workspace\Workspace;
use JumPHP\Ide\Workspace\WorkspaceException;

/**
 * The IDE's commands on one opened project, the same for every host: the desktop app calls them from its pages
 * (jumphp.invoke), the playground from its web API. A command takes an array and returns a plain value or a
 * Deferred (the host waits for it); an exception is the error the interface shows.
 *
 * Hosts add their own commands (sign-in, project list, the web IDE's drafts...) with register(). Events
 * (index progress, run output) go to the host's emitter.
 */
final class Ide
{
    public readonly Index $index;
    public readonly Intelligence $php;
    public readonly Search $search;
    public readonly ProjectInfo $project;
    public readonly Processes $processes;
    public ?Git $git = null;
    public ?Runner $runner = null;

    /** @var array<string, callable(array): mixed> */
    private array $commands = [];

    /** @var callable(string, array): void */
    private $emit;

    private bool $indexed = false;

    /** the Claude Code of this computer (desktop only) */
    public ?ClaudeCode $claude = null;

    /** @var array<string, mixed> capabilities a host sets (they win over the defaults of project.info) */
    public array $capabilities = [];

    /** @var list<string> folders outside the project whose PHP files may be opened (read only) */
    private array $libraryDirs = [];

    /**
     * @param callable(string $event, array $payload): void|null $emit
     * @param string|null $cacheDir where the index is kept between sessions
     */
    public function __construct(public readonly Workspace $workspace, ?string $cacheDir = null, ?callable $emit = null, ?Processes $processes = null)
    {
        $this->emit = $emit ?? fn () => null;
        $this->index = new Index($workspace, $cacheDir !== null ? $cacheDir . '/index-' . substr(sha1((string)($workspace->localPath() ?? $workspace->name())), 0, 12) . '.json' : null);
        $this->php = new Intelligence($this->index);
        $this->search = new Search($workspace);
        $this->project = new ProjectInfo($workspace);
        $this->processes = $processes ?? new Processes();
        $root = $workspace->localPath();
        if ($root !== null) {
            // the extensions' functions (SDL, OpenGL, OpenAL...) from the engine's stubs, of the project or of a game's vendor/
            $stubs = [];
            foreach ([...(glob("$root/engine/stubs/*.php") ?: []), ...(glob("$root/games/*/vendor/jumphp/engine/stubs/*.php") ?: [])] as $file) {
                $stubs[(string)realpath($file)] = true;
            }
            $this->index->addStubs(...array_keys($stubs));
            $this->git = new Git($this->processes, $root);
            $this->runner = new Runner($this->processes, $root, fn (string $event, array $payload) => ($this->emit)($event, $payload));
        }
        $this->registerCore();
        $this->registerForms();
        if ($this->git !== null) {
            $this->registerGit();
        }
        if ($this->runner !== null) {
            $this->registerRun();
        }
        if ($root !== null) {
            $this->claude = new ClaudeCode($this->processes, $root, fn (string $event, array $payload) => ($this->emit)($event, $payload));
            $this->register('claude.status', fn () => $this->claude->status());
            $this->register('claude.send', fn (array $p) => $this->claude->send((string)($p['prompt'] ?? ''), isset($p['session']) ? (string)$p['session'] : null,
                (string)($p['mode'] ?? 'acceptEdits'), isset($p['model']) && $p['model'] !== '' ? (string)$p['model'] : null,
                isset($p['effort']) && $p['effort'] !== '' ? (string)$p['effort'] : null));
            $this->register('claude.stop', fn (array $p) => $this->claude->stop((int)($p['id'] ?? 0)));
        }
    }

    /**
     * A library the project uses but does not hold (the web IDE: the engine is not in the published game):
     * its classes resolve, go to definition opens them read only, its stubs declare the extensions' functions.
     */
    public function addLibrary(string $prefix, string $dir, ?string $stubsDir = null): void
    {
        $this->index->addLibrary($prefix, $dir);
        $this->libraryDirs[] = (string)realpath($dir);
        if ($stubsDir !== null && is_dir($stubsDir)) {
            $this->index->addStubs(...(glob(rtrim($stubsDir, '/') . '/*.php') ?: []));
            $this->libraryDirs[] = (string)realpath($stubsDir);
        }
    }

    public function register(string $name, callable $handler): void
    {
        $this->commands[$name] = $handler;
    }

    public function has(string $name): bool
    {
        return isset($this->commands[$name]);
    }

    /** @return list<string> */
    public function commands(): array
    {
        return array_keys($this->commands);
    }

    /** Runs a command: its result, or a Deferred of it. */
    public function handle(string $name, mixed $payload): mixed
    {
        $handler = $this->commands[$name] ?? throw new \InvalidArgumentException("no command '$name'");
        return $handler(is_array($payload) ? $payload : []);
    }

    public function emit(string $event, array $payload): void
    {
        ($this->emit)($event, $payload);
    }

    /** A slice of background work: indexing, processes. The desktop app calls it every frame. */
    public function tick(float $budget = 0.008): void
    {
        $this->processes->poll();
        if (!$this->indexed) {
            $done = $this->index->refresh($budget);
            $this->emit('index.progress', $this->index->progress() + ['complete' => $done]);
            $this->indexed = $done;
        }
    }

    /** Files changed outside (git checkout, a run): index them again. */
    public function filesChanged(): void
    {
        $this->index->invalidate();
        $this->indexed = false;
    }

    /** The visual editor of forms (graphic PHP apps, JumPHP\Gui): form files <-> arrays, the code behind them. */
    private function registerForms(): void
    {
        $this->register('gui.read', fn (array $p) => FormFile::parse(isset($p['text']) ? (string)$p['text'] : $this->workspace->read(Paths::clean((string)($p['path'] ?? '')))));
        $this->register('gui.dump', fn (array $p) => ['content' => FormFile::dump((array)($p['form'] ?? []), (string)($p['header'] ?? ''))]);
        // the form's class with a new event method and/or its @property lines: {path (the form file), wire?: {id, type, event,
        // method?}, widgets?: {id: type}} -> {path, content, line, added, created} (the interface writes it: open editors follow)
        $this->register('gui.code', function (array $p) {
            [$path, $created] = $this->formClassFile(Paths::clean((string)($p['path'] ?? '')));
            $content = $created ? FormCode::newClass(Templates::namespaceOf($path, $this->workspace), pathinfo($path, PATHINFO_FILENAME)) : $this->workspace->read($path);
            $line = null;
            $added = false;
            if (isset($p['widgets']) && is_array($p['widgets'])) {
                $widgets = [];
                foreach ($p['widgets'] as $id => $type) {
                    if (preg_match('/^[A-Za-z_]\w*$/', (string)$id)) {
                        $widgets[(string)$id] = FormCode::classOf((string)$type);
                    }
                }
                $content = FormCode::properties($content, $widgets);
            }
            if (!empty($p['wire']) && is_array($p['wire'])) {
                $w = $p['wire'];
                $event = (string)($w['event'] ?? 'click');
                $method = (string)($w['method'] ?? FormCode::handlerName((string)($w['id'] ?? 'form'), $event));
                if (!preg_match('/^[A-Za-z_]\w*$/', $method)) {
                    throw new \InvalidArgumentException("$method is not a method name");
                }
                ['content' => $content, 'line' => $line, 'added' => $added] = FormCode::wire($content, $method, FormCode::classOf((string)($w['type'] ?? '')), $event);
            }
            return ['path' => $path, 'content' => $content, 'line' => $line, 'added' => $added, 'created' => $created];
        });
    }

    /**
     * The class of a form file: games/x/forms/MainForm.form.yaml -> the file of class MainForm under games/x/src/
     * (src/Form/MainForm.php when there is none yet).
     *
     * @return array{0: string, 1: bool} its path, whether it has to be created
     */
    private function formClassFile(string $form): array
    {
        $class = preg_replace('/\.form\.ya?ml$/', '', basename($form)) ?: 'MainForm';
        $app = preg_match('#^(.*?)/?forms/#', $form, $m) ? $m[1] : dirname($form);
        $src = ($app === '' || $app === '.' ? '' : "$app/") . 'src/';
        foreach (array_keys($this->workspace->files()) as $file) {
            if (str_starts_with($file, $src) && basename($file) === "$class.php") {
                return [$file, false];
            }
        }
        return ["{$src}Form/$class.php", true];
    }

    private function registerCore(): void
    {
        $this->register('project.info', fn () => $this->project->describe() + [
            // what the interface shows; the web IDE's host answers its own (gitMode 'drafts', preview, tests)
            'capabilities' => $this->capabilities + [
                'git' => $this->git !== null && $this->git->isRepository(), 'gitMode' => 'local',
                'branches' => true, 'log' => true, 'blame' => true, 'stage' => true,
                'run' => $this->runner !== null, 'preview' => false, 'tests' => false,
                'local' => $this->workspace->localPath() !== null,
                'claude' => $this->claude !== null,
            ],
            'root' => $this->workspace->localPath(),
        ]);
        $this->register('files.list', fn (array $p) => ['files' => $this->listing(!empty($p['all']))]);
        $this->register('file.read', fn (array $p) => $this->read((string)($p['path'] ?? '')));
        $this->register('file.write', function (array $p) {
            $path = Paths::clean((string)($p['path'] ?? ''));
            $this->workspace->write($path, (string)($p['content'] ?? ''));
            if (Paths::extension($path) === 'php') {
                $this->index->update($path);
            }
            return ['saved' => true, 'mtime' => $this->workspace->mtime($path)];
        });
        $this->register('file.create', function (array $p) {
            $path = Paths::clean((string)($p['path'] ?? ''));
            if ($this->workspace->exists($path)) {
                throw new WorkspaceException("$path already exists");
            }
            $content = array_key_exists('content', $p) ? (string)$p['content'] : Templates::forFile($path, $this->workspace);
            $this->workspace->write($path, $content);
            if (Paths::extension($path) === 'php') {
                $this->index->update($path);
            }
            return ['path' => $path];
        });
        $this->register('file.delete', function (array $p) {
            $path = Paths::clean((string)($p['path'] ?? ''));
            $this->workspace->delete($path);
            $this->filesChanged();
            return ['deleted' => $path];
        });
        $this->register('file.rename', function (array $p) {
            $from = Paths::clean((string)($p['from'] ?? ''));
            $to = Paths::clean((string)($p['to'] ?? ''));
            $this->workspace->rename($from, $to);
            $this->filesChanged();
            return ['from' => $from, 'to' => $to];
        });
        $this->register('edits.apply', function (array $p) {
            // rename edits for files that are not open in the editor: {path: [{line, col, length, text}]}
            $changed = [];
            foreach ((array)($p['edits'] ?? []) as $path => $edits) {
                $path = Paths::clean((string)$path);
                $text = $this->workspace->read($path);
                $source = new Source($text);
                $list = array_map(fn ($e) => ['start' => $source->offset((int)$e['line'], (int)$e['col'])] + $e, (array)$edits);
                usort($list, fn ($a, $b) => $b['start'] <=> $a['start']);
                foreach ($list as $edit) {
                    $end = $source->offset((int)$edit['line'], (int)$edit['col'] + (int)$edit['length']);
                    $text = substr($text, 0, $edit['start']) . $edit['text'] . substr($text, $end);
                }
                $this->workspace->write($path, $text);
                if (Paths::extension($path) === 'php') {
                    $this->index->update($path);
                }
                $changed[] = $path;
            }
            return ['files' => $changed];
        });

        $at = fn (array $p) => [(string)($p['path'] ?? ''), (string)($p['text'] ?? ''), (int)($p['line'] ?? 1), (int)($p['column'] ?? 1)];
        $this->register('php.complete', fn (array $p) => $this->php->complete(...$at($p)));
        $this->register('php.definition', fn (array $p) => $this->php->definition(...$at($p)));
        $this->register('php.references', fn (array $p) => $this->php->references(...[...$at($p), $p['declarations'] ?? true]));
        $this->register('php.hierarchy', fn (array $p) => isset($p['class']) ? $this->php->hierarchyOf((string)$p['class'])
            : $this->php->typeHierarchy(...$at($p)));
        $this->register('libraries.list', fn () => $this->index->libraries());
        $this->register('libraries.files', fn (array $p) => $this->index->libraryListing((string)($p['dir'] ?? '')));
        $this->register('php.implementations', fn (array $p) => $this->php->implementations(...$at($p)));
        $this->register('php.hover', fn (array $p) => $this->php->hover(...$at($p)));
        $this->register('php.signature', fn (array $p) => $this->php->signatureHelp(...$at($p)));
        $this->register('php.rename', fn (array $p) => $this->php->rename(...[...$at($p), (string)($p['newName'] ?? '')]));
        $this->register('php.symbol', fn (array $p) => (fn ($s) => $s === null ? null : array_diff_key($s, ['function' => 1]))($this->php->symbolAt(...$at($p))));
        $this->register('php.diagnostics', fn (array $p) => $this->php->diagnostics((string)($p['path'] ?? ''), (string)($p['text'] ?? '')));
        $this->register('php.outline', fn (array $p) => $this->php->outline((string)($p['text'] ?? (isset($p['path']) ? $this->workspace->read((string)$p['path']) : ''))));
        $this->register('php.symbols', fn (array $p) => $this->php->searchSymbols((string)($p['query'] ?? ''), (int)($p['limit'] ?? 60)));
        $this->register('php.classes', fn (array $p) => array_slice($this->index->classNames((string)($p['query'] ?? '')), 0, (int)($p['limit'] ?? 60)));
        $this->register('index.status', fn () => $this->index->progress() + ['complete' => $this->indexed]);
        $this->register('index.rebuild', function () {
            foreach (array_keys($this->index->files()) as $path) {
                $this->index->forget($path);
            }
            $this->filesChanged();
            return true;
        });

        $this->register('search.files', fn (array $p) => $this->search->files((string)($p['query'] ?? ''), (int)($p['limit'] ?? 50)));
        $this->register('search.text', fn (array $p) => $this->search->find((string)($p['query'] ?? ''), (array)($p['options'] ?? [])));
        $this->register('search.replace', function (array $p) {
            $result = $this->search->replace((string)($p['query'] ?? ''), (string)($p['replacement'] ?? ''), (array)($p['options'] ?? []), isset($p['paths']) ? (array)$p['paths'] : null);
            $this->filesChanged();
            return $result;
        });
    }

    /** @return list<array{path: string, size: int}> */
    private function listing(bool $all): array
    {
        $out = [];
        foreach ($this->workspace->files($all) as $path => $meta) {
            $out[] = ['path' => $path, 'size' => $meta['size']];
        }
        return $out;
    }

    /** @return array<string, mixed> a text file's content, or what the editor needs to show another file */
    private function read(string $path): array
    {
        if (str_starts_with($path, '/')) {
            // a library file outside the project (go to definition into vendor/): read only, PHP only
            $real = realpath($path);
            // the libraries added, the engine of the repository, and the ones the index found outside the project
            // (a game whose vendor/jumphp/engine links to the JumPHP checkout: External Libraries)
            $found = array_map(fn (array $l) => (string)realpath($l['dir']), array_filter($this->index->libraries(), fn (array $l) => $l['external']));
            $library = $real !== false && array_filter([...$this->libraryDirs, ...$found, ...(($root = $this->workspace->localPath()) !== null ? [(string)realpath("$root/engine")] : [])],
                fn (string $dir) => $dir !== '' && str_starts_with($real, $dir . '/'));
            if ($real === false || !str_ends_with($real, '.php') || (!str_contains($real, '/vendor/') && !$library)) {
                throw new WorkspaceException("cannot open $path");
            }
            return ['path' => $path, 'text' => true, 'content' => (string)file_get_contents($real), 'readOnly' => true];
        }
        $path = Paths::clean($path);
        $content = $this->workspace->read($path);
        $ext = Paths::extension($path);
        if (in_array($ext, ['png', 'jpg', 'jpeg', 'gif', 'webp', 'bmp', 'ico'], true)) {
            return ['path' => $path, 'text' => false, 'image' => 'data:image/' . ($ext === 'jpg' ? 'jpeg' : ($ext === 'ico' ? 'x-icon' : $ext)) . ';base64,' . base64_encode($content), 'size' => strlen($content)];
        }
        if (in_array($ext, ['wav', 'ogg', 'mp3'], true)) {
            return ['path' => $path, 'text' => false, 'audio' => 'data:audio/' . ($ext === 'mp3' ? 'mpeg' : $ext) . ';base64,' . base64_encode($content), 'size' => strlen($content)];
        }
        if (strlen($content) > 5_000_000 || !mb_check_encoding($content, 'UTF-8') || (str_contains($content, "\0") && !Paths::isText($path))) {
            return ['path' => $path, 'text' => false, 'size' => strlen($content)];
        }
        return ['path' => $path, 'text' => true, 'content' => $content, 'readOnly' => str_contains($path, 'vendor/'), 'mtime' => $this->workspace->mtime($path)];
    }

    private function registerGit(): void
    {
        $git = $this->git;
        $after = function (Deferred $d) {
            return $d->then(function ($r) {
                $this->filesChanged();
                return $r;
            });
        };
        $output = fn (string $chunk) => $this->emit('git.output', ['chunk' => $chunk]);
        $this->register('git.status', fn () => $git->isRepository() ? $git->status() : null);
        $this->register('git.original', fn (array $p) => $git->show((string)$p['path'], (string)($p['revision'] ?? 'HEAD'))->then(fn ($c) => ['content' => $c]));
        $this->register('git.stage', fn (array $p) => $git->stage((array)$p['paths']));
        $this->register('git.unstage', fn (array $p) => $git->unstage((array)$p['paths']));
        $this->register('git.rollback', fn (array $p) => $after($git->rollback((array)$p['paths'])));
        $this->register('git.commit', function (array $p) use ($git, $output) {
            $commit = $git->commit((string)($p['message'] ?? ''), isset($p['paths']) ? (array)$p['paths'] : null, !empty($p['amend']));
            return empty($p['push']) ? $commit->then(fn ($sha) => ['sha' => $sha])
                : $commit->then(fn ($sha) => $git->push($output)->then(fn () => ['sha' => $sha, 'pushed' => true]));
        });
        $this->register('git.push', fn () => $git->push($output));
        $this->register('git.pull', fn (array $p) => $after($git->pull($output, !empty($p['rebase']))));
        $this->register('git.fetch', fn () => $git->fetch($output));
        $this->register('git.branches', fn () => $git->branches());
        $this->register('git.checkout', fn (array $p) => $after($git->checkout((string)$p['branch'], !empty($p['create']))));
        $this->register('git.deleteBranch', fn (array $p) => $git->deleteBranch((string)$p['branch'], !empty($p['force'])));
        $this->register('git.merge', fn (array $p) => $after($git->merge((string)$p['branch'])));
        $this->register('git.log', fn (array $p) => $git->log(isset($p['path']) ? (string)$p['path'] : null, (int)($p['limit'] ?? 200)));
        $this->register('git.commitFiles', fn (array $p) => $git->commitFiles((string)$p['sha']));
        $this->register('git.blame', fn (array $p) => $git->blame((string)$p['path'], isset($p['content']) ? (string)$p['content'] : null));
        $this->register('git.remotes', fn () => $git->remotes());
        $this->register('git.stash', fn (array $p) => $after($git->stash($p['message'] ?? null)));
        $this->register('git.stashPop', fn () => $after($git->stashPop()));
    }

    private function registerRun(): void
    {
        $runner = $this->runner;
        $this->register('run.start', function (array $p) use ($runner) {
            $config = null;
            foreach ($this->project->describe()['run'] as $candidate) {
                if ($candidate['id'] === ($p['id'] ?? null)) {
                    $config = $candidate;
                }
            }
            if ($config === null && isset($p['command']) && is_array($p['command'])) {
                $config = ['id' => 'adhoc', 'label' => implode(' ', $p['command']), 'command' => array_map('strval', $p['command']), 'kind' => (string)($p['kind'] ?? 'tool')];
            }
            if ($config === null) {
                throw new \InvalidArgumentException('unknown run configuration ' . ($p['id'] ?? ''));
            }
            return $runner->start($config, array_map('strval', (array)($p['env'] ?? [])));
        });
        $this->register('run.stop', fn (array $p) => $runner->stop((int)$p['id']));
        $this->register('run.list', fn () => $runner->running());
    }
}
