<?php

namespace App\Ui;

use Revolt\EventLoop\CallbackType;
use Revolt\EventLoop\Driver;
use Revolt\EventLoop\InvalidCallbackError;
use Revolt\EventLoop\Suspension;
use Revolt\EventLoop\UnsupportedFeatureException;

/**
 * A Revolt event loop without fibers, for jumphp.net's web terminal: the browser's PHP (WebAssembly) has no fibers,
 * which Revolt's own drivers need. Timers, deferred and queued callbacks, readable / writable streams; a suspension
 * only from outside any callback (what symfony/tui's Tui::run() does: it suspends until stop()). When nothing is due
 * it sleeps through $sleep (the web terminal wakes it as soon as a key is typed).
 */
final class WebLoop implements Driver
{
    /** @var array<string, array{type: CallbackType, closure: \Closure, enabled: bool, referenced: bool, at: float, interval: float, stream: mixed}> */
    private array $callbacks = [];
    /** @var list<array{\Closure, array<mixed>}> */
    private array $queue = [];
    private int $next = 0;
    private bool $running = false;
    private bool $stopped = false;
    private ?\Closure $errorHandler = null;

    /** @param \Closure(float): void $sleep waits at most that many seconds */
    public function __construct(private \Closure $sleep)
    {
    }

    public function run(): void
    {
        $this->loop(fn (): bool => $this->stopped);
    }

    /** Turns until $done says so, or nothing referenced is left (then false). */
    public function loop(\Closure $done): bool
    {
        $wasRunning = $this->running;
        $this->running = true;
        $this->stopped = false;
        try {
            while (!$done()) {
                if (!$this->tick()) {
                    return false;
                }
            }
            return true;
        } finally {
            $this->running = $wasRunning;
            $this->stopped = false;
        }
    }

    /** One turn: what is queued, deferred, due, ready; sleeps when nothing was. False when nothing is left to wait for. */
    private function tick(): bool
    {
        $ran = $this->runQueue();
        $now = microtime(true);
        $nextAt = null;
        $streams = [];
        foreach ($this->callbacks as $id => $c) {
            if (!$c['enabled']) {
                continue;
            }
            if ($c['type'] === CallbackType::Defer) {
                unset($this->callbacks[$id]);
                $this->invoke($c['closure'], $id);
                $ran = true;
            } elseif ($c['type'] === CallbackType::Delay || $c['type'] === CallbackType::Repeat) {
                if ($c['at'] <= $now) {
                    if ($c['type'] === CallbackType::Delay) {
                        unset($this->callbacks[$id]);
                    } else {
                        $this->callbacks[$id]['at'] = $now + $c['interval'];
                    }
                    $this->invoke($c['closure'], $id);
                    $ran = true;
                } else {
                    $nextAt = $nextAt === null ? $c['at'] : min($nextAt, $c['at']);
                }
            } elseif ($c['type'] === CallbackType::Readable || $c['type'] === CallbackType::Writable) {
                $streams[$id] = $c;
            }
            if ($this->stopped) {
                return true;
            }
        }
        $ran = $this->runQueue() || $ran;
        if ($streams) {
            $ran = $this->select($streams) || $ran;
        }
        if (!$this->hasReferenced()) {
            return false;
        }
        if (!$ran && $nextAt !== null) {
            ($this->sleep)(max(0.0, min(1.0, $nextAt - microtime(true))));
        }
        return true;
    }

    private function runQueue(): bool
    {
        $ran = false;
        while ($this->queue) {
            [$closure, $args] = array_shift($this->queue);
            $closure(...$args);
            $ran = true;
        }
        return $ran;
    }

    /** @param array<string, array{type: CallbackType, closure: \Closure, enabled: bool, referenced: bool, at: float, interval: float, stream: mixed}> $streams */
    private function select(array $streams): bool
    {
        $read = $write = [];
        foreach ($streams as $id => $c) {
            if ($c['type'] === CallbackType::Readable) {
                $read[$id] = $c['stream'];
            } else {
                $write[$id] = $c['stream'];
            }
        }
        $except = null;
        if (!@stream_select($read, $write, $except, 0)) {
            return false;
        }
        foreach ($read + $write as $id => $stream) {
            if (isset($this->callbacks[$id]) && $this->callbacks[$id]['enabled']) {
                $this->invoke($this->callbacks[$id]['closure'], (string)$id, $stream);
            }
        }
        return true;
    }

    private function invoke(\Closure $closure, string $id, mixed ...$args): void
    {
        try {
            $closure($id, ...$args);
        } catch (\Throwable $e) {
            if (!$this->errorHandler) {
                throw $e;
            }
            ($this->errorHandler)($e);
        }
    }

    private function hasReferenced(): bool
    {
        foreach ($this->callbacks as $c) {
            if ($c['enabled'] && $c['referenced']) {
                return true;
            }
        }
        return (bool)$this->queue;
    }

