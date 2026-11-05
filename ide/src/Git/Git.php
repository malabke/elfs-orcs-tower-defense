<?php

namespace JumPHP\Ide\Git;

use JumPHP\Ide\Async\Deferred;
use JumPHP\Ide\Async\Processes;

/**
 * Git on a local clone, through the git program (never blocking: every call returns a Deferred). A token, when
 * given, authenticates https remotes on GitHub for push / pull / fetch / clone; it goes through git's
 * environment (GIT_CONFIG_*), never on the command line nor in the repository's config.
 */
final class Git
{
    /** @var array{name: string, email: string}|null author used when git has none configured */
    private ?array $fallbackAuthor = null;

    public function __construct(private Processes $processes, private string $root, private ?string $token = null, private string $binary = 'git')
    {
    }

    public function setToken(?string $token): void
    {
        $this->token = $token;
    }

    /** @var array<string, array{0: string, 1: string}> host => [user, token or password]: GitHub, GitLab, a company's server... over https */
    private array $credentials = [];

    /** @param array<string, array{0: string, 1: string}> $credentials */
    public function setCredentials(array $credentials): void
    {
        $this->credentials = $credentials;
    }

    public function token(): ?string
    {
        return $this->token;
    }

    /** @param array{name: string, email: string}|null $author */
    public function setFallbackAuthor(?array $author): void
    {
        $this->fallbackAuthor = $author;
    }

    /**
     * Runs git with arguments; resolves with its output (stdout), rejects with its error message.
     *
     * @param list<string> $args
     */
    public function run(array $args, bool $network = false, ?callable $onOutput = null, ?string $cwd = null, ?string $input = null): Deferred
    {
        $env = ['GIT_TERMINAL_PROMPT' => '0', 'GIT_OPTIONAL_LOCKS' => '0'];
        $config = [['core.quotepath', 'false'], ['color.ui', 'false']];
        if ($network && $this->token !== null && $this->token !== '' && !isset($this->credentials['github.com'])) {
            $config[] = ['http.https://github.com/.extraHeader', 'Authorization: Basic ' . base64_encode('x-access-token:' . $this->token)];
        }
        if ($network) {
            // the credentials the user gave for a host win over the jumphp.net sign-in (which is GitHub's only)
            foreach ($this->credentials as $host => [$user, $secret]) {
                if (preg_match('/^[a-z0-9.-]+(:\d+)?$/i', (string)$host) && $secret !== '') {
                    $config[] = ["http.https://$host/.extraHeader", 'Authorization: Basic ' . base64_encode($user . ':' . $secret)];
                }
            }
        }
        $env['GIT_CONFIG_COUNT'] = (string)count($config);
        foreach ($config as $i => [$key, $value]) {
            $env["GIT_CONFIG_KEY_$i"] = $key;
            $env["GIT_CONFIG_VALUE_$i"] = $value;
        }
        $started = $this->processes->start([$this->binary, ...$args], $cwd ?? $this->root, $env, $onOutput, $input);
        return $started['done']->then(function (array $result) use ($args) {
            if ($result['code'] !== 0) {
                $message = trim($result['stderr']) ?: trim($result['stdout']) ?: 'git ' . ($args[0] ?? '') . ' failed';
                throw new GitException($this->clean($message) . self::authHint($message), $result['code']);
            }
            return $result['stdout'];
        });
    }

    /** What to do when git could not sign in: said once, after git's own message. */
    private static function authHint(string $message): string
    {
        return preg_match('/Authentication failed|could not read (Username|Password)|terminal prompts disabled|Permission denied \(publickey\)|returned error: 40[13]|HTTP 40[13]|Invalid username or token/i', $message)
            ? "\n\nGit could not sign in. Over https: Git > Credentials… gives this host a user and a token (the jumphp.net sign-in covers GitHub only); "
              . 'with two GitHub accounts, the account menu picks the one of this project. Over ssh: the IDE uses your ssh agent and keys.'
            : '';
    }

    private function clean(string $message): string
    {
        return preg_replace('/x-access-token:[^@\s]+@/', '', $message) ?? $message;
    }

    /** Whether the folder is a git repository (its own .git). */
    public function isRepository(): bool
    {
        return is_dir($this->root . '/.git') || is_file($this->root . '/.git');
    }

