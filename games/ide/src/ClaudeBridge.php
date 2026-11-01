<?php

namespace App;

use JumPHP\Async\Task;
use JumPHP\Ide\Async\Deferred;
use JumPHP\Ide\Async\Processes;
use JumPHP\Ide\Claude\ClaudeCode;
use JumPHP\Ide\Git\Git;

/**
 * "Claude for jumphp.net": this computer's Claude Code answers the web IDE (jumphp.net/ide/<project>) of the same
 * user. The IDE asks jumphp.net for messages (GET /api/ide/claude/next, its jpi_ token: the sign-in authorized in
 * the browser), runs Claude on a clone of the project kept for it (~/.cache/jumphp/claude-bridge/<project>, reset to
 * the published branch, the user's drafts written on top), sends what Claude says as it comes, then the files it
 * changed in the game folder: they become drafts on jumphp.net. Nothing runs without a message of the signed-in
 * user; the user's own working copies are never touched.
 */
final class ClaudeBridge
{
    private bool $polling = false;

    /** @var array<string, mixed>|null the message being answered */
    private ?array $job = null;

    /** @var list<array<string, mixed>> events not sent yet */
    private array $outbox = [];

    private float $nextFlush = 0;
    private bool $flushing = false;
    private float $retryAt = 0;
    private ?int $runId = null;
    private string $state = 'off';

    public function __construct(private Main $main, private Playground $playground, private Processes $processes)
    {
    }

    public function enabled(): bool
    {
        return $this->state !== 'off';
    }

    /** @return array{on: bool, state: string, project: ?string} */
    public function status(): array
    {
        return ['on' => $this->enabled(), 'state' => $this->state, 'project' => $this->job['project'] ?? null];
    }

    public function enable(bool $on): array
    {
        $this->state = $on ? 'waiting' : 'off';
        if (!$on && $this->runId !== null) {
            $this->processes->stop($this->runId);
        }
        $this->main->send('bridge.status', $this->status());
        return $this->status();
    }

    /** Every frame: ask for work when idle, send what Claude said. */
    public function tick(): void
    {
        if ($this->state === 'off') {
            return;
        }
        if ($this->job === null && !$this->polling && microtime(true) >= $this->retryAt) {
            $this->poll();
        }
        if ($this->job !== null && $this->outbox && !$this->flushing && microtime(true) >= $this->nextFlush) {
            $this->flush();
        }
    }

    private function poll(): void
    {
        $this->polling = true;
        $computer = 'JumPHP IDE (' . PHP_OS_FAMILY . ')';
        self::deferred($this->playground->request('GET', '/api/ide/claude/next?wait=20&computer=' . rawurlencode($computer), null, 35.0))
            ->then(function (mixed $job) {
                $this->polling = false;
                if (is_array($job) && isset($job['id'])) {
                    $this->start($job);
                }
            }, function (\Throwable $e) {
                $this->polling = false;
                $this->retryAt = microtime(true) + 10;     // offline, signed out: try again a little later
                $this->main->send('bridge.status', $this->status() + ['error' => $e->getMessage()]);
            });
    }

    /** @param array<string, mixed> $job */
    private function start(array $job): void
    {
        $this->job = $job;
        $this->state = 'working';
        $this->outbox = [];
        $this->main->send('bridge.status', $this->status());
        $slug = (string)preg_replace('/[^a-z0-9-]/', '', (string)$job['project']);
        $cache = (getenv('XDG_CACHE_HOME') ?: ((getenv('HOME') ?: sys_get_temp_dir()) . '/.cache')) . '/jumphp/claude-bridge';
        $root = "$cache/$slug";
        $gameDir = trim((string)preg_replace('#\.\.+#', '', (string)($job['gameDir'] ?? '')), '/');
        @mkdir($cache, 0777, true);
        $git = new Git($this->processes, $root);
        self::deferred($this->playground->githubToken())->then(fn (array $r) => $git->setToken($r['token'] ?? null), fn () => null)
            ->then(function () use ($git, $root, $job) {
                $branch = (string)($job['branch'] ?? 'main');
                return is_dir("$root/.git")
                    ? $git->run(['fetch', '--depth', '50', 'origin', $branch], true)
                        ->then(fn () => $git->run(['reset', '--hard', 'FETCH_HEAD']))
                        ->then(fn () => $git->run(['clean', '-fd']))
                    : $git->cloneInto((string)$job['cloneUrl'], $root);
            })
            ->then(function () use ($root, $gameDir, $job) {
                $game = $gameDir !== '' ? "$root/$gameDir" : $root;
                // the drafts: what the user sees in the web IDE
                foreach ((array)($job['drafts'] ?? []) as $path => $content) {
                    $path = (string)$path;
                    if (str_contains($path, '..') || str_starts_with($path, '/')) {
                        continue;
                    }
                    if ($content === null) {
                        @unlink("$game/$path");
                    } else {
                        @mkdir(dirname("$game/$path"), 0777, true);
                        file_put_contents("$game/$path", (string)base64_decode((string)$content));
                    }
                }
                $before = self::snapshot($game);
                $claude = new ClaudeCode($this->processes, $root, function (string $event, array $payload) use ($root, $gameDir, $game, $before) {
                    if ($event === 'claude.event') {
                        $this->outbox[] = self::relative($payload['event'], $root, $gameDir);
                    } elseif ($event === 'claude.exit') {
                        $this->finish((int)$payload['code'], $payload['error'] ?? null, self::changes($game, $before));
                    }
                });
                $this->runId = $claude->send((string)$job['prompt'], $job['session'] ?? null, (string)($job['mode'] ?? 'acceptEdits'))['id'];
            })
            ->then(null, fn (\Throwable $e) => $this->finish(-1, 'Your computer could not prepare the project: ' . $e->getMessage(), []));
    }

