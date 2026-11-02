<?php

namespace JumPHP\Ide\Debug;

use JumPHP\Ide\Async\Deferred;

/**
 * Step debugging with Xdebug (DBGp): the IDE listens on a port of this computer (127.0.0.1), the PHP runs started with
 * JUMPHP_XDEBUG=<port> (bin/jumphp: the desktop image with Xdebug) connect to it. Breakpoints are kept here and given
 * to every program that connects; a program stops at one (or after a step): event debug.paused with where and the
 * stack, then the interface (or Claude's tools) asks the variables, evaluates, steps, resumes.
 *
 * Paths: Xdebug says file:///workspace/<path in the project> (bin/jumphp mounts the project at /workspace); a program
 * run outside Docker says its real path: both are mapped to paths of the project.
 *
 * Events: debug.state {listening, port, sessions}, debug.paused {session, path, line, stack}, debug.running {session},
 * debug.ended {session}, debug.output {session, text}.
 */
final class Debugger
{
    public const CONTAINER_ROOT = '/workspace';

    /** @var resource|null */
    private $server = null;
    private int $port = 0;

    /** @var array<int, Session> */
    private array $sessions = [];
    private int $nextSession = 1;

    /** @var array<string, array<int, array{line: int, condition: ?string}>> path => line => breakpoint */
    private array $breakpoints = [];

    /** @var array<int, array<string, array{path: string, line: int}>> session => Xdebug breakpoint id => where */
    private array $ids = [];

    /** @var array<int, array<string, mixed>> session => where it stopped (path, line, stack) */
    private array $paused = [];

    /** @var array<int, string> session => the project's folder as that program sees it (/workspace in Docker) */
    private array $roots = [];

    /** @param callable(string $event, array $payload): void $emit */
    public function __construct(private string $root, private $emit)
    {
        $this->root = rtrim(str_replace('\\', '/', $root), '/');
    }

    /** Starts listening (once); the port to give to JUMPHP_XDEBUG. */
    public function listen(): int
    {
        if ($this->server !== null) {
            return $this->port;
        }
        foreach ([9003, 9013, 9023, 0] as $port) {
            $server = @stream_socket_server("tcp://127.0.0.1:$port", $code, $message);
            if ($server !== false) {
                stream_set_blocking($server, false);
                $this->server = $server;
                $name = (string)stream_socket_get_name($server, false);
                $this->port = (int)substr($name, strrpos($name, ':') + 1);
                $this->state();
                return $this->port;
            }
        }
        throw new \RuntimeException('the debugger cannot listen on this computer (no free port on 127.0.0.1)');
    }

    public function close(): void
    {
        foreach ($this->sessions as $session) {
            $session->close();
        }
        $this->sessions = [];
        if ($this->server !== null) {
            @fclose($this->server);
        }
        $this->server = null;
        $this->port = 0;
        $this->state();
    }

    /** @return array<string, mixed> */
    public function status(): array
    {
        return [
            'listening' => $this->server !== null, 'port' => $this->port,
            'sessions' => array_map(fn (Session $s) => ['id' => $s->id, 'status' => $s->status, 'paused' => $this->paused[$s->id] ?? null],
                array_values($this->sessions)),
            'breakpoints' => $this->breakpointList(),
        ];
    }

    // breakpoints -----------------------------------------------------------------------------------------------

    /**
     * The breakpoints of a file (all of them: those not listed are removed), given at once to the running programs.
     *
     * @param list<array{line?: int, condition?: ?string}|int> $lines
     */
    public function setBreakpoints(string $path, array $lines): array
    {
        $path = $this->projectPath($path);
        $new = [];
        foreach ($lines as $line) {
            $entry = is_array($line) ? $line : ['line' => $line];
            $n = (int)($entry['line'] ?? 0);
            if ($n > 0) {
                $condition = trim((string)($entry['condition'] ?? ''));
                $new[$n] = ['line' => $n, 'condition' => $condition !== '' ? $condition : null];
            }
        }
        $old = $this->breakpoints[$path] ?? [];
        if ($new) {
            $this->breakpoints[$path] = $new;
        } else {
            unset($this->breakpoints[$path]);
        }
        foreach ($this->sessions as $session) {
            foreach ($this->ids[$session->id] ?? [] as $id => $where) {
                if ($where['path'] === $path && (!isset($new[$where['line']]) || $new[$where['line']] != ($old[$where['line']] ?? null))) {
                    $session->command('breakpoint_remove', ['d' => $id]);
                    unset($this->ids[$session->id][$id]);
                }
            }
            foreach ($new as $n => $bp) {
                if (!isset($old[$n]) || $old[$n] != $bp) {
                    $this->sendBreakpoint($session, $path, $bp);
                }
            }
        }
        return $this->breakpointList();
    }

