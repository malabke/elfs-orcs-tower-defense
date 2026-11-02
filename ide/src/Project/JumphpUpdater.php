<?php

namespace JumPHP\Ide\Project;

use JumPHP\Ide\Async\Deferred;
use JumPHP\Ide\Async\Processes;

/**
 * Keeps the JumPHP of a project (bin/jumphp, the engine, tools, docker, skeletons, docs...) up to date with JumPHP's
 * repository, as the IDE is: a project made by the IDE (or by composer create-project) has its own git history, so
 * `git pull` of JumPHP can not merge into it. Its games (games/) and README.md are never touched.
 *
 * JumPHP's commits are fetched into a cache of the IDE (a bare repository), never into the project's git: the
 * project's history, index and remotes stay as they are (it does not even have to be a git repository). A project that
 * holds JumPHP's history itself (JumPHP's own repository, a fork) is left to git: nothing is done there.
 *
 * The commit of JumPHP the project's files come from is kept in .jumphp/jumphp.json. A file the user changed since
 * (its content differs from that commit's) is never replaced without a yes: check() says which; update() replaces
 * them only when told to. Without that record (older projects) every difference counts as a change: asked once.
 */
final class JumphpUpdater
{
    public const REPOSITORY = 'https://github.com/davidnurdin/jumPhp.git';
    public const RECORD = '.jumphp/jumphp.json';

    /** JumPHP's first commit: a repository that has it holds JumPHP's history (git pull updates it, not this) */
    public const ROOT_COMMIT = 'ffefc796f8ca1d3b4ff674305598b3cb51a9e52b';

    /** top-level entries of the repository that are the project's own, never JumPHP's to replace */
    private const OWN = ['games', 'README.md', '.jumphp', '.git', '.env'];

    private string $cache;

    public function __construct(private Processes $processes, private string $root, private string $repository = self::REPOSITORY, ?string $cache = null,
        private string $rootCommit = self::ROOT_COMMIT)
    {
        $home = getenv('LOCALAPPDATA') ?: ((getenv('HOME') ?: sys_get_temp_dir()) . '/.cache');
        $this->cache = $cache ?? rtrim(str_replace('\\', '/', $home), '/') . '/jumphp/jumphp.git';
    }

    public function isJumphpProject(): bool
    {
        return is_file("$this->root/bin/jumphp") && is_dir("$this->root/engine") && is_dir("$this->root/tools");
    }

    /** The commit the project's JumPHP files come from (null: unknown, an older project). */
    public function current(): ?string
    {
        $record = json_decode((string)@file_get_contents("$this->root/" . self::RECORD), true);
        return is_array($record) && is_string($record['commit'] ?? null) ? $record['commit'] : null;
    }

    /**
     * What an update would do: {latest, current, upToDate, files (to write), deleted, changed (files of these the user
     * changed: they need a yes), known (the record exists)}.
     */
    public function check(): Deferred
    {
        if (!$this->isJumphpProject()) {
            return Deferred::resolved(['upToDate' => true, 'jumphp' => false]);
        }
        return $this->holdsHistory()->then(function (bool $history) {
            if ($history) {
                return ['upToDate' => true, 'history' => true];
            }
            return $this->fetch('main')
                ->then(fn () => $this->cached(['rev-parse', 'FETCH_HEAD']))
                ->then(function (array $r) {
                    $latest = trim($r['stdout']);
                    $current = $this->current();
                    if ($current === $latest) {
                        return ['upToDate' => true, 'latest' => $latest, 'current' => $current, 'files' => [], 'deleted' => [], 'changed' => []];
                    }
                    return $this->tree($latest)->then(function (array $new) use ($latest, $current) {
                        $base = $current === null ? Deferred::resolved(null)
                            : $this->fetch($current)->then(fn () => $this->tree($current), fn () => null);
                        return $base->then(fn (?array $old) => $this->compare($latest, $current, $new, $old));
                    });
                });
        });
    }

