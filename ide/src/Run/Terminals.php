<?php

namespace JumPHP\Ide\Run;

use JumPHP\Ide\Async\Environment;
use JumPHP\Ide\Async\Processes;

/**
 * The Terminal tool window's shells, in the project's folder.
 *
 * Linux / macOS: the user's shell ($SHELL, else bash, else sh) in a pseudo-terminal (proc_open's "pty"): colours,
 * prompts, line editing, full-screen programs. Its size follows the window: `stty` on its terminal device (the kernel
 * then tells the programs, SIGWINCH). PHP without pty support: the shell on pipes, interactive (-i).
 * Windows: PHP has no pseudo-terminal there: cmd.exe (COMSPEC; JUMPHP_IDE_SHELL=powershell for PowerShell) on pipes,
 * through Processes (Windows' pipes block: files). The page then echoes and edits the line itself (pty: false).
 *
 * Output goes out as base64 (a chunk can end in the middle of a UTF-8 character; the page decodes it as a stream).
 */
final class Terminals
{
    private int $next = 1;

    /** @var array<int, array<string, mixed>> */
    private array $open = [];

    /** @param callable(string $event, array<string, mixed> $payload): void $emit */
    public function __construct(private readonly Processes $processes, private readonly string $root, private $emit)
    {
    }

    /** @return array{id: int, pty: bool, shell: string} */
    public function open(int $cols = 120, int $rows = 30, ?string $shell = null, ?string $os = null): array
    {
        $os ??= PHP_OS_FAMILY;
        $id = $this->next++;
        $cols = max(10, min(1000, $cols));
        $rows = max(2, min(500, $rows));
        $command = $this->shell($shell, $os);
        $env = array_merge(Environment::variables(), ['TERM' => 'xterm-256color', 'COLORTERM' => 'truecolor', 'COLUMNS' => (string)$cols,
            'LINES' => (string)$rows, 'TERM_PROGRAM' => 'JumPHP-IDE']);
        if ($os !== 'Windows') {
            $pipes = [];
            // its size first, then the shell as the leader of a session whose terminal is this one (setsid -c, Linux):
            // job control (Ctrl+C, Ctrl+Z, fg) and SIGWINCH when the window changes size
            $wrapped = ['/bin/sh', '-c', 'stty cols "$1" rows "$2" 2>/dev/null; shift 2; '
                . 'if setsid -c true 2>/dev/null; then exec setsid -c "$@"; else exec "$@"; fi', 'sh', (string)$cols, (string)$rows, ...$command];
            $process = function_exists('proc_open') ? @proc_open($wrapped, [0 => ['pty'], 1 => ['pty'], 2 => ['pty']], $pipes, $this->root, $env) : false;
            $pty = is_resource($process);
            if (!$pty) {
                // errors with the output, in order: one stream
                $process = @proc_open(['/bin/sh', '-c', 'exec "$@" 2>&1', 'sh', ...$command, '-i'], [0 => ['pipe', 'r'], 1 => ['pipe', 'w']], $pipes, $this->root, $env);
            }
            if (!is_resource($process)) {
                throw new \RuntimeException('cannot start ' . implode(' ', $command));
            }
            stream_set_blocking($pipes[1], false);
            $this->open[$id] = ['process' => $process, 'in' => $pipes[0], 'out' => $pipes[1], 'pty' => $pty, 'tty' => null];
            if ($pty) {
                $this->device($id);     // now, while the wrapper still has it: setsid -c may fork
            }
            return ['id' => $id, 'pty' => $pty, 'shell' => basename($command[0])];
        }
        $started = $this->processes->start($command, $this->root, $env, function (string $chunk) use ($id): void {
            ($this->emit)('terminal.output', ['id' => $id, 'data' => base64_encode($chunk)]);
        }, null, true);
        $this->open[$id] = ['process' => $started['id'], 'pty' => false];
        $started['done']->then(fn () => $this->ended($id), fn () => $this->ended($id));
        return ['id' => $id, 'pty' => false, 'shell' => basename($command[0])];
    }