    public function clearBreakpoints(): array
    {
        foreach (array_keys($this->breakpoints) as $path) {
            $this->setBreakpoints($path, []);
        }
        return [];
    }

    /** @return list<array{path: string, line: int, condition: ?string}> */
    public function breakpointList(): array
    {
        $out = [];
        foreach ($this->breakpoints as $path => $lines) {
            foreach ($lines as $bp) {
                $out[] = ['path' => $path, 'line' => $bp['line'], 'condition' => $bp['condition']];
            }
        }
        return $out;
    }

    // the programs ------------------------------------------------------------------------------------------------

    /** Accepts the programs that connect, reads what they said. The IDE calls it every frame. */
    public function tick(): void
    {
        if ($this->server !== null) {
            while (($socket = @stream_socket_accept($this->server, 0)) !== false) {
                $session = new Session($this->nextSession++, $socket);
                $this->sessions[$session->id] = $session;
                $this->state();
            }
        }
        foreach ($this->sessions as $id => $session) {
            $messages = $session->poll();
            foreach ($messages === false ? [] : $messages as $message) {
                if ($message->getName() === 'init') {
                    $this->started($session);
                } elseif ($message->getName() === 'stream') {
                    ($this->emit)('debug.output', ['session' => $id, 'text' => base64_decode((string)$message)]);
                }
            }
            if ($messages === false || !$session->isOpen()) {
                $this->ended($session);
            }
        }
    }

    /** A program connected: the breakpoints, its output copied to the IDE, then it runs. */
    private function started(Session $session): void
    {
        // in Docker (bin/jumphp) the project is /workspace; a program run on this computer sees its real folder
        $script = str_replace('\\', '/', rawurldecode((string)preg_replace('#^file://#', '', (string)($session->init['fileuri'] ?? ''))));
        $this->roots[$session->id] = str_starts_with($script, self::CONTAINER_ROOT . '/') ? self::CONTAINER_ROOT : $this->root;
        $session->command('feature_set', ['n' => 'max_depth', 'v' => 1]);
        $session->command('feature_set', ['n' => 'max_children', 'v' => 200]);
        $session->command('feature_set', ['n' => 'max_data', 'v' => 4096]);
        $session->command('feature_set', ['n' => 'resolved_breakpoints', 'v' => 1]);
        foreach ($this->breakpoints as $path => $lines) {
            foreach ($lines as $bp) {
                $this->sendBreakpoint($session, $path, $bp);
            }
        }
        $this->resume($session->id, 'run');
    }

    private function sendBreakpoint(Session $session, string $path, array $bp): void
    {
        $args = ['t' => $bp['condition'] !== null ? 'conditional' : 'line', 'f' => $this->fileUri($path, $this->roots[$session->id] ?? self::CONTAINER_ROOT), 'n' => $bp['line']];
        $session->command('breakpoint_set', $args, $bp['condition'])->then(function (\SimpleXMLElement $r) use ($session, $path, $bp): void {
            $this->ids[$session->id][(string)$r['id']] = ['path' => $path, 'line' => $bp['line']];
        }, fn () => null);
    }

    private function ended(Session $session): void
    {
        $session->close();
        unset($this->sessions[$session->id], $this->ids[$session->id], $this->paused[$session->id], $this->roots[$session->id]);
        ($this->emit)('debug.ended', ['session' => $session->id]);
        $this->state();
    }

    /**
     * Resumes a stopped program: run (to the next breakpoint), step_over, step_into, step_out, or stop (ends it).
     * Resolves once it stops again (with where), or ends.
     */
    public function resume(int $id, string $how = 'run'): Deferred
    {
        $session = $this->session($id);
        if (!in_array($how, ['run', 'step_over', 'step_into', 'step_out', 'stop', 'detach'], true)) {
            throw new \InvalidArgumentException("unknown step $how (run, step_over, step_into, step_out, stop)");
        }
        unset($this->paused[$id]);
        $session->status = 'running';
        ($this->emit)('debug.running', ['session' => $id]);
        return $session->command($how)->then(function (\SimpleXMLElement $r) use ($session, $how) {
            $status = (string)$r['status'];
            if ($status === 'break') {
                return $this->pausedAt($session);
            }
            if ($status === 'stopping' && $how !== 'stop' && $how !== 'detach') {
                // the script ended: let it finish (its output, its exit)
                $session->command('run');
            }
            return ['session' => $session->id, 'status' => $status];
        }, function (\Throwable $e) use ($session) {
            return ['session' => $session->id, 'status' => 'stopped'];
        });
    }