    private function flush(): void
    {
        if ($this->job === null) {
            return;
        }
        $events = array_splice($this->outbox, 0, 100);
        $this->flushing = true;
        self::deferred($this->playground->request('POST', '/api/ide/claude/' . $this->job['id'] . '/events', ['events' => $events]))
            ->then(function (mixed $r) {
                $this->flushing = false;
                $this->nextFlush = microtime(true) + 0.4;
                if (is_array($r) && !empty($r['stop']) && $this->runId !== null) {
                    $this->processes->stop($this->runId);
                }
            }, function () use ($events) {
                $this->flushing = false;
                $this->nextFlush = microtime(true) + 2;
                array_unshift($this->outbox, ...$events);
            });
    }

    /** @param array<string, string|null> $files */
    private function finish(int $code, ?string $error, array $files): void
    {
        $job = $this->job;
        if ($job === null) {
            return;
        }
        $events = $this->outbox;
        $this->outbox = [];
        $this->runId = null;
        $send = $events
            ? self::deferred($this->playground->request('POST', '/api/ide/claude/' . $job['id'] . '/events', ['events' => $events]))
            : Deferred::resolved(null);
        $send->then(fn () => self::deferred($this->playground->request('POST', '/api/ide/claude/' . $job['id'] . '/done', ['code' => $code, 'error' => $error, 'files' => $files], 60.0)))
            ->then(null, fn () => null)
            ->then(function () {
                $this->job = null;
                if ($this->state !== 'off') {
                    $this->state = 'waiting';
                }
                $this->main->send('bridge.status', $this->status());
            });
    }

    /** @return array<string, string> path => sha1 of the game's files (not vendor/, var/, dist/) */
    private static function snapshot(string $game): array
    {
        $out = [];
        if (!is_dir($game)) {
            return $out;
        }
        $it = new \RecursiveIteratorIterator(new \RecursiveCallbackFilterIterator(
            new \RecursiveDirectoryIterator($game, \FilesystemIterator::SKIP_DOTS),
            fn (\SplFileInfo $f) => !in_array($f->getFilename(), ['vendor', 'var', 'dist', '.git', 'node_modules'], true),
        ));
        foreach ($it as $file) {
            if ($file->isFile() && $file->getSize() <= 2_000_000 && count($out) < 5000) {
                $out[substr($file->getPathname(), strlen($game) + 1)] = (string)sha1_file($file->getPathname());
            }
        }
        return $out;
    }

    /**
     * @param array<string, string> $before
     * @return array<string, string|null> what Claude changed: path => base64 content, null = deleted
     */
    private static function changes(string $game, array $before): array
    {
        $after = self::snapshot($game);
        $files = [];
        foreach ($after as $path => $hash) {
            if (($before[$path] ?? null) !== $hash) {
                $files[$path] = base64_encode((string)file_get_contents("$game/$path"));
            }
        }
        foreach (array_diff_key($before, $after) as $path => $hash) {
            $files[$path] = null;
        }
        return array_slice($files, 0, 200, true);
    }

    /**
     * The event as the web IDE shows it: paths in the game folder, not this computer's.
     *
     * @param array<string, mixed> $event
     * @return array<string, mixed>
     */
    private static function relative(array $event, string $root, string $gameDir): array
    {
        $json = (string)json_encode($event, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE);
        $json = str_replace([$gameDir !== '' ? "$root/$gameDir/" : "$root/", "$root/"], ['', ''], $json);
        $decoded = json_decode($json, true);
        return is_array($decoded) ? $decoded : $event;
    }

    private static function deferred(Task $task): Deferred
    {
        $d = new Deferred();
        $task->then(fn ($v) => $d->resolve($v), fn (\Throwable $e) => $d->reject($e));
        return $d;
    }
}
