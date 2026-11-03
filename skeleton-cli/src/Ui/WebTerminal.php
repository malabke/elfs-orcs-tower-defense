<?php

namespace App\Ui;

use Revolt\EventLoop;
use Symfony\Component\Tui\Input\StdinBuffer;
use Symfony\Component\Tui\Terminal\TerminalInterface;

/**
 * symfony/tui's terminal in the web terminal of jumphp.net (the app played in a browser): there is no `stty`, so the
 * page does what it would. "raw" to its control device turns the page's line editing and echo off (the keys come one
 * by one, as the same escape sequences as on Linux), "wait <seconds>" sleeps until a key comes (the screen's timers
 * keep running: WebLoop calls it when nothing is due), a read of it gives the terminal's size (it follows the browser
 * window). JUMPHP_WEB_TERMINAL=1 says the app runs there (docs/cli.md "On jumphp.net").
 */
final class WebTerminal implements TerminalInterface
{
    private const CONTROL = '/dev/jumphp-term';
    private const KEYS = '/dev/jumphp-keys';

    /** @var resource|null */
    private $control = null;
    /** @var resource|null */
    private $keys = null;
    private bool $started = false;
    private ?StdinBuffer $buffer = null;
    private ?string $pollId = null;
    private ?string $pendingId = null;
    private ?\Closure $onInput = null;
    private ?\Closure $onResize = null;
    /** @var array{int, int} */
    private array $size = [80, 24];

    /** The app runs in jumphp.net's web terminal, which can give it raw keys. */
    public static function available(): bool
    {
        return getenv('JUMPHP_WEB_TERMINAL') === '1' && file_exists(self::CONTROL);
    }

    /** This terminal, with an event loop that works there (the browser's PHP has no fibers: WebLoop). */
    public static function create(): self
    {
        EventLoop::setDriver(new WebLoop(self::sleep(...)));
        return new self();
    }

    /** Waits until a key is typed, at most $seconds. */
    public static function sleep(float $seconds): void
    {
        $control = @fopen(self::CONTROL, 'r+');
        if ($control) {
            fwrite($control, sprintf("wait %.3F\n", $seconds));
            fclose($control);
        }
    }

    public function start(callable $onInput, callable $onResize, callable $onKittyProtocolActivated): void
    {
        if ($this->started) {
            return;
        }
        $this->started = true;
        $this->onInput = $onInput(...);
        $this->onResize = $onResize(...);
        $this->control = fopen(self::CONTROL, 'a') ?: null;
        $this->keys = fopen(self::KEYS, 'r') ?: null;
        $this->command('raw');
        $this->size = $this->readSize();
        $this->buffer = new StdinBuffer();
        $this->buffer->onData(function (string $sequence): void {
            $this->onInput && ($this->onInput)($sequence);
        });
        $this->buffer->onPaste(function (string $content): void {
            $this->onInput && ($this->onInput)("\x1b[200~" . $content . "\x1b[201~");
        });
        $this->write("\x1b[?2004h");        // bracketed paste
        $this->pollId = EventLoop::repeat(0.01, fn () => $this->poll());
    }

    public function stop(): void
    {
        if (!$this->started) {
            return;
        }
        $this->started = false;
        foreach ([$this->pollId, $this->pendingId] as $id) {
            $id !== null && EventLoop::cancel($id);
        }
        $this->pollId = $this->pendingId = null;
        $this->write("\x1b[?2004l");
        $this->buffer?->clear();
        $this->buffer = null;
        $this->command('cooked');
        $this->keys && fclose($this->keys);
        $this->control && fclose($this->control);
        $this->keys = $this->control = null;
        $this->onInput = $this->onResize = null;
    }

    /** The keys typed since the last poll, and the resizes (WebLoop sleeps between polls until a key comes). */
    private function poll(): void
    {
        $size = $this->readSize();
        if ($size !== $this->size) {
            $this->size = $size;
            $this->onResize && ($this->onResize)();
        }
        $data = $this->keys ? (string)fread($this->keys, 4096) : '';
        if ($data === '') {
            return;
        }
        if ($this->pendingId !== null) {
            EventLoop::cancel($this->pendingId);
            $this->pendingId = null;
        }
        $this->buffer?->process($data);
        $pending = $this->buffer?->getBuffer() ?? '';
        if ($this->started && $pending !== '') {
            // a lone ESC is the Escape key when nothing follows it soon
            $this->pendingId = EventLoop::delay($pending === "\x1b" ? 0.01 : 0.05, function (): void {
                $this->pendingId = null;
                $this->buffer?->flushPending();
            });
        }
    }

    private function command(string $command): void
    {
        if ($this->control) {
            fwrite($this->control, $command . "\n");
            fflush($this->control);
        }
    }

    /** @return array{int, int} columns, rows */
    private function readSize(): array
    {
        [$columns, $rows] = array_map('intval', explode(' ', trim((string)@file_get_contents(self::CONTROL))) + [1 => 0]);
        return [$columns > 0 ? $columns : 80, $rows > 0 ? $rows : 24];
    }

    public function write(string $data): void
    {
        fwrite(\STDOUT, $data);
        fflush(\STDOUT);
    }

    public function getColumns(): int
    {
        return $this->size[0];
    }

    public function getRows(): int
    {
        return $this->size[1];
    }

    public function isKittyProtocolActive(): bool
    {
        return false;
    }

    public function moveBy(int $lines): void
    {
        if ($lines > 0) {
            $this->write("\x1b[{$lines}B");
        } elseif ($lines < 0) {
            $this->write("\x1b[" . (-$lines) . 'A');
        }
    }

    public function hideCursor(): void
    {
        $this->write("\x1b[?25l");
    }

    public function showCursor(): void
    {
        $this->write("\x1b[?25h");
    }

    public function clearLine(): void
    {
        $this->write("\x1b[2K");
    }

    public function clearFromCursor(): void
    {
        $this->write("\x1b[0J");
    }

    public function clearScreen(): void
    {
        $this->write("\x1b[2J\x1b[H");
    }

    public function setTitle(string $title): void
    {
        $this->write("\x1b]0;" . preg_replace("/[\x00-\x1f\x7f]/", '', $title) . "\x07");
    }

    public function bell(): void
    {
        $this->write("\x07");
    }

    public function isVirtual(): bool
    {
        return false;
    }
}
