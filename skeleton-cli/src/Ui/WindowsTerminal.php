<?php

namespace App\Ui;

use Revolt\EventLoop;
use Symfony\Component\Tui\Input\StdinBuffer;
use Symfony\Component\Tui\Terminal\Terminal;
use Symfony\Component\Tui\Terminal\TerminalInterface;

/**
 * symfony/tui's terminal on a Windows console. Its own Terminal needs `stty` (raw keys, the size), which Windows
 * does not have: there the keys would only arrive after Enter, echoed. This one talks to the console itself through
 * FFI (kernel32): raw mode with VT input (the keys come as the same escape sequences as on Linux), a poll of the
 * console's input events every 10 ms on the event loop, the window's size and its resizes, UTF-8 output.
 *
 * WindowsTerminal::create(): this one on a Windows console with FFI, else symfony/tui's Terminal.
 */
final class WindowsTerminal implements TerminalInterface
{
    private const STD_INPUT = 4294967286;   // (DWORD)-10
    private const STD_OUTPUT = 4294967285;  // (DWORD)-11
    private const KEY_EVENT = 0x1;
    private const RESIZE_EVENT = 0x4;
    private const IN_PROCESSED = 0x1, IN_LINE = 0x2, IN_ECHO = 0x4, IN_WINDOW = 0x8, IN_MOUSE = 0x10, IN_QUICK_EDIT = 0x40,
        IN_EXTENDED = 0x80, IN_VT = 0x200;
    private const OUT_PROCESSED = 0x1, OUT_VT = 0x4;

    /** kernel32 through FFI (its functions are declared at run time) */
    private mixed $k32;
    private mixed $in;
    private mixed $out;
    private ?int $inMode = null;
    private ?int $outMode = null;
    private ?int $outputCp = null;
    private bool $started = false;
    private ?StdinBuffer $buffer = null;
    private ?string $pollId = null;
    private ?string $pendingId = null;
    private ?int $highSurrogate = null;
    private ?\Closure $onInput = null;
    private ?\Closure $onResize = null;

    /** The terminal for this console: this one on Windows (with FFI), jumphp.net's web terminal, symfony/tui's elsewhere. */
    public static function create(): TerminalInterface
    {
        if (WebTerminal::available()) {
            return WebTerminal::create();
        }
        if (PHP_OS_FAMILY === 'Windows' && extension_loaded('ffi')) {
            try {
                $terminal = new self();
                if ($terminal->isConsole()) {
                    return $terminal;
                }
            } catch (\Throwable) {
                // FFI not allowed (ffi.enable), no kernel32: the line mode below
            }
        }
        return new Terminal();
    }

    public function __construct()
    {
        $k32 = self::kernel32();
        $this->k32 = $k32;
        $this->in = $k32->GetStdHandle(self::STD_INPUT);
        $this->out = $k32->GetStdHandle(self::STD_OUTPUT);
    }

    /** kernel32 through FFI: mixed, as its functions are declared at run time (phpstan does not know them) */
    private static function kernel32(): mixed
    {
        return \FFI::cdef(<<<'C'
            typedef struct { short X; short Y; } COORD;
            typedef struct { short Left; short Top; short Right; short Bottom; } SMALL_RECT;
            typedef struct { COORD dwSize; COORD dwCursorPosition; unsigned short wAttributes; SMALL_RECT srWindow; COORD dwMaximumWindowSize; } CONSOLE_SCREEN_BUFFER_INFO;
            typedef struct { int bKeyDown; unsigned short wRepeatCount; unsigned short wVirtualKeyCode; unsigned short wVirtualScanCode; unsigned short UnicodeChar; unsigned int dwControlKeyState; } KEY_EVENT_RECORD;
            typedef struct { unsigned short EventType; unsigned short pad; union { KEY_EVENT_RECORD KeyEvent; COORD dwSize; unsigned char raw[16]; } Event; } INPUT_RECORD;
            void *GetStdHandle(unsigned int nStdHandle);
            int GetConsoleMode(void *h, unsigned int *mode);
            int SetConsoleMode(void *h, unsigned int mode);
            int GetNumberOfConsoleInputEvents(void *h, unsigned int *count);
            int ReadConsoleInputW(void *h, INPUT_RECORD *records, unsigned int length, unsigned int *read);
            int GetConsoleScreenBufferInfo(void *h, CONSOLE_SCREEN_BUFFER_INFO *info);
            unsigned int GetConsoleOutputCP(void);
            int SetConsoleOutputCP(unsigned int cp);
            C, 'kernel32.dll');
    }

