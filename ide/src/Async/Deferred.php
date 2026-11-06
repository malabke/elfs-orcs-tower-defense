<?php

namespace JumPHP\Ide\Async;

/**
 * A result that comes later (a process that ends, an HTTP answer). The core does not depend on the engine: the
 * desktop app turns it into a JumPHP\Async\Task (see games/ide), the tests read it directly.
 */
final class Deferred
{
    private bool $done = false;
    private mixed $result = null;
    private ?\Throwable $error = null;

    /** @var list<array{0: ?callable, 1: ?callable}> */
    private array $listeners = [];

    public static function resolved(mixed $value): self
    {
        $d = new self();
        $d->resolve($value);
        return $d;
    }

    public static function rejected(\Throwable $error): self
    {
        $d = new self();
        $d->reject($error);
        return $d;
    }

    public function resolve(mixed $value): void
    {
        if ($value instanceof self) {
            $value->then(fn ($v) => $this->resolve($v), fn (\Throwable $e) => $this->reject($e));
            return;
        }
        $this->settle($value, null);
    }

    public function reject(\Throwable $error): void
    {
        $this->settle(null, $error);
    }

    private function settle(mixed $value, ?\Throwable $error): void
    {
        if ($this->done) {
            return;
        }
        $this->done = true;
        $this->result = $value;
        $this->error = $error;
        $listeners = $this->listeners;
        $this->listeners = [];
        foreach ($listeners as [$onDone, $onError]) {
            $this->call($onDone, $onError);
        }
    }

    /** Runs $onDone with the result (or $onError with the exception) once settled; returns a Deferred of what it returns. */
    public function then(?callable $onDone, ?callable $onError = null): self
    {
        $next = new self();
        $wrapDone = function (mixed $value) use ($onDone, $next): void {
            try {
                $next->resolve($onDone ? $onDone($value) : $value);
            } catch (\Throwable $e) {
                $next->reject($e);
            }
        };
        $wrapError = function (\Throwable $error) use ($onError, $next): void {
            if ($onError === null) {
                $next->reject($error);
                return;
            }
            try {
                $next->resolve($onError($error));
            } catch (\Throwable $e) {
                $next->reject($e);
            }
        };
        if ($this->done) {
            $this->call($wrapDone, $wrapError);
        } else {
            $this->listeners[] = [$wrapDone, $wrapError];
        }
        return $next;
    }

    private function call(?callable $onDone, ?callable $onError): void
    {
        if ($this->error !== null) {
            $onError && $onError($this->error);
        } else {
            $onDone && $onDone($this->result);
        }
    }

    public function isDone(): bool
    {
        return $this->done;
    }

    public function result(): mixed
    {
        if ($this->error !== null) {
            throw $this->error;
        }
        return $this->result;
    }

    public function error(): ?\Throwable
    {
        return $this->error;
    }
}
