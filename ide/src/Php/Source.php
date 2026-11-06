<?php

namespace JumPHP\Ide\Php;

/**
 * A text and its positions. PHP works in byte offsets; the editor (Monaco) in lines and columns counted in UTF-16
 * code units, both starting at 1. This converts between the two.
 */
final class Source
{
    /** @var list<int> byte offset of each line's start */
    private array $lines = [0];

    public function __construct(public readonly string $text)
    {
        $offset = 0;
        while (($nl = strpos($text, "\n", $offset)) !== false) {
            $this->lines[] = $offset = $nl + 1;
        }
    }

    /** @return array{0: int, 1: int} [line, column] of a byte offset */
    public function position(int $offset): array
    {
        $offset = max(0, min($offset, strlen($this->text)));
        $low = 0;
        $high = count($this->lines) - 1;
        while ($low < $high) {
            $mid = intdiv($low + $high + 1, 2);
            if ($this->lines[$mid] <= $offset) {
                $low = $mid;
            } else {
                $high = $mid - 1;
            }
        }
        $start = $this->lines[$low];
        return [$low + 1, self::utf16Length(substr($this->text, $start, $offset - $start)) + 1];
    }

    /** The byte offset of a [line, column]. */
    public function offset(int $line, int $column): int
    {
        $line = max(1, min($line, count($this->lines)));
        $start = $this->lines[$line - 1];
        $end = $line < count($this->lines) ? $this->lines[$line] - 1 : strlen($this->text);
        $text = substr($this->text, $start, $end - $start);
        $units = $column - 1;
        if ($units <= 0) {
            return $start;
        }
        if (!preg_match('/[\x80-\xff]/', $text)) {
            return $start + min($units, strlen($text));
        }
        $bytes = 0;
        $length = strlen($text);
        while ($units > 0 && $bytes < $length) {
            $byte = ord($text[$bytes]);
            $size = $byte < 0x80 ? 1 : ($byte < 0xe0 ? 2 : ($byte < 0xf0 ? 3 : 4));
            $units -= $size === 4 ? 2 : 1;
            $bytes += $size;
        }
        return $start + min($bytes, $length);
    }

    /** A range for the editor: {startLine, startColumn, endLine, endColumn} of bytes [$start, $end). */
    public function range(int $start, int $end): array
    {
        [$l1, $c1] = $this->position($start);
        [$l2, $c2] = $this->position($end);
        return ['startLine' => $l1, 'startColumn' => $c1, 'endLine' => $l2, 'endColumn' => $c2];
    }

    public function lineCount(): int
    {
        return count($this->lines);
    }

    /** The text of one line (1-based), without its end of line. */
    public function line(int $line): string
    {
        if ($line < 1 || $line > count($this->lines)) {
            return '';
        }
        $start = $this->lines[$line - 1];
        $end = $line < count($this->lines) ? $this->lines[$line] - 1 : strlen($this->text);
        return rtrim(substr($this->text, $start, $end - $start), "\r");
    }

    public static function utf16Length(string $text): int
    {
        if (!preg_match('/[\x80-\xff]/', $text)) {
            return strlen($text);
        }
        return intdiv(strlen((string)mb_convert_encoding($text, 'UTF-16LE', 'UTF-8')), 2);
    }
}
