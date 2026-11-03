<?php

namespace App\Ui;

use JumPHP\Audio\SoundManager;
use JumPHP\Audio\Synth;

/**
 * The game's sounds, made by Synth once (a file assets/sfx/<name>.wav replaces one).
 */
final class Sfx
{
    public static function load(SoundManager $sound, string $root): void
    {
        if ($sound->hasSfx('menu_move')) {
            return;
        }
        $sounds = [
            'menu_move' => Synth::tone(660, 0.04, 'square', 0.3),
            'menu_confirm' => Synth::sequence([[660, 0.05], [990, 0.08]], 'square', 0.35),
            'arrow' => Synth::sweep(1400, 700, 0.06, 'triangle', 0.25),
            'orb' => Synth::sweep(500, 900, 0.12, 'sine', 0.3),
            'root' => Synth::sweep(160, 70, 0.18, 'saw', 0.3),
            'note' => Synth::sequence([[784, 0.05], [1047, 0.08]], 'triangle', 0.3),
            'star' => Synth::sweep(1800, 900, 0.15, 'sine', 0.3),
            'boom' => Synth::noise(0.18, 0.3),
            'kill' => Synth::sweep(300, 90, 0.12, 'square', 0.25),
            'coin' => Synth::sequence([[988, 0.04], [1319, 0.08]], 'square', 0.25),
            'build' => Synth::sequence([[220, 0.05], [330, 0.05], [440, 0.1]], 'square', 0.35),
            'upgrade' => Synth::sequence([[523, 0.06], [659, 0.06], [784, 0.06], [1047, 0.12]], 'square', 0.35),
            'sell' => Synth::sequence([[784, 0.05], [523, 0.1]], 'square', 0.3),
            'leak' => Synth::sweep(400, 120, 0.3, 'saw', 0.4),
            'wave' => Synth::sequence([[196, 0.15], [196, 0.08], [262, 0.3]], 'saw', 0.4),
            'deny' => Synth::tone(120, 0.12, 'square', 0.3),
            'victory' => Synth::sequence([[523, 0.12], [659, 0.12], [784, 0.12], [1047, 0.4]], 'square', 0.4),
            'defeat' => Synth::sequence([[392, 0.2], [330, 0.2], [262, 0.2], [196, 0.5]], 'triangle', 0.4),
        ];
        foreach ($sounds as $name => $wav) {
            $file = "assets/sfx/$name.wav";
            if (is_file(rtrim($root, '/') . '/' . $file)) {
                $sound->loadSfx($name, $file);
            } else {
                $sound->synth($name, $wav);
            }
        }
    }
}