    /**
     * Branch, upstream, ahead / behind, and every changed file.
     *
     * @return Deferred resolves with array{branch: ?string, upstream: ?string, ahead: int, behind: int, files: list<array{path: string, from: ?string, index: string, worktree: string, status: string, staged: bool, unstaged: bool, conflict: bool}>}
     */
    public function status(): Deferred
    {
        return $this->run(['status', '--porcelain=v2', '--branch', '-z', '--untracked-files=all'])
            ->then(fn (string $out) => self::parseStatus($out) + ['merging' => $this->mergingBranch()]);
    }

    /** The branch being merged when a merge stopped on conflicts ("" when its name is unknown), else null. */
    public function mergingBranch(): ?string
    {
        $head = $this->gitPath('MERGE_HEAD');
        if ($head === null || !is_file($head)) {
            return null;
        }
        $message = (string)@file_get_contents((string)$this->gitPath('MERGE_MSG'));
        return preg_match("/^Merge (?:remote-tracking )?branch '([^']+)'/", $message, $m) ? $m[1] : '';
    }

    /** A file of the repository's .git folder (a worktree's .git file points at it), null when it has none. */
    private function gitPath(string $name): ?string
    {
        $dot = $this->root . '/.git';
        if (is_dir($dot)) {
            return "$dot/$name";
        }
        if (is_file($dot) && preg_match('/^gitdir:\s*(.+)$/m', (string)file_get_contents($dot), $m)) {
            $dir = trim($m[1]);
            return (preg_match('#^(/|[A-Za-z]:)#', $dir) ? $dir : $this->root . '/' . $dir) . '/' . $name;
        }
        return null;
    }

    /** @return array<string, mixed> */
    public static function parseStatus(string $out): array
    {
        $status = ['branch' => null, 'upstream' => null, 'ahead' => 0, 'behind' => 0, 'files' => []];
        $records = explode("\0", $out);
        for ($i = 0; $i < count($records); $i++) {
            $r = $records[$i];
            if ($r === '') {
                continue;
            }
            if (str_starts_with($r, '# branch.head ')) {
                $head = substr($r, 14);
                $status['branch'] = $head === '(detached)' ? null : $head;
            } elseif (str_starts_with($r, '# branch.upstream ')) {
                $status['upstream'] = substr($r, 18);
            } elseif (preg_match('/^# branch\.ab \+(\d+) -(\d+)/', $r, $m)) {
                $status['ahead'] = (int)$m[1];
                $status['behind'] = (int)$m[2];
            } elseif ($r[0] === '1' || $r[0] === '2') {
                $parts = explode(' ', $r, $r[0] === '1' ? 9 : 10);
                $xy = $parts[1];
                $path = $parts[$r[0] === '1' ? 8 : 9];
                $from = $r[0] === '2' ? ($records[++$i] ?? null) : null;
                $status['files'][] = self::file($path, $from, $xy[0], $xy[1]);
            } elseif ($r[0] === 'u') {
                $parts = explode(' ', $r, 11);
                $status['files'][] = self::file($parts[10], null, 'U', 'U', true);
            } elseif ($r[0] === '?') {
                $status['files'][] = self::file(substr($r, 2), null, '?', '?');
            }
        }
        return $status;
    }

    /** @return array<string, mixed> */
    private static function file(string $path, ?string $from, string $index, string $worktree, bool $conflict = false): array
    {
        $code = $conflict ? 'U' : ($index === '?' ? '?' : ($worktree !== '.' ? $worktree : $index));
        $status = match ($code) {
            'M', 'T' => 'modified', 'A' => 'added', 'D' => 'deleted', 'R', 'C' => 'renamed', '?' => 'untracked', 'U' => 'conflict', default => 'modified',
        };
        return ['path' => $path, 'from' => $from, 'index' => $index, 'worktree' => $worktree, 'status' => $status,
            'staged' => $index !== '.' && $index !== '?' && !$conflict, 'unstaged' => $worktree !== '.' || $index === '?', 'conflict' => $conflict];
    }

