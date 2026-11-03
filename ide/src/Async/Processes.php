<?php

namespace JumPHP\Ide\Async;

/**
 * Programs run by the IDE (git, bin/jumphp, phpunit...) without blocking: started at once, their output read
 * a little at every poll() (the desktop app polls once per frame), their end settles a Deferred.
 *
 * Windows has no non-blocking pipes: PHP's read of an empty pipe waits up to ~32 s (PeekNamedPipe in a loop), so
 * reading stdout while the program fills stderr froze the IDE for good (git clone --progress: a .git folder and a
 * window that no longer answers). There, the program writes its stdout / stderr into temporary files that poll()
 * reads from where it stopped (Symfony Process does the same: WindowsPipes); stdin stays a pipe (writes only).
 */
final class Processes
{
    private int $next = 1;

    /** @var array<int, array<string, mixed>> */
    private array $running = [];

    private bool $files;

    public function __construct(?bool $files = null)
    {
        $this->files = $files ?? PHP_OS_FAMILY === 'Windows';
    }

    /**
     * @param list<string> $command
     * @param array<string, string> $env added to the IDE's own environment
     * @param callable(string $chunk, string $stream): void|null $onOutput
     * @param bool $keepInput leave its stdin open after $input: write() sends more, closeInput() ends it
     * @return array{id: int, done: Deferred} done: {code, stdout, stderr, output}
     */
    public function start(array $command, string $cwd, array $env = [], ?callable $onOutput = null, ?string $input = null, bool $keepInput = false): array
    {
        $id = $this->next++;
        $done = new Deferred();
        $fullEnv = array_merge(Environment::variables(), ['GIT_TERMINAL_PROMPT' => '0', 'LC_ALL' => 'C.UTF-8'], $env);
        $program = (string)($command[0] ?? '');
        // a program name is looked for in the full PATH (proc_open would only search the IDE's own)
        if ($program !== '' && !str_contains($program, '/') && !str_contains($program, '\\') && ($found = Environment::which($program)) !== null) {
            $command[0] = $found;
        }
        $pipes = [];
        $spec = [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']];
        $temp = [];
        if ($this->files) {
            foreach ([1, 2] as $n) {
                $temp[$n] = (string)tempnam(sys_get_temp_dir(), 'jph');
                $spec[$n] = ['file', $temp[$n], 'w'];
            }
        }
        $process = @proc_open($command, $spec, $pipes, $cwd, $fullEnv);
        if (!is_resource($process)) {
            array_map('unlink', array_filter($temp, 'is_file'));
            $done->reject(new \RuntimeException("cannot start $program" . (str_contains($program, '/') ? '' : ' (not found in the PATH: ' . Environment::path() . ')')));
            return ['id' => $id, 'done' => $done];
        }
        if ($input !== null) {
            fwrite($pipes[0], $input);
        }
        if (!$keepInput) {
            fclose($pipes[0]);
        }
        if ($temp !== []) {
            $readers = [1 => fopen($temp[1], 'rb'), 2 => fopen($temp[2], 'rb')];
        } else {
            stream_set_blocking($pipes[1], false);
            stream_set_blocking($pipes[2], false);
            $readers = [1 => $pipes[1], 2 => $pipes[2]];
        }
        $this->running[$id] = ['process' => $process, 'pipes' => $readers, 'temp' => $temp, 'read' => [1 => 0, 2 => 0],
            'stdin' => $keepInput ? $pipes[0] : null, 'stdout' => '', 'stderr' => '',
            'output' => '', 'done' => $done, 'onOutput' => $onOutput, 'command' => $command, 'started' => microtime(true)];
        return ['id' => $id, 'done' => $done];
    }

    /** Reads what the programs wrote and settles those that ended. */
    public function poll(): void
    {
        foreach ($this->running as $id => &$p) {
            $this->read($p);
            $status = proc_get_status($p['process']);
            if (!$status['running']) {
                if (is_resource($p['stdin'])) {
                    @fclose($p['stdin']);
                }
                $this->read($p, true);
                foreach ($p['pipes'] as $pipe) {
                    is_resource($pipe) && fclose($pipe);
                }
                foreach ($p['temp'] as $file) {
                    @unlink($file);
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

    /**
     * What a program wrote since the last time: from its pipes (non-blocking), or its temporary files (Windows),
     * from where the last read stopped. $end: it has ended, everything that is left.
     * @param array<string, mixed> $p
     */
    private function read(array &$p, bool $end = false): void
    {
        foreach ($p['pipes'] as $n => $pipe) {
            if (!is_resource($pipe)) {
                continue;
            }
            if ($p['temp'] !== []) {
                fseek($pipe, $p['read'][$n]);     // a file that grows: read on from where we stopped
            }
            while (true) {
                $chunk = $end && $p['temp'] === [] ? (string)stream_get_contents($pipe) : fread($pipe, 65536);
                if ($chunk === false || $chunk === '') {
                    break;
                }
                $p['read'][$n] += strlen($chunk);
                $p[$n === 1 ? 'stdout' : 'stderr'] .= $chunk;
                $p['output'] .= $chunk;
                if ($p['onOutput']) {
                    ($p['onOutput'])($chunk, $n === 1 ? 'stdout' : 'stderr');
                }
            }
        }
    }

    /** Stops a program (SIGTERM, then the caller may kill it again: SIGKILL). */
    public function stop(int $id, bool $force = false): bool
    {
        if (!isset($this->running[$id])) {
            return false;
        }
        $status = proc_get_status($this->running[$id]['process']);
        if (PHP_OS_FAMILY === 'Windows' && $status['pid'] > 0) {
            // the whole tree (bash -> docker): proc_terminate only ends the first one
            @exec('taskkill /T ' . ($force ? '/F ' : '') . '/PID ' . (int)$status['pid'] . ' 2>NUL');
            if (!$force) {
                @exec('taskkill /T /F /PID ' . (int)$status['pid'] . ' 2>NUL');   // console programs ignore the polite one
            }
        }
        if (function_exists('posix_kill') && $status['pid'] > 0) {
            // the whole group: bin/jumphp starts docker, which starts PHP
            @posix_kill(-$status['pid'], $force ? 9 : 15);
        }
        return proc_terminate($this->running[$id]['process'], $force ? 9 : 15);
    }

    /** More input for a program started with $keepInput (small writes: a line of JSON). False when its stdin is closed. */
    public function write(int $id, string $data): bool
    {
        $stdin = $this->running[$id]['stdin'] ?? null;
        if (!is_resource($stdin)) {
            return false;
        }
        while ($data !== '') {
            $written = @fwrite($stdin, $data);
            if ($written === false || $written === 0) {
                return false;
            }
            $data = substr($data, $written);
        }
        @fflush($stdin);
        return true;
    }

    /** Ends a program's input (it finishes what it does, then usually exits). */
    public function closeInput(int $id): void
    {
        $stdin = $this->running[$id]['stdin'] ?? null;
        if (is_resource($stdin)) {
            @fclose($stdin);
        }
        if (isset($this->running[$id])) {
            $this->running[$id]['stdin'] = null;
        }
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