    /** Whether the project's own git has JumPHP's history (JumPHP's repository, a fork, a clone of it). */
    private function holdsHistory(): Deferred
    {
        if (!is_dir("$this->root/.git")) {
            return Deferred::resolved(false);
        }
        return $this->processes->start(['git', 'cat-file', '-e', $this->rootCommit . '^{commit}'], $this->root)['done']
            ->then(fn (array $r) => $r['code'] === 0, fn () => false);
    }

    /**
     * @param array<string, array{0: string, 1: string}> $new path => [mode, blob]
     * @param array<string, array{0: string, 1: string}>|null $old
     */
    private function compare(string $latest, ?string $current, array $new, ?array $old): Deferred
    {
        $paths = array_values(array_unique([...array_keys($new), ...array_keys($old ?? [])]));
        return $this->hashes($paths)->then(function (array $mine) use ($latest, $current, $new, $old) {
            $write = $delete = $changed = [];
            foreach ($new as $path => [, $blob]) {
                if (($mine[$path] ?? null) === $blob) {
                    continue;                      // already the newest
                }
                $write[] = $path;
                $base = $old[$path][1] ?? null;
                if (isset($mine[$path]) && ($old === null || $mine[$path] !== $base)) {
                    $changed[] = $path;            // the user's version differs from the one JumPHP gave
                }
            }
            foreach ($old ?? [] as $path => [, $blob]) {
                if (!isset($new[$path]) && isset($mine[$path])) {
                    $delete[] = $path;             // removed from JumPHP
                    if ($mine[$path] !== $blob) {
                        $changed[] = $path;
                    }
                }
            }
            return ['upToDate' => !$write && !$delete, 'latest' => $latest, 'current' => $current, 'files' => $write, 'deleted' => $delete,
                'changed' => $changed, 'known' => $old !== null, 'modes' => array_map(fn ($e) => $e[0], array_intersect_key($new, array_flip($write))),
                'blobs' => array_map(fn ($e) => $e[1], array_intersect_key($new, array_flip($write)))];
        });
    }

    /**
     * Brings the project's JumPHP files to the newest JumPHP. Without $force, the files the user changed stay as they
     * are (and the update is not recorded as complete: asked again).
     */
    public function update(bool $force = false): Deferred
    {
        return $this->check()->then(function (array $plan) use ($force) {
            if (!empty($plan['upToDate'])) {
                if (isset($plan['latest']) && $this->current() !== $plan['latest']) {
                    $this->record($plan['latest']);
                }
                return self::summary($plan) + ['updated' => [], 'removed' => [], 'kept' => []];
            }
            $skip = $force ? [] : array_flip($plan['changed']);
            $write = array_values(array_filter($plan['files'], fn ($p) => !isset($skip[$p])));
            $delete = array_values(array_filter($plan['deleted'], fn ($p) => !isset($skip[$p])));
            return $this->write($write, $plan['blobs'], $plan['modes'])->then(function () use ($plan, $write, $delete, $skip) {
                foreach ($delete as $path) {
                    @unlink("$this->root/$path");
                }
                if (!$skip) {
                    $this->record($plan['latest']);
                }
                return self::summary($plan) + ['updated' => $write, 'removed' => $delete, 'kept' => array_keys($skip)];
            });
        });
    }

    /** the plan without the blob ids and modes (what the interface is told) */
    private static function summary(array $plan): array
    {
        unset($plan['blobs'], $plan['modes']);
        return $plan;
    }