    /** The file's content at a revision (default HEAD); "" when it did not exist there. */
    public function show(string $path, string $revision = 'HEAD'): Deferred
    {
        // revision ':1' / ':2' / ':3': the base, ours and theirs of a conflicted file (git show :2:<path>)
        return $this->run(['show', $revision . ':' . $path])->then(null, fn (\Throwable $e) => '');
    }

    /** @param list<string> $paths */
    public function stage(array $paths): Deferred
    {
        return $this->run(['add', '--all', '--', ...$paths]);
    }

    /** @param list<string> $paths */
    public function unstage(array $paths): Deferred
    {
        return $this->run(['reset', '-q', 'HEAD', '--', ...$paths])->then(null, fn () => $this->run(['rm', '--cached', '-q', '--', ...$paths]));
    }

    /**
     * Throws away the changes of files: back to HEAD; new (untracked) files are deleted.
     *
     * @param list<string> $paths
     */
    public function rollback(array $paths): Deferred
    {
        return $this->status()->then(function (array $status) use ($paths) {
            $tracked = [];
            foreach ($status['files'] as $file) {
                if (!in_array($file['path'], $paths, true)) {
                    continue;
                }
                if ($file['status'] === 'untracked' || ($file['index'] === 'A' && $file['worktree'] !== 'D')) {
                    if ($file['index'] === 'A') {
                        $this->run(['rm', '--cached', '-q', '--', $file['path']]);
                    }
                    @unlink($this->root . '/' . $file['path']);
                } else {
                    $tracked[] = $file['path'];
                }
            }
            return $tracked ? $this->run(['checkout', 'HEAD', '--', ...$tracked]) : '';
        });
    }

    /**
     * Commits the given files (all their changes) or what is staged (null).
     *
     * @param list<string>|null $paths
     */
    public function commit(string $message, ?array $paths = null, bool $amend = false): Deferred
    {
        if (trim($message) === '') {
            return Deferred::rejected(new GitException('empty commit message'));
        }
        $add = $paths ? $this->run(['add', '--all', '--', ...$paths]) : Deferred::resolved('');
        return $add->then(fn () => $this->author())->then(function (array $author) use ($message, $paths, $amend) {
            $args = [];
            if ($author) {
                $args = ['-c', 'user.name=' . $author['name'], '-c', 'user.email=' . $author['email']];
            }
            $args = [...$args, 'commit', '-F', '-'];
            if ($amend) {
                $args[] = '--amend';
            }
            if ($paths) {
                $args[] = '--';
                array_push($args, ...$paths);
            }
            return $this->run($args, false, null, null, $message);
        })->then(fn () => $this->run(['rev-parse', 'HEAD']))->then(fn (string $sha) => trim($sha));
    }

    /** The fallback author when git has none (user.name / user.email unset). @return Deferred resolves with array */
    private function author(): Deferred
    {
        if ($this->fallbackAuthor === null) {
            return Deferred::resolved([]);
        }
        return $this->run(['config', 'user.email'])->then(fn () => [], fn () => $this->fallbackAuthor);
    }

    public function push(?callable $onOutput = null, bool $setUpstream = true): Deferred
    {
        return $this->status()->then(function (array $status) use ($onOutput, $setUpstream) {
            $args = ['push', '--porcelain', '--progress'];
            if ($status['upstream'] === null && $setUpstream && $status['branch'] !== null) {
                array_push($args, '-u', 'origin', $status['branch']);
            }
            return $this->run($args, true, $onOutput);
        });
    }

    public function pull(?callable $onOutput = null, bool $rebase = false): Deferred
    {
        return $this->run(['pull', '--progress', $rebase ? '--rebase' : '--no-rebase'], true, $onOutput);
    }

    public function fetch(?callable $onOutput = null): Deferred
    {
        return $this->run(['fetch', '--all', '--prune', '--progress'], true, $onOutput);
    }