    private function pausedAt(Session $session): Deferred
    {
        return $session->command('stack_get')->then(function (\SimpleXMLElement $r) use ($session) {
            $stack = [];
            foreach ($r->stack as $frame) {
                $stack[] = ['level' => (int)$frame['level'], 'where' => (string)$frame['where'],
                    'path' => $this->fromUri((string)$frame['filename']), 'line' => (int)$frame['lineno']];
            }
            $top = $stack[0] ?? ['path' => null, 'line' => 0];
            $paused = ['session' => $session->id, 'status' => 'break', 'path' => $top['path'], 'line' => $top['line'], 'stack' => $stack];
            $this->paused[$session->id] = $paused;
            ($this->emit)('debug.paused', $paused);
            return $paused;
        });
    }

    /**
     * The variables of a frame of the stack (0: where it stopped): locals (context 0), or the superglobals (1), or
     * the constants (2). Each one: name, type, class, value, and whether it has children (property()).
     */
    public function variables(int $id, int $depth = 0, int $context = 0): Deferred
    {
        return $this->session($id)->command('context_get', ['d' => $depth, 'c' => $context])
            ->then(fn (\SimpleXMLElement $r) => array_map($this->property(...), iterator_to_array($r->property, false)));
    }

    /** The children of a variable (its fullname from variables(): $hero->weapon, $list[3]...). */
    public function children(int $id, string $fullname, int $depth = 0, int $page = 0): Deferred
    {
        return $this->session($id)->command('property_get', ['n' => $fullname, 'd' => $depth, 'p' => $page])
            ->then(fn (\SimpleXMLElement $r) => isset($r->property) ? $this->property($r->property, true) : null);
    }

    /** Evaluates PHP in the stopped frame (an expression: $this->score * 2, count($enemies)...). */
    public function evaluate(int $id, string $expression): Deferred
    {
        return $this->session($id)->command('eval', [], $expression)
            ->then(fn (\SimpleXMLElement $r) => isset($r->property) ? $this->property($r->property, true) : ['type' => 'null', 'value' => 'null']);
    }

    /** @return array<string, mixed> */
    private function property(\SimpleXMLElement $p, bool $withChildren = false): array
    {
        $a = $p->attributes();
        $value = (string)$p;
        if ((string)($a['encoding'] ?? '') === 'base64') {
            $value = (string)base64_decode($value);
        }
        $type = (string)($a['type'] ?? '');
        $out = ['name' => (string)($a['name'] ?? ''), 'fullname' => (string)($a['fullname'] ?? ''), 'type' => $type,
            'class' => isset($a['classname']) ? (string)$a['classname'] : null,
            'value' => match ($type) {
                'array' => 'array(' . (int)($a['numchildren'] ?? 0) . ')',
                'object' => (string)($a['classname'] ?? 'object'),
                'null', 'uninitialized' => $type,
                'bool' => $value === '1' ? 'true' : 'false',
                'string' => mb_strimwidth($value, 0, 400, '…'),
                default => $value,
            },
            'children' => (int)($a['numchildren'] ?? 0) > 0 || (string)($a['children'] ?? '0') === '1'];
        if ($withChildren && isset($p->property)) {
            $out['items'] = array_map(fn ($c) => $this->property($c), iterator_to_array($p->property, false));
        }
        return $out;
    }

    private function session(int $id): Session
    {
        if ($id === 0 && $this->paused) {
            $id = (int)array_key_first($this->paused);
        }
        return $this->sessions[$id] ?? throw new \RuntimeException('no program being debugged' . ($id ? " (session $id)" : '') . ': run one with the debugger first');
    }

    private function state(): void
    {
        ($this->emit)('debug.state', $this->status());
    }

    // paths --------------------------------------------------------------------------------------------------------

    /** A path of the project (relative), from one of Xdebug's file URIs. Null: outside the project. */
    public function fromUri(string $uri): ?string
    {
        $path = rawurldecode((string)preg_replace('#^file://#', '', $uri));
        $path = str_replace('\\', '/', (string)preg_replace('#^/([A-Za-z]:)#', '$1', $path));
        foreach ([self::CONTAINER_ROOT, $this->root] as $root) {
            if (str_starts_with($path, "$root/")) {
                return substr($path, strlen($root) + 1);
            }
        }
        return null;
    }

    /** The file URI Xdebug knows a path of the project by (inside Docker: /workspace/...; Windows: file:///C:/...). */
    public function fileUri(string $path, string $root = self::CONTAINER_ROOT): string
    {
        $full = $root . '/' . implode('/', array_map('rawurlencode', explode('/', $this->projectPath($path))));
        return 'file://' . (preg_match('#^[A-Za-z]:#', $full) ? '/' : '') . $full;
    }

    private function projectPath(string $path): string
    {
        $path = str_replace('\\', '/', $path);
        if (str_starts_with($path, $this->root . '/')) {
            $path = substr($path, strlen($this->root) + 1);
        }
        return ltrim((string)preg_replace('#^\./#', '', $path), '/');
    }
}
