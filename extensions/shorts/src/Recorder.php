<?php

namespace JumPHP\Shorts;

use JumPHP\Audio\SoundManager;
use JumPHP\Audio\Synth;
use JumPHP\Kernel\Platform;

/**
 * Notes a scene plays (a collision, the next note of a Melody): heard at once through SoundManager, and, while
 * bin/jumphp video:make renders the scene (JUMPHP_VIDEO_OUT), written to <out>/notes.json for the offline mix.
 * reset() in the scene's enter(); note($time, $freq) with the scene's own clock (seconds since enter()).
 */
final class Recorder
{
    /** @var list<array{time: float, freq: float, length: float, wave: string, volume: float}> */
    private array $notes = [];

    public function __construct(private SoundManager $sound)
    {
    }

    /** The folder video:make writes into, or null when the scene is just played. */
    public function out(): ?string
    {
        $out = Platform::setting('JUMPHP_VIDEO_OUT');
        return $out === null || $out === '' ? null : (string)$out;
    }

    public function isRecording(): bool
    {
        return $this->out() !== null;
    }

    public function reset(): void
    {
        $this->notes = [];
        $this->save();
    }

    public function note(float $time, float $freq, float $length = 0.35, string $wave = 'pluck', float $volume = 0.35): void
    {
        $this->notes[] = ['time' => round($time, 4), 'freq' => round($freq, 3), 'length' => $length, 'wave' => $wave, 'volume' => $volume];
        $name = 'shorts-' . (int)round($freq);
        if (!$this->sound->hasSfx($name)) {
            $this->sound->synth($name, Synth::tone($freq, min(0.3, $length), $wave === 'pluck' ? 'triangle' : $wave, $volume));
        }
        $this->sound->playSfx($name);
        $this->save();
    }

    /** @return list<array{time: float, freq: float, length: float, wave: string, volume: float}> */
    public function notes(): array
    {
        return $this->notes;
    }

    private function save(): void
    {
        $out = $this->out();
        if ($out !== null) {
            @mkdir($out, 0777, true);
            file_put_contents("$out/notes.json", json_encode($this->notes));
        }
    }
}