    /** @return Deferred resolves with list<array{name: string, remote: bool, current: bool, upstream: string, sha: string, subject: string}> */
    public function branches(): Deferred
    {
        return $this->run(['branch', '-a', '--format=%(HEAD)%1f%(refname)%1f%(upstream:short)%1f%(objectname:short)%1f%(contents:subject)'])
            ->then(function (string $out) {
                $branches = [];
                foreach (array_filter(explode("\n", $out)) as $line) {
                    [$head, $ref, $upstream, $sha, $subject] = explode("\x1f", $line) + ['', '', '', '', ''];
                    if (str_ends_with($ref, '/HEAD')) {
                        continue;
                    }
                    $remote = str_starts_with($ref, 'refs/remotes/');
                    $branches[] = ['name' => preg_replace('#^refs/(heads|remotes)/#', '', $ref), 'remote' => $remote,
                        'current' => $head === '*', 'upstream' => $upstream, 'sha' => $sha, 'subject' => $subject];
                }
                return $branches;
            });
    }

    /** Switches to a branch (a remote one gets its local branch), or creates it from HEAD. */
    public function checkout(string $branch, bool $create = false): Deferred
    {
        if ($create) {
            return $this->run(['checkout', '-b', $branch]);
        }
        if (str_starts_with($branch, 'origin/')) {
            return $this->run(['checkout', '--track', $branch])->then(null, fn () => $this->run(['checkout', substr($branch, 7)]));
        }
        return $this->run(['checkout', $branch]);
    }

    public function deleteBranch(string $branch, bool $force = false): Deferred
    {
        return $this->run(['branch', $force ? '-D' : '-d', $branch]);
    }

    /**
     * Merges a branch into the current one. Conflicts are not an error: the merge waits (resolve(), then
     * commitMerge(), or abortMerge()).
     *
     * @return Deferred resolves with array{conflicts: list<string>}
     */
    public function merge(string $branch): Deferred
    {
        return $this->author()->then(fn (array $author) => $this->run([...self::identity($author), 'merge', '--no-edit', $branch]))
            ->then(fn () => ['conflicts' => []], fn (\Throwable $e) => $this->status()->then(function (array $status) use ($e) {
                $conflicts = array_column(array_filter($status['files'], fn (array $f) => $f['conflict']), 'path');
                if (!$conflicts) {
                    throw $e;
                }
                return ['conflicts' => $conflicts];
            }));
    }

    /**
     * Resolves a conflicted file: "ours" (the current branch's version), "theirs" (the merged branch's), "both"
     * (ours then theirs), or "mark" (the file as it is now, edited by hand: refused while it has conflict markers).
     */
    public function resolve(string $path, string $how): Deferred
    {
        $add = fn () => $this->run(['add', '--', $path]);
        return match ($how) {
            'ours', 'theirs' => $this->run(['checkout', '--' . $how, '--', $path])->then($add, fn () => $this->run(['rm', '-q', '--', $path])),
            'both' => $this->show($path, ':2')->then(fn (string $ours) => $this->show($path, ':3')->then(function (string $theirs) use ($path, $ours, $add) {
                file_put_contents($this->root . '/' . $path, $ours . ($ours !== '' && !str_ends_with($ours, "\n") ? "\n" : '') . $theirs);
                return $add();
            })),
            'mark' => preg_match('/^(<{7}|>{7})( |$)/m', (string)@file_get_contents($this->root . '/' . $path))
                ? Deferred::rejected(new GitException("$path still has conflict markers (<<<<<<< / >>>>>>>): edit them away first"))
                : $add(),
            default => Deferred::rejected(new GitException("unknown way to resolve: $how")),
        };
    }

    /** @return Deferred resolves with array{base: string, ours: string, theirs: string} (the three versions of a conflicted file) */
    public function conflictVersions(string $path): Deferred
    {
        return $this->show($path, ':1')->then(fn (string $base) => $this->show($path, ':2')
            ->then(fn (string $ours) => $this->show($path, ':3')->then(fn (string $theirs) => ['base' => $base, 'ours' => $ours, 'theirs' => $theirs])));
    }

    /** Commits the merge once every conflict is resolved (the message git prepared). */
    public function commitMerge(): Deferred
    {
        return $this->status()->then(function (array $status) {
            if ($status['merging'] === null) {
                throw new GitException('no merge in progress');
            }
            if (array_filter($status['files'], fn (array $f) => $f['conflict'])) {
                throw new GitException('some files still have conflicts: resolve them first');
            }
            return $this->author()->then(fn (array $author) => $this->run([...self::identity($author), 'commit', '--no-edit']));
        })->then(fn () => $this->run(['rev-parse', 'HEAD']))->then(fn (string $sha) => trim($sha));
    }