    /**
     * Writes files from the cache's blobs (git cat-file --batch): the project's git (index, history) is not involved.
     *
     * @param list<string> $paths @param array<string, string> $blobs @param array<string, string> $modes
     */
    private function write(array $paths, array $blobs, array $modes): Deferred
    {
        if (!$paths) {
            return Deferred::resolved(null);
        }
        $input = implode("\n", array_map(fn ($p) => $blobs[$p], $paths)) . "\n";
        return $this->cached(['cat-file', '--batch'], $input)->then(function (array $r) use ($paths, $modes) {
            $out = $r['stdout'];
            $at = 0;
            foreach ($paths as $path) {
                $eol = strpos($out, "\n", $at);
                if ($eol === false || !preg_match('/^[0-9a-f]+ blob (\d+)$/', substr($out, $at, $eol - $at), $m)) {
                    throw new \RuntimeException("JumPHP's $path could not be read from the cache");
                }
                $content = substr($out, $eol + 1, (int)$m[1]);
                $at = $eol + 1 + (int)$m[1] + 1;
                $file = "$this->root/$path";
                @mkdir(dirname($file), 0777, true);
                if (file_put_contents($file, $content) === false) {
                    throw new \RuntimeException("cannot write $path");
                }
                @chmod($file, ($modes[$path] ?? '') === '100755' ? 0755 : 0644);
            }
            return null;
        });
    }

    private function record(string $commit): void
    {
        @mkdir("$this->root/.jumphp", 0777, true);
        file_put_contents("$this->root/" . self::RECORD, json_encode(['repository' => $this->repository, 'commit' => $commit,
            'updated' => gmdate('c')], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n");
    }

    /** One commit of JumPHP (main, or a commit id) into the cache, without its history. */
    private function fetch(string $what): Deferred
    {
        $ready = is_dir("$this->cache/objects") ? Deferred::resolved(null) : $this->run(['git', 'init', '-q', '--bare', $this->cache], dirname($this->cache, 1), true);
        return $ready->then(fn () => $this->cached(['fetch', '-q', '--depth', '1', $this->repository, $what]));
    }

    /** @return Deferred the files of a commit that are JumPHP's (path => [mode, blob id]) */
    private function tree(string $commit): Deferred
    {
        return $this->cached(['ls-tree', '-r', '--full-tree', $commit])->then(function (array $r) {
            $files = [];
            foreach (explode("\n", $r['stdout']) as $line) {
                if (!preg_match('/^(\d+) blob ([0-9a-f]+)\t(.+)$/', $line, $m) || $m[1] === '120000') {
                    continue;
                }
                $path = $m[3];
                if (in_array(explode('/', $path)[0], self::OWN, true)) {
                    continue;
                }
                $files[$path] = [$m[1], $m[2]];
            }
            return $files;
        });
    }

    /** @return Deferred blob ids of the project's files that exist (git hash-object, the project's line endings rules) */
    private function hashes(array $paths): Deferred
    {
        $present = array_values(array_filter($paths, fn ($p) => is_file("$this->root/$p")));
        if (!$present) {
            return Deferred::resolved([]);
        }
        return $this->run(['git', 'hash-object', '--stdin-paths'], $this->root, false, implode("\n", $present) . "\n")->then(function (array $r) use ($present) {
            $ids = preg_split('/\s+/', trim($r['stdout'])) ?: [];
            return count($ids) === count($present) ? array_combine($present, $ids) : [];
        });
    }

    /** git on the cache (a bare repository of the IDE) */
    private function cached(array $args, ?string $input = null): Deferred
    {
        return $this->run(['git', '--git-dir=' . $this->cache, ...$args], $this->root, false, $input);
    }

    private function run(array $command, string $cwd, bool $mkdir = false, ?string $input = null): Deferred
    {
        if ($mkdir) {
            @mkdir($cwd, 0777, true);
        }
        return $this->processes->start($command, $cwd, [], null, $input)['done']->then(function (array $r) use ($command) {
            if ($r['code'] !== 0) {
                $name = $command[1] === '--git-dir=' . $this->cache ? $command[2] : $command[1];
                throw new \RuntimeException("git $name: " . trim($r['stderr'] ?: $r['stdout']));
            }
            return $r;
        });
    }
}