    /** Both ends are a console (not a pipe or a file). */
    public function isConsole(): bool
    {
        $mode = $this->k32->new('unsigned int');
        return $this->k32->GetConsoleMode($this->in, \FFI::addr($mode)) !== 0
            && $this->k32->GetConsoleMode($this->out, \FFI::addr($mode)) !== 0;
    }

    public function start(callable $onInput, callable $onResize, callable $onKittyProtocolActivated): void
    {
        if ($this->started) {
            return;
        }
        $this->started = true;
        $this->onInput = $onInput(...);
        $this->onResize = $onResize(...);

        $mode = $this->k32->new('unsigned int');
        if ($this->k32->GetConsoleMode($this->in, \FFI::addr($mode)) !== 0) {
            $this->inMode = $mode->cdata;
            // raw: no line editing, no echo, Ctrl+C as a key; keys as VT sequences; resizes as events
            $raw = ($this->inMode & ~(self::IN_PROCESSED | self::IN_LINE | self::IN_ECHO | self::IN_MOUSE | self::IN_QUICK_EDIT))
                | self::IN_VT | self::IN_WINDOW | self::IN_EXTENDED;
            $this->k32->SetConsoleMode($this->in, $raw);
        }
        if ($this->k32->GetConsoleMode($this->out, \FFI::addr($mode)) !== 0) {
            $this->outMode = $mode->cdata;
            $this->k32->SetConsoleMode($this->out, $this->outMode | self::OUT_PROCESSED | self::OUT_VT);
        }
        $this->outputCp = $this->k32->GetConsoleOutputCP();
        $this->k32->SetConsoleOutputCP(65001);      // UTF-8

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
        $this->inMode !== null && $this->k32->SetConsoleMode($this->in, $this->inMode);
        $this->outMode !== null && $this->k32->SetConsoleMode($this->out, $this->outMode);
        $this->outputCp !== null && $this->k32->SetConsoleOutputCP($this->outputCp);
        $this->onInput = $this->onResize = null;
    }

    /** The console's waiting events: keys (their characters: VT sequences included) and resizes. */
    private function poll(): void
    {
        $count = $this->k32->new('unsigned int');
        if ($this->k32->GetNumberOfConsoleInputEvents($this->in, \FFI::addr($count)) === 0 || $count->cdata === 0) {
            return;
        }
        $records = $this->k32->new('INPUT_RECORD[' . min(256, $count->cdata) . ']');
        $read = $this->k32->new('unsigned int');
        if ($this->k32->ReadConsoleInputW($this->in, $records, min(256, $count->cdata), \FFI::addr($read)) === 0) {
            return;
        }
        $text = '';
        $resized = false;
        for ($i = 0; $i < $read->cdata; $i++) {
            $record = $records[$i];
            if ($record->EventType === self::RESIZE_EVENT) {
                $resized = true;
            } elseif ($record->EventType === self::KEY_EVENT && $record->Event->KeyEvent->bKeyDown !== 0) {
                $text .= str_repeat($this->char($record->Event->KeyEvent->UnicodeChar), max(1, $record->Event->KeyEvent->wRepeatCount));
            }
        }
        if ($resized && $this->onResize) {
            ($this->onResize)();
        }
        if ($text !== '') {
            $this->process($text);
        }
    }

    /** A UTF-16 unit as UTF-8 (a surrogate pair comes as two events). */
    private function char(int $unit): string
    {
        if ($unit === 0) {
            return '';
        }
        if ($unit >= 0xD800 && $unit <= 0xDBFF) {
            $this->highSurrogate = $unit;
            return '';
        }
        if ($unit >= 0xDC00 && $unit <= 0xDFFF && $this->highSurrogate !== null) {
            $unit = 0x10000 + (($this->highSurrogate - 0xD800) << 10) + ($unit - 0xDC00);
            $this->highSurrogate = null;
        }
        return (string)mb_chr($unit, 'UTF-8');
    }

    private function process(string $data): void
    {
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

    public function write(string $data): void
    {
        fwrite(\STDOUT, $data);
        fflush(\STDOUT);
    }

    /** @return array{int, int} columns, rows of the window */
    private function size(): array
    {
        $info = $this->k32->new('CONSOLE_SCREEN_BUFFER_INFO');
        if ($this->k32->GetConsoleScreenBufferInfo($this->out, \FFI::addr($info)) === 0) {
            return [80, 24];
        }
        return [max(1, $info->srWindow->Right - $info->srWindow->Left + 1), max(1, $info->srWindow->Bottom - $info->srWindow->Top + 1)];
    }

    public function getColumns(): int
    {
        return $this->size()[0];
    }

    public function getRows(): int
    {
        return $this->size()[1];
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