    public function stop(): void
    {
        $this->stopped = true;
    }

    public function getSuspension(): Suspension
    {
        $loop = $this;
        return new class ($loop) implements Suspension {
            private bool $resumed = false;
            private mixed $value = null;
            private ?\Throwable $error = null;

            public function __construct(private WebLoop $loop)
            {
            }

            public function resume(mixed $value = null): void
            {
                $this->resumed = true;
                $this->value = $value;
            }

            public function throw(\Throwable $throwable): void
            {
                $this->resumed = true;
                $this->error = $throwable;
            }

            public function suspend(): mixed
            {
                if (!$this->loop->loop(fn (): bool => $this->resumed) && !$this->resumed) {
                    throw new \Error('The event loop ended without resuming the suspension');
                }
                $this->resumed = false;
                if ($this->error) {
                    $error = $this->error;
                    $this->error = null;
                    throw $error;
                }
                return $this->value;
            }
        };
    }

    public function isRunning(): bool
    {
        return $this->running;
    }

    public function queue(\Closure $closure, mixed ...$args): void
    {
        $this->queue[] = [$closure, $args];
    }

    private function add(CallbackType $type, \Closure $closure, float $at = 0.0, float $interval = 0.0, mixed $stream = null): string
    {
        $id = 'w' . $this->next++;
        $this->callbacks[$id] = ['type' => $type, 'closure' => $closure, 'enabled' => true, 'referenced' => true, 'at' => $at, 'interval' => $interval, 'stream' => $stream];
        return $id;
    }

    public function defer(\Closure $closure): string
    {
        return $this->add(CallbackType::Defer, $closure);
    }

    public function delay(float $delay, \Closure $closure): string
    {
        return $this->add(CallbackType::Delay, $closure, microtime(true) + max(0.0, $delay), max(0.0, $delay));
    }

    public function repeat(float $interval, \Closure $closure): string
    {
        return $this->add(CallbackType::Repeat, $closure, microtime(true) + max(0.0, $interval), max(0.0, $interval));
    }

    public function onReadable(mixed $stream, \Closure $closure): string
    {
        return $this->add(CallbackType::Readable, $closure, stream: $stream);
    }

    public function onWritable(mixed $stream, \Closure $closure): string
    {
        return $this->add(CallbackType::Writable, $closure, stream: $stream);
    }

    public function onSignal(int $signal, \Closure $closure): string
    {
        throw new UnsupportedFeatureException('No signals in the browser');
    }

    public function enable(string $callbackId): string
    {
        $this->callback($callbackId);
        if (!$this->callbacks[$callbackId]['enabled'] && in_array($this->callbacks[$callbackId]['type'], [CallbackType::Delay, CallbackType::Repeat], true)) {
            $this->callbacks[$callbackId]['at'] = microtime(true) + $this->callbacks[$callbackId]['interval'];
        }
        $this->callbacks[$callbackId]['enabled'] = true;
        return $callbackId;
    }

    public function cancel(string $callbackId): void
    {
        unset($this->callbacks[$callbackId]);
    }

    public function disable(string $callbackId): string
    {
        if (isset($this->callbacks[$callbackId])) {
            $this->callbacks[$callbackId]['enabled'] = false;
        }
        return $callbackId;
    }

    public function reference(string $callbackId): string
    {
        $this->callback($callbackId);
        $this->callbacks[$callbackId]['referenced'] = true;
        return $callbackId;
    }

    public function unreference(string $callbackId): string
    {
        if (isset($this->callbacks[$callbackId])) {
            $this->callbacks[$callbackId]['referenced'] = false;
        }
        return $callbackId;
    }

    public function setErrorHandler(?\Closure $errorHandler): void
    {
        $this->errorHandler = $errorHandler;
    }

    public function getErrorHandler(): ?\Closure
    {
        return $this->errorHandler;
    }

    public function getHandle(): mixed
    {
        return null;
    }

    public function getIdentifiers(): array
    {
        return array_map('strval', array_keys($this->callbacks));
    }

    public function getType(string $callbackId): CallbackType
    {
        return $this->callback($callbackId)['type'];
    }

    public function isEnabled(string $callbackId): bool
    {
        return $this->callback($callbackId)['enabled'];
    }

    public function isReferenced(string $callbackId): bool
    {
        return $this->callback($callbackId)['referenced'];
    }

    public function __debugInfo(): array
    {
        return ['callbacks' => count($this->callbacks), 'queued' => count($this->queue), 'running' => $this->running];
    }

    /** @return array{type: CallbackType, closure: \Closure, enabled: bool, referenced: bool, at: float, interval: float, stream: mixed} */
    private function callback(string $callbackId): array
    {
        return $this->callbacks[$callbackId] ?? throw InvalidCallbackError::invalidIdentifier($callbackId);
    }
}