    /** Gives up the merge in progress: the branch as it was before it. */
    public function abortMerge(): Deferred
    {
        return $this->run(['merge', '--abort']);
    }

    /** Whether a local branch is merged into the current one (deleting it loses nothing). */
    public function isMerged(string $branch): Deferred
    {
        return $this->run(['branch', '--merged', 'HEAD', '--format=%(refname:short)'])
            ->then(fn (string $out) => in_array($branch, array_map('trim', explode("\n", $out)), true));
    }

    /** @param array<string, string> $author @return list<string> */
    private static function identity(array $author): array
    {
        return $author ? ['-c', 'user.name=' . $author['name'], '-c', 'user.email=' . $author['email']] : [];
    }

    /** @return Deferred resolves with list<array{sha: string, short: string, author: string, email: string, time: int, subject: string, refs: string, parents: list<string>}> */
    public function log(?string $path = null, int $limit = 200, ?string $revision = null): Deferred
    {
        $args = ['log', '--format=%H%x1f%h%x1f%an%x1f%ae%x1f%at%x1f%s%x1f%D%x1f%P%x1e', '-n', (string)$limit];
        if ($revision !== null) {
            $args[] = $revision;
        } else {
            $args[] = '--all';
        }
        if ($path !== null) {
            array_push($args, '--follow', '--', $path);
        }
        return $this->run($args)->then(function (string $out) {
            $commits = [];
            foreach (explode("\x1e", $out) as $record) {
                $record = trim($record);
                if ($record === '') {
                    continue;
                }
                [$sha, $short, $author, $email, $time, $subject, $refs, $parents] = explode("\x1f", $record) + array_fill(0, 8, '');
                $commits[] = ['sha' => $sha, 'short' => $short, 'author' => $author, 'email' => $email, 'time' => (int)$time,
                    'subject' => $subject, 'refs' => $refs, 'parents' => array_values(array_filter(explode(' ', $parents)))];
            }
            return $commits;
        })->then(null, fn (\Throwable $e) => str_contains($e->getMessage(), 'does not have any commits') ? [] : throw $e);
    }

    /** The files a commit changed. @return Deferred resolves with list<array{status: string, path: string, from: ?string}> */
    public function commitFiles(string $sha): Deferred
    {
        return $this->run(['show', '--format=', '--name-status', '-z', '-M', $sha])->then(function (string $out) {
            $parts = explode("\0", $out);
            $files = [];
            for ($i = 0; $i < count($parts); $i++) {
                $code = trim($parts[$i]);
                if ($code === '') {
                    continue;
                }
                if ($code[0] === 'R' || $code[0] === 'C') {
                    $files[] = ['status' => $code[0], 'from' => $parts[++$i] ?? '', 'path' => $parts[++$i] ?? ''];
                } else {
                    $files[] = ['status' => $code[0], 'from' => null, 'path' => $parts[++$i] ?? ''];
                }
            }
            return $files;
        });
    }

    /** @return Deferred resolves with list<array{line: int, sha: string, author: string, time: int, summary: string}> one entry per line */
    public function blame(string $path, ?string $content = null): Deferred
    {
        $args = ['blame', '--porcelain'];
        if ($content !== null) {
            $args[] = '--contents';
            $args[] = '-';
        }
        $args[] = '--';
        $args[] = $path;
        return $this->run($args, false, null, null, $content)->then(function (string $out) {
            $lines = [];
            $commits = [];
            $current = null;
            foreach (explode("\n", $out) as $row) {
                if (preg_match('/^([0-9a-f]{40}) \d+ (\d+)/', $row, $m)) {
                    $current = ['sha' => $m[1], 'line' => (int)$m[2]];
                    $commits[$m[1]] ??= ['author' => '', 'time' => 0, 'summary' => ''];
                } elseif ($current !== null && str_starts_with($row, 'author ')) {
                    $commits[$current['sha']]['author'] = substr($row, 7);
                } elseif ($current !== null && str_starts_with($row, 'author-time ')) {
                    $commits[$current['sha']]['time'] = (int)substr($row, 12);
                } elseif ($current !== null && str_starts_with($row, 'summary ')) {
                    $commits[$current['sha']]['summary'] = substr($row, 8);
                } elseif ($current !== null && str_starts_with($row, "\t")) {
                    $lines[] = $current + $commits[$current['sha']];
                    $current = null;
                }
            }
            return $lines;
        });
    }

