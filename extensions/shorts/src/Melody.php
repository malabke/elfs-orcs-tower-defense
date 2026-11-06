<?php

namespace JumPHP\Shorts;

/**
 * Notes played one after the other (each collision plays the next one), looping: frequencies in Hz, from names
 * ("C4 E4 G4 C5", "A#3", "r" = a rest that is skipped) or from a MIDI file (its notes in order of time).
 */
final class Melody
{
    /** @var list<float> */
    private array $notes;
    private int $index = 0;

    /** @param list<float|int|string> $notes */
    public function __construct(array $notes)
    {
        $this->notes = array_values(array_filter(array_map(fn ($n) => is_string($n) ? self::frequency($n) : (float)$n, $notes), fn (?float $f) => $f !== null && $f > 0));
        if (!$this->notes) {
            $this->notes = [440.0];
        }
    }

    public static function fromString(string $notes): self
    {
        return new self(preg_split('/[\s,]+/', trim($notes)) ?: []);
    }

    /** The notes of a standard MIDI file (format 0 or 1), in order of time; the first track that has notes. */
    public static function fromMidi(string $file): self
    {
        $data = (string)file_get_contents($file);
        $notes = [];
        $pos = 14;
        while ($pos + 8 <= strlen($data)) {
            $chunk = substr($data, $pos, 4);
            $length = unpack('N', substr($data, $pos + 4, 4))[1];
            $start = $pos + 8;
            $pos = $start + $length;
            if ($chunk !== 'MTrk') {
                continue;
            }
            $notes = array_merge($notes, self::trackNotes(substr($data, $start, $length)));
        }
        usort($notes, fn ($a, $b) => $a[0] <=> $b[0]);
        return new self(array_map(fn ($n) => 440 * 2 ** (($n[1] - 69) / 12), $notes));
    }

    /** @return list<array{0: int, 1: int}> [tick, MIDI note] of the note-ons of a track */
    private static function trackNotes(string $t): array
    {
        $notes = [];
        $i = 0;
        $tick = 0;
        $status = 0;
        $n = strlen($t);
        while ($i < $n) {
            $delta = 0;
            do {
                $b = ord($t[$i++]);
                $delta = ($delta << 7) | ($b & 0x7F);
            } while ($b & 0x80 && $i < $n);
            $tick += $delta;
            if ($i >= $n) {
                break;
            }
            $b = ord($t[$i]);
            if ($b === 0xFF) {                          // meta: type, length, data
                $i += 2;
                $len = 0;
                do {
                    $c = ord($t[$i++]);
                    $len = ($len << 7) | ($c & 0x7F);
                } while ($c & 0x80 && $i < $n);
                $i += $len;
                continue;
            }
            if ($b === 0xF0 || $b === 0xF7) {           // sysex
                $i++;
                $len = 0;
                do {
                    $c = ord($t[$i++]);
                    $len = ($len << 7) | ($c & 0x7F);
                } while ($c & 0x80 && $i < $n);
                $i += $len;
                continue;
            }
            if ($b & 0x80) {
                $status = $b;
                $i++;
            }
            $type = $status & 0xF0;
            $size = in_array($type, [0xC0, 0xD0], true) ? 1 : 2;
            if ($type === 0x90 && $i + 1 < $n && ord($t[$i + 1]) > 0) {
                $notes[] = [$tick, ord($t[$i])];
            }
            $i += $size;
        }
        return $notes;
    }

    /** "A4" = 440 Hz, "C#5", "Eb3"; "r" or "-" = null (a rest). */
    public static function frequency(string $name): ?float
    {
        if (is_numeric($name)) {
            return (float)$name;
        }
        if (!preg_match('/^([A-Ga-g])([#b]?)(-?\d)$/', $name, $m)) {
            return null;
        }
        $semitone = ['C' => 0, 'D' => 2, 'E' => 4, 'F' => 5, 'G' => 7, 'A' => 9, 'B' => 11][strtoupper($m[1])]
            + ($m[2] === '#' ? 1 : ($m[2] === 'b' ? -1 : 0));
        $midi = ((int)$m[3] + 1) * 12 + $semitone;
        return 440 * 2 ** (($midi - 69) / 12);
    }

    public function next(): float
    {
        $note = $this->notes[$this->index];
        $this->index = ($this->index + 1) % count($this->notes);
        return $note;
    }

    public function rewind(): void
    {
        $this->index = 0;
    }

    public function count(): int
    {
        return count($this->notes);
    }
}
