<?php

namespace JumPHP\Ide\Async;

/**
 * Programs run by the IDE (git, bin/jumphp, phpunit...) without blocking: started at once, their output read
 * a little at every poll() (the desktop app polls once per frame), their end settles a Deferred.
 */
final class Processes
{
    private int $next = 1;

    /** @var array<int, array<string, mixed>> */
    private array $running = [];

    /**
     * @param list<string> $command
     * @param array<string, string> $env added to the IDE's own environment
     * @param callable(string $chunk, string $stream): void|null $onOutput
     * @return array{id: int, done: Deferred} done: {code, stdout, stderr, output}
     */
    public function start(array $command, string $cwd, array $env = [], ?callable $onOutput = null, ?string $input = null): array
    {
        $id = $this->next++;
        $done = new Deferred();
        $fullEnv = array_merge(Environment::variables(), ['GIT_TERMINAL_PROMPT' => '0', 'LC_ALL' => 'C.UTF-8'], $env);
        $program = (string)($command[0] ?? '');
        // a program name is looked for in the full PATH (proc_open would only search the IDE's own)
        if ($program !== '' && !str_contains($program, '/') && ($found = Environment::which($program)) !== null) {
            $command[0] = $found;
        }
        $pipes = [];
        $process = @proc_open($command, [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, $cwd, $fullEnv);
        if (!is_resource($process)) {
            $done->reject(new \RuntimeException("cannot start $program" . (str_contains($program, '/') ? '' : ' (not found in the PATH: ' . Environment::path() . ')')));
            return ['id' => $id, 'done' => $done];
        }
        if ($input !== null) {
            fwrite($pipes[0], $input);
        }
        fclose($pipes[0]);
        stream_set_blocking($pipes[1], false);
        stream_set_blocking($pipes[2], false);
        $this->running[$id] = ['process' => $process, 'pipes' => [1 => $pipes[1], 2 => $pipes[2]], 'stdout' => '', 'stderr' => '',
            'output' => '', 'done' => $done, 'onOutput' => $onOutput, 'command' => $command, 'started' => microtime(true)];
        return ['id' => $id, 'done' => $done];
    }

    /** Reads what the programs wrote and settles those that ended. */
    public function poll(): void
    {
        foreach ($this->running as $id => &$p) {
            foreach ($p['pipes'] as $n => $pipe) {
                while (is_resource($pipe) && ($chunk = fread($pipe, 65536)) !== false && $chunk !== '') {
                    $p[$n === 1 ? 'stdout' : 'stderr'] .= $chunk;
                    $p['output'] .= $chunk;
                    if ($p['onOutput']) {
                        ($p['onOutput'])($chunk, $n === 1 ? 'stdout' : 'stderr');
                    }
                }
            }
            $status = proc_get_status($p['process']);
            if (!$status['running']) {
                foreach ($p['pipes'] as $n => $pipe) {
                    $rest = (string)stream_get_contents($pipe);
                    if ($rest !== '') {
                        $p[$n === 1 ? 'stdout' : 'stderr'] .= $rest;
                        $p['output'] .= $rest;
                        if ($p['onOutput']) {
                            ($p['onOutput'])($rest, $n === 1 ? 'stdout' : 'stderr');
                        }
                    }
                    fclose($pipe);
                }
                $code = $status['exitcode'];
                proc_close($p['process']);
                $result = ['code' => $code, 'stdout' => $p['stdout'], 'stderr' => $p['stderr'], 'output' => $p['output'],
                    'seconds' => round(microtime(true) - $p['started'], 2)];
                $done = $p['done'];
                unset($this->running[$id]);
                $done->resolve($result);
            }
        }
        unset($p);
    }

    /** Stops a program (SIGTERM, then the caller may kill it again: SIGKILL). */
    public function stop(int $id, bool $force = false): bool
    {
        if (!isset($this->running[$id])) {
            return false;
        }
        $status = proc_get_status($this->running[$id]['process']);
        if (function_exists('posix_kill') && $status['pid'] > 0) {
            // the whole group: bin/jumphp starts docker, which starts PHP
            @posix_kill(-$status['pid'], $force ? 9 : 15);
        }
        return proc_terminate($this->running[$id]['process'], $force ? 9 : 15);
    }

    public function isRunning(int $id): bool
    {
        return isset($this->running[$id]);
    }

    public function count(): int
    {
        return count($this->running);
    }

    /** Runs to the end, polling (tests and command-line tools only: it blocks). */
    public function wait(Deferred $done, float $timeout = 60.0): mixed
    {
        $end = microtime(true) + $timeout;
        while (!$done->isDone() && microtime(true) < $end) {
            $this->poll();
            // @phpstan-ignore booleanNot.alwaysTrue (poll() settles it)
            if (!$done->isDone()) {
                usleep(2000);
            }
        }
        return $done->result();
    }
}
