<?php

namespace JumPHP\Shorts;

use JumPHP\Audio\Synth;

/**
 * The sound of a video, mixed offline from the notes a scene played (Recorder): each one a plucked tone at its time,
 * all summed, softly limited, as a mono WAV (Synth::RATE Hz). No audio device needed.
 */
final class Mixer
{
    /**
     * @param list<array{time: float, freq: float, length?: float, wave?: string, volume?: float}> $notes
     */
    public static function mix(array $notes, float $seconds): string
    {
        $rate = Synth::RATE;
        $count = max(1, (int)ceil($seconds * $rate));
        $mix = array_fill(0, $count, 0.0);
        foreach ($notes as $note) {
            $start = (int)round($note['time'] * $rate);
            $length = (int)(($note['length'] ?? 0.35) * $rate);
            $freq = (float)$note['freq'];
            $volume = (float)($note['volume'] ?? 0.35);
            $wave = $note['wave'] ?? 'pluck';
            $end = min($count, $start + $length);
            $attack = max(1, (int)(0.004 * $rate));
            for ($i = max(0, $start); $i < $end; $i++) {
                $k = $i - $start;
                $t = $k / $rate;
                $p = fmod($t * $freq, 1.0);
                $v = match ($wave) {
                    'sine' => sin(2 * M_PI * $p),
                    'square' => ($p < 0.5 ? 1.0 : -1.0) * 0.5,
                    'triangle' => 4 * abs($p - 0.5) - 1,
                    default => 0.6 * sin(2 * M_PI * $p) + 0.3 * sin(4 * M_PI * $p) + 0.1 * (4 * abs($p - 0.5) - 1),
                };
                $env = ($k < $attack ? $k / $attack : 1.0) * exp(-5.0 * $t / max(0.05, $length / $rate));
                $mix[$i] += $v * $env * $volume;
            }
        }
        $samples = [];
        foreach ($mix as $v) {
            $samples[] = (int)(32767 * tanh($v));          // a soft limit: chords never clip
        }
        return Synth::wav($samples);
    }

    /** notes.json (Recorder) -> a WAV file of $seconds. */
    public static function mixFile(string $notesJson, float $seconds, string $wavFile): void
    {
        $notes = is_file($notesJson) ? (json_decode((string)file_get_contents($notesJson), true) ?: []) : [];
        file_put_contents($wavFile, self::mix($notes, $seconds));
    }
}