    /** @return list<string> */
    private function shell(?string $shell, string $os): array
    {
        if ($os === 'Windows') {
            $wanted = strtolower($shell ?? (string)getenv('JUMPHP_IDE_SHELL'));
            if (str_contains($wanted, 'powershell') || $wanted === 'pwsh') {
                // reads its commands from stdin; no prompt of its own on a pipe: the page shows one
                return [Environment::which('pwsh') ?? 'powershell.exe', '-NoLogo', '-NoProfile', '-Command', '-'];
            }
            return [getenv('COMSPEC') ?: 'cmd.exe', '/Q', '/K', 'chcp 65001 >NUL'];     // UTF-8 out
        }
        foreach ([$shell, getenv('SHELL') ?: null, '/bin/bash', '/bin/zsh', '/bin/sh'] as $candidate) {
            if ($candidate !== null && $candidate !== '' && is_executable($candidate)) {
                return [$candidate];
            }
        }
        return ['/bin/sh'];
    }

    /** What the user typed (keys, pasted text). */
    public function write(int $id, string $data): bool
    {
        $t = $this->open[$id] ?? null;
        if ($t === null) {
            return false;
        }
        if (!is_resource($t['in'] ?? null)) {
            return $this->processes->write((int)$t['process'], $data);
        }
        while ($data !== '') {
            $written = @fwrite($t['in'], $data);
            if ($written === false || $written === 0) {
                return false;
            }
            $data = substr($data, $written);
        }
        return true;
    }

    /** The window's new size in characters: the terminal device gets it (programs are told by the kernel). */
    public function resize(int $id, int $cols, int $rows): bool
    {
        $t = $this->open[$id] ?? null;
        if ($t === null || !$t['pty'] || !is_resource($t['process'])) {
            return false;
        }
        $tty = $this->device($id);
        if ($tty === '') {
            return false;
        }
        $flag = PHP_OS_FAMILY === 'Darwin' ? '-f' : '-F';
        $this->processes->start(['stty', $flag, $tty, 'cols', (string)max(10, $cols), 'rows', (string)max(2, $rows)], $this->root);
        return true;
    }

    /** The shell's terminal device (/dev/pts/3), '' when it cannot be known. */
    private function device(int $id): string
    {
        $t = $this->open[$id];
        if ($t['tty'] !== null) {
            return $t['tty'];
        }
        $pid = (int)proc_get_status($t['process'])['pid'];
        $link = $pid > 0 ? @readlink("/proc/$pid/fd/0") : false;     // Linux
        if ($link === false && $pid > 0) {
            $name = trim((string)@shell_exec('ps -o tty= -p ' . $pid . ' 2>/dev/null'));   // macOS
            $link = $name !== '' && $name !== '?' ? '/dev/' . $name : false;
        }
        return $this->open[$id]['tty'] = $link !== false && str_starts_with($link, '/dev/') ? $link : '';
    }

    /** Ends a shell and what it runs. */
    public function close(int $id): void
    {
        $t = $this->open[$id] ?? null;
        if ($t === null) {
            return;
        }
        if (!is_resource($t['process'])) {
            $this->processes->stop((int)$t['process'], true);
            return;    // ended() follows
        }
        $pid = (int)proc_get_status($t['process'])['pid'];
        if (function_exists('posix_kill') && $pid > 0) {
            @posix_kill(-$pid, 9);
        }
        @proc_terminate($t['process'], 9);
        foreach (['in', 'out'] as $pipe) {
            is_resource($t[$pipe]) && @fclose($t[$pipe]);
        }
        @proc_close($t['process']);
        $this->ended($id);
    }

    /** Reads the shells' output (once per frame). */
    public function poll(): void
    {
        foreach ($this->open as $id => $t) {
            if (!is_resource($t['process'])) {
                continue;     // Windows: Processes reads it
            }
            $data = '';
            while (is_resource($t['out']) && ($chunk = @fread($t['out'], 65536)) !== false && $chunk !== '') {
                $data .= $chunk;
                if (strlen($data) > 1 << 20) {
                    break;
                }
            }
            if ($data !== '') {
                ($this->emit)('terminal.output', ['id' => $id, 'data' => base64_encode($data)]);
            }
            if (!proc_get_status($t['process'])['running']) {
                foreach (['in', 'out'] as $pipe) {
                    is_resource($t[$pipe]) && @fclose($t[$pipe]);
                }
                @proc_close($t['process']);
                $this->ended($id);
            }
        }
    }

    private function ended(int $id): void
    {
        if (isset($this->open[$id])) {
            unset($this->open[$id]);
            ($this->emit)('terminal.exit', ['id' => $id]);
        }
    }

    public function closeAll(): void
    {
        foreach (array_keys($this->open) as $id) {
            $this->close($id);
        }
    }

    public function count(): int
    {
        return count($this->open);
    }
}