    /** @return Deferred resolves with list<array{name: string, url: string}> */
    public function remotes(): Deferred
    {
        return $this->run(['remote', '-v'])->then(function (string $out) {
            $remotes = [];
            foreach (array_filter(explode("\n", $out)) as $line) {
                if (preg_match('/^(\S+)\s+(\S+)\s+\(fetch\)/', $line, $m)) {
                    $remotes[] = ['name' => $m[1], 'url' => preg_replace('#//[^@/]+@#', '//', $m[2])];
                }
            }
            return $remotes;
        });
    }

    /**
     * Pins the GitHub account of this repository (git config credential.https://github.com.username, in the repository only, no secret):
     * with two accounts signed in on one computer, the programs that run plain git here (the terminal, Claude, the credential manager of
     * Windows) then ask for THIS account instead of the one they remembered. Done when origin is on GitHub and belongs to $login.
     */
    public function pinAccount(string $login): Deferred
    {
        return $this->remotes()->then(function (array $remotes) use ($login) {
            foreach ($remotes as $r) {
                if ($r['name'] === 'origin' && preg_match('#github\.com[:/]([^/]+)/#i', $r['url'], $m) && strcasecmp($m[1], $login) === 0) {
                    return $this->run(['config', '--local', 'credential.https://github.com.username', $login])->then(fn () => true);
                }
            }
            return false;
        });
    }

    public function stash(?string $message = null): Deferred
    {
        return $this->run(['stash', 'push', '--include-untracked', ...($message ? ['-m', $message] : [])]);
    }

    public function stashPop(int $index = 0): Deferred
    {
        return $this->run(['stash', 'pop', "stash@{{$index}}"]);
    }

    /** @return Deferred resolves with list<array{index: int, message: string, time: int}> */
    public function stashes(): Deferred
    {
        return $this->run(['stash', 'list', '--format=%gd%x09%ct%x09%s'])->then(function (string $out) {
            $list = [];
            foreach (array_filter(explode("\n", $out)) as $line) {
                [$ref, $time, $subject] = array_pad(explode("\t", $line, 3), 3, '');
                if (preg_match('/\{(\d+)\}/', $ref, $m)) {
                    $list[] = ['index' => (int)$m[1], 'message' => $subject, 'time' => (int)$time];
                }
            }
            return $list;
        });
    }

    public function stashApply(int $index): Deferred
    {
        return $this->run(['stash', 'apply', "stash@{{$index}}"]);
    }

    public function stashDrop(int $index): Deferred
    {
        return $this->run(['stash', 'drop', "stash@{{$index}}"]);
    }

    // remotes ------------------------------------------------------------------------------------------------

    public function addRemote(string $name, string $url): Deferred
    {
        return self::validName($name) ? $this->run(['remote', 'add', $name, $url]) : Deferred::rejected(new GitException("bad remote name: $name"));
    }

    public function removeRemote(string $name): Deferred
    {
        return $this->run(['remote', 'remove', $name]);
    }

    public function setRemoteUrl(string $name, string $url): Deferred
    {
        return $this->run(['remote', 'set-url', $name, $url]);
    }

    // tags ---------------------------------------------------------------------------------------------------

    /** @return Deferred resolves with list<array{name: string, sha: string, subject: string, time: int}> newest first */
    public function tags(): Deferred
    {
        return $this->run(['for-each-ref', '--sort=-creatordate', '--format=%(refname:short)%09%(objectname:short)%09%(creatordate:unix)%09%(contents:subject)', 'refs/tags'])
            ->then(function (string $out) {
                $list = [];
                foreach (array_filter(explode("\n", $out)) as $line) {
                    [$name, $sha, $time, $subject] = array_pad(explode("\t", $line, 4), 4, '');
                    $list[] = ['name' => $name, 'sha' => $sha, 'time' => (int)$time, 'subject' => $subject];
                }
                return $list;
            });
    }

