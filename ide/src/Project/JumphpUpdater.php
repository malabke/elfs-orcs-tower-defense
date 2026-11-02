<?php

namespace JumPHP\Ide\Project;

use JumPHP\Ide\Async\Deferred;
use JumPHP\Ide\Async\Processes;

/**
 * Keeps the JumPHP of a project (bin/jumphp, the engine, tools, docker, skeletons, docs...) up to date with JumPHP's
 * repository, as the IDE is: a project made by the IDE (or by composer create-project) has its own git history, so
 * `git pull` of JumPHP can not merge into it. Its games (games/) and README.md are never touched.
 *
 * The commit of JumPHP the project's files come from is kept in .jumphp/jumphp.json. A file the user changed since
 * (its content differs from that commit's) is never replaced without a yes: check() says which; update() replaces
 * them only when told to. Without that record (older projects) every difference counts as a change: asked once.
 */
final class JumphpUpdater
{
    public const REPOSITORY = 'https://github.com/davidnurdin/jumPhp.git';
    public const RECORD = '.jumphp/jumphp.json';

    /** top-level entries of the repository that are the project's own, never JumPHP's to replace */
    private const OWN = ['games', 'README.md', '.jumphp', '.git', '.env'];

    public function __construct(private Processes $processes, private string $root, private string $repository = self::REPOSITORY)
    {
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
     * What an update would do: {latest, current, upToDate, files (to write or delete), changed (files of these the user
     * changed: they need a yes)}.
     */
    public function check(): Deferred
    {
        if (!$this->isJumphpProject()) {
            return Deferred::resolved(['upToDate' => true, 'jumphp' => false]);
        }
        return $this->git(['fetch', '-q', '--depth', '1', $this->repository, 'main'])
            ->then(fn () => $this->git(['rev-parse', 'FETCH_HEAD']))
            ->then(function (array $r) {
                $latest = trim($r['stdout']);
                $current = $this->current();
                if ($current === $latest) {
                    return ['upToDate' => true, 'latest' => $latest, 'current' => $current, 'files' => [], 'changed' => []];
                }
                return $this->tree($latest)->then(function (array $new) use ($latest, $current) {
                    $base = $current === null ? Deferred::resolved(null)
                        : $this->git(['fetch', '-q', '--depth', '1', $this->repository, $current])->then(fn () => $this->tree($current), fn () => null);
                    return $base->then(fn (?array $old) => $this->compare($latest, $current, $new, $old));
                });
            });
    }

    /** @param array<string, string> $new path => blob @param array<string, string>|null $old */
    private function compare(string $latest, ?string $current, array $new, ?array $old): Deferred
    {
        $paths = array_values(array_unique([...array_keys($new), ...array_keys($old ?? [])]));
        return $this->hashes($paths)->then(function (array $mine) use ($latest, $current, $new, $old) {
            $write = $delete = $changed = [];
            foreach ($new as $path => $blob) {
                if (($mine[$path] ?? null) === $blob) {
                    continue;                      // already the newest
                }
                $write[] = $path;
                $base = $old[$path] ?? null;
                if (isset($mine[$path]) && ($old === null || $mine[$path] !== $base)) {
                    $changed[] = $path;            // the user's version differs from the one JumPHP gave
                }
            }
            foreach ($old ?? [] as $path => $blob) {
                if (!isset($new[$path]) && isset($mine[$path])) {
                    $delete[] = $path;             // removed from JumPHP
                    if ($mine[$path] !== $blob) {
                        $changed[] = $path;
                    }
                }
            }
            return ['upToDate' => !$write && !$delete, 'latest' => $latest, 'current' => $current, 'files' => $write, 'deleted' => $delete,
                'changed' => $changed, 'known' => $old !== null];
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
                return $plan + ['updated' => []];
            }
            $skip = $force ? [] : array_flip($plan['changed']);
            $write = array_values(array_filter($plan['files'], fn ($p) => !isset($skip[$p])));
            $delete = array_values(array_filter($plan['deleted'], fn ($p) => !isset($skip[$p])));
            $done = $write ? $this->restore($plan['latest'], $write) : Deferred::resolved(null);
            return $done->then(function () use ($plan, $write, $delete, $skip) {
                foreach ($delete as $path) {
                    @unlink("$this->root/$path");
                }
                foreach ($write as $path) {
                    if (str_starts_with($path, 'bin/') || str_ends_with($path, '.sh')) {
                        @chmod("$this->root/$path", 0755);
                    }
                }
                if (!$skip) {
                    $this->record($plan['latest']);
                }
                return $plan + ['updated' => $write, 'removed' => $delete, 'kept' => array_keys($skip)];
            });
        });
    }

    /** Writes files of a commit into the working tree only (the index, the user's staging, is left alone). */
    private function restore(string $commit, array $paths): Deferred
    {
        $chain = Deferred::resolved(null);
        foreach (array_chunk($paths, 200) as $chunk) {
            $chain = $chain->then(fn () => $this->git(['restore', '--source=' . $commit, '--worktree', '--', ...$chunk]));
        }
        return $chain;
    }

    private function record(string $commit): void
    {
        @mkdir("$this->root/.jumphp", 0777, true);
        file_put_contents("$this->root/" . self::RECORD, json_encode(['repository' => $this->repository, 'commit' => $commit,
            'updated' => gmdate('c')], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n");
    }

    /** the files of a commit that are JumPHP's (path => blob id) */
    private function tree(string $commit): Deferred
    {
        return $this->git(['ls-tree', '-r', '--full-tree', $commit])->then(function (array $r) {
            $files = [];
            foreach (explode("\n", $r['stdout']) as $line) {
                if (!preg_match('/^(\d+) blob ([0-9a-f]+)\t(.+)$/', $line, $m) || $m[1] === '120000') {
                    continue;
                }
                $path = $m[3];
                if (in_array(explode('/', $path)[0], self::OWN, true)) {
                    continue;
                }
                $files[$path] = $m[2];
            }
            return $files;
        });
    }

    /** blob ids of the project's files that exist (git hash-object) */
    private function hashes(array $paths): Deferred
    {
        $present = array_values(array_filter($paths, fn ($p) => is_file("$this->root/$p")));
        if (!$present) {
            return Deferred::resolved([]);
        }
        return $this->git(['hash-object', '--stdin-paths'], implode("\n", $present) . "\n")->then(function (array $r) use ($present) {
            $ids = preg_split('/\s+/', trim($r['stdout'])) ?: [];
            return count($ids) === count($present) ? array_combine($present, $ids) : [];
        });
    }

    private function git(array $args, ?string $input = null): Deferred
    {
        return $this->processes->start(['git', ...$args], $this->root, [], null, $input)['done']->then(function (array $r) use ($args) {
            if ($r['code'] !== 0) {
                throw new \RuntimeException('git ' . $args[0] . ': ' . trim($r['stderr'] ?: $r['stdout']));
            }
            return $r;
        });
    }
}