    /** A tag on $revision (default HEAD); with a message it is an annotated tag. */
    public function createTag(string $name, ?string $revision = null, ?string $message = null): Deferred
    {
        if (!self::validName($name)) {
            return Deferred::rejected(new GitException("bad tag name: $name"));
        }
        return $this->authorArgs()->then(fn (array $id) => $this->run([...$id, 'tag', ...($message !== null && $message !== '' ? ['-a', '-m', $message] : []), $name, ...($revision ? [$revision] : [])]));
    }

    public function deleteTag(string $name, bool $remote = false, string $remoteName = 'origin'): Deferred
    {
        return $remote ? $this->run(['push', $remoteName, '--delete', "refs/tags/$name"], true) : $this->run(['tag', '-d', $name]);
    }

    public function pushTag(string $name, string $remoteName = 'origin'): Deferred
    {
        return $this->run(['push', $remoteName, "refs/tags/$name"], true);
    }

    // commits ------------------------------------------------------------------------------------------------

    /** Moves the current branch to $sha: soft (changes stay staged), mixed (stay in the files), hard (thrown away). */
    public function resetTo(string $sha, string $mode = 'mixed'): Deferred
    {
        return in_array($mode, ['soft', 'mixed', 'hard'], true) && preg_match('/^[0-9a-f]{4,40}$/i', $sha)
            ? $this->run(['reset', "--$mode", $sha])
            : Deferred::rejected(new GitException('bad reset'));
    }

    /** A new commit that undoes $sha. */
    public function revert(string $sha): Deferred
    {
        return $this->authorArgs()->then(fn (array $id) => $this->run([...$id, 'revert', '--no-edit', $sha]))
            ->then(null, fn (\Throwable $e) => $this->run(['revert', '--abort'])->then(fn () => throw $e, fn () => throw $e));
    }

    /** $sha's changes as a new commit on the current branch. */
    public function cherryPick(string $sha): Deferred
    {
        return $this->authorArgs()->then(fn (array $id) => $this->run([...$id, 'cherry-pick', $sha]))
            ->then(null, fn (\Throwable $e) => $this->run(['cherry-pick', '--abort'])->then(fn () => throw $e, fn () => throw $e));
    }

    /** The last commit is taken back (its changes stay staged), to commit again. */
    public function undoLastCommit(): Deferred
    {
        return $this->run(['reset', '--soft', 'HEAD~1']);
    }

    // branches -----------------------------------------------------------------------------------------------

    public function renameBranch(string $old, string $new): Deferred
    {
        return self::validName($new) ? $this->run(['branch', '-m', $old, $new]) : Deferred::rejected(new GitException("bad branch name: $new"));
    }

    /** git push <remote> --delete <branch> */
    public function deleteRemoteBranch(string $branch, string $remoteName = 'origin', ?callable $onOutput = null): Deferred
    {
        return $this->run(['push', $remoteName, '--delete', $branch], true, $onOutput);
    }

    /** Push over a rewritten history (after a reset or an amend), refused if someone else pushed meanwhile. */
    public function pushForce(?callable $onOutput = null): Deferred
    {
        return $this->status()->then(fn (array $st) => $this->run(['push', '--force-with-lease', '--porcelain', '--progress', ...($st['upstream'] === null && $st['branch'] !== null ? ['-u', 'origin', $st['branch']] : [])], true, $onOutput));
    }

    /** -c user.name / user.email arguments when git has no author configured. @return Deferred resolves with list<string> */
    private function authorArgs(): Deferred
    {
        return $this->author()->then(fn (array $a) => self::identity($a));
    }

    private static function validName(string $name): bool
    {
        return (bool)preg_match('#^[A-Za-z0-9][A-Za-z0-9._/-]*$#', $name) && !str_contains($name, '..') && !str_ends_with($name, '.lock') && !str_ends_with($name, '/');
    }

    /** Clones a repository into a new folder (its parent must exist). */
    public function cloneInto(string $url, string $folder, ?callable $onOutput = null): Deferred
    {
        return $this->run(['clone', '--progress', $url, $folder], true, $onOutput, dirname($folder));
    }
}
