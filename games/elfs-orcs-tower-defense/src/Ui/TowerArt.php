<?php

namespace App\Ui;

use JumPHP\Util\Color;

/**
 * The towers of the dwarves, horsefolk, little folk and orcs, drawn with GD on a 28 x 40 picture (foot at the
 * bottom): a little taller at each level, a flag from level 2, gold from level 3, and the specialization's colour
 * (first branch gold, second red) at level 4.
 */
final class TowerArt
{
    public const SPRITES = ['crossbow', 'slingshot', 'forge', 'mine', 'stables', 'javelin', 'horn', 'palisade',
        'slingers', 'traps', 'inn', 'garden', 'barracks', 'catapult', 'pitch', 'drums'];

    /** @param int|null $spec 0 / 1: which specialization */
    public static function paint(\GdImage $img, string $sprite, int $level, ?int $spec): void
    {
        $c = static function (string $hex) use ($img): int {
            $col = Color::of($hex);
            return imagecolorallocatealpha($img, $col->r, $col->g, $col->b, 127 - intdiv($col->a, 2));
        };
        $rect = static fn (int $x, int $y, int $w, int $h, int $col) => imagefilledrectangle($img, $x, $y, $x + $w - 1, $y + $h - 1, $col);
        $poly = static fn (array $points, int $col) => imagefilledpolygon($img, $points, $col);
        $oval = static fn (int $cx, int $cy, int $w, int $h, int $col) => imagefilledellipse($img, $cx, $cy, $w, $h, $col);
        $ink = $c('#1a1424');
        $gold = $c('#e8c040');
        $accent = $spec === null ? null : $c($spec === 0 ? '#ffe070' : '#ff5a40');
        $b = 39;                       // the foot
        $top = 20 - 4 * $level;        // taller with each level
        $flagColor = $c('#ffffff');

        switch ($sprite) {
            case 'crossbow':
                $stone = $c('#8a9ab0');
                $dark = $c('#56657a');
                $wood = $c('#7a5230');
                $rect(4, $top + 12, 20, $b - $top - 11, $ink);
                $rect(5, $top + 13, 18, $b - $top - 13, $stone);
                $rect(17, $top + 13, 6, $b - $top - 13, $dark);
                for ($x = 5; $x < 23; $x += 5) {
                    $rect($x, $top + 9, 3, 4, $ink);
                    $rect($x + 1, $top + 10, 1, 3, $stone);
                }
                $rect(11, $b - 7, 6, 7, $c('#3a2a1a'));
                // the crossbow on the roof
                $rect(8, $top + 5, 13, 2, $wood);
                $rect(13, $top + 2, 3, 9, $wood);
                imageline($img, 6, $top + 9, 14, $top + 3, $c('#d0d0d0'));
                imageline($img, 22, $top + 9, 14, $top + 3, $c('#d0d0d0'));
                $flagColor = $c('#4a78a8');
                break;

            case 'slingshot':
                $wood = $c('#8a6a3a');
                $dark = $c('#5a4020');
                $poly([4, $b, 9, $b, 15, $top + 10, 12, $top + 10], $wood);
                $poly([24, $b, 19, $b, 14, $top + 10, 17, $top + 10], $dark);
                $rect(5, $b - 4, 18, 3, $dark);
                imageline($img, 14, $top + 12, 22, $top + 2, $wood);
                imageline($img, 15, $top + 12, 23, $top + 2, $wood);
                $oval(23, $top + 2, 7, 6, $ink);
                $oval(23, $top + 2, 5, 4, $c('#9a9a90'));
                $flagColor = $c('#4a78a8');
                break;

            case 'forge':
                $stone = $c('#7a7a86');
                $dark = $c('#4a4a56');
                $rect(3, $top + 12, 22, $b - $top - 11, $ink);
                $rect(4, $top + 13, 20, $b - $top - 13, $stone);
                $rect(17, $top + 13, 7, $b - $top - 13, $dark);
                $rect(18, $top + 2, 5, 11, $ink);
                $rect(19, $top + 3, 3, 10, $dark);
                $oval(20, $top, 6, 4, $c('#c8c8c8'));
                $rect(8, $b - 10, 9, 10, $ink);
                $rect(9, $b - 9, 7, 9, $c('#ff8a20'));
                $rect(10, $b - 6, 5, 6, $c('#ffd060'));
                $rect(7, $top + 17, 2, 4, $c('#80c8ff'));
                $rect(6, $top + 18, 4, 1, $c('#80c8ff'));
                $flagColor = $c('#4a78a8');
                break;

            case 'mine':
                $rock = $c('#7a6a5a');
                $dark = $c('#4a3e32');
                $wood = $c('#8a6a3a');
                $oval(14, $b - 6 + intdiv(20 - $top, 4), 28, 24 + 2 * $level, $ink);
                $oval(14, $b - 6 + intdiv(20 - $top, 4), 26, 22 + 2 * $level, $rock);
                $oval(18, $b - 2, 14, 10, $dark);
                $rect(8, $b - 13, 12, 13, $wood);
                $rect(10, $b - 11, 8, 11, $c('#140c08'));
                $rect(2, $b - 4, 7, 4, $ink);
                $rect(3, $b - 5, 5, 2, $gold);
                $flagColor = $c('#4a78a8');
                break;

            case 'stables':
                $wood = $c('#9a6a3a');
                $dark = $c('#6a4220');
                $roof = $c('#a83a2a');
                $rect(3, $top + 14, 22, $b - $top - 13, $ink);
                $rect(4, $top + 15, 20, $b - $top - 15, $wood);
                for ($x = 6; $x < 24; $x += 4) {
                    $rect($x, $top + 15, 1, $b - $top - 15, $dark);
                }
                $poly([1, $top + 15, 27, $top + 15, 14, $top + 3], $ink);
                $poly([3, $top + 14, 25, $top + 14, 14, $top + 5], $roof);
                $rect(10, $b - 10, 8, 10, $c('#2a1a0a'));
                imageline($img, 10, $b - 10, 17, $b - 1, $wood);
                imageline($img, 17, $b - 10, 10, $b - 1, $wood);
                // the horse head on the gable
                $rect(13, $top + 8, 3, 4, $c('#f0dca0'));
                $rect(12, $top + 7, 2, 2, $c('#f0dca0'));
                $flagColor = $c('#c08a30');
                break;

            case 'javelin':
                $wood = $c('#9a7040');
                $dark = $c('#6a4a26');
                $rect(6, $top + 10, 3, $b - $top - 10, $wood);
                $rect(19, $top + 10, 3, $b - $top - 10, $dark);
                imageline($img, 7, $b - 2, 20, $top + 14, $dark);
                imageline($img, 20, $b - 2, 7, $top + 14, $dark);
                $rect(3, $top + 8, 22, 4, $ink);
                $rect(4, $top + 9, 20, 2, $wood);
                $poly([3, $top + 8, 25, $top + 8, 14, $top - 1], $c('#c08a30'));
                foreach ([8, 13, 18] as $x) {
                    $rect($x, $top + 1, 1, 8, $c('#d8c8a0'));
                    $rect($x, $top, 1, 1, $c('#e0e0e0'));
                }
                $flagColor = $c('#c08a30');
                break;

            case 'horn':
                $stone = $c('#c8b88a');
                $dark = $c('#8a7a5a');
                $rect(9, $top + 12, 10, $b - $top - 11, $ink);
                $rect(10, $top + 13, 8, $b - $top - 13, $stone);
                $rect(15, $top + 13, 3, $b - $top - 13, $dark);
                $rect(7, $b - 3, 14, 3, $dark);
                // the great horn
                $poly([5, $top + 4, 14, $top + 9, 14, $top + 13, 4, $top + 11], $ink);
                $poly([6, $top + 5, 13, $top + 10, 13, $top + 12, 5, $top + 10], $gold);
                $oval(23, $top + 8, 8, 10, $ink);
                $oval(23, $top + 8, 6, 8, $gold);
                $oval(23, $top + 8, 3, 5, $c('#6a4a10'));
                $rect(13, $top + 9, 8, 3, $gold);
                $flagColor = $c('#c08a30');
                break;

            case 'palisade':
                $wood = $c('#9a7040');
                $dark = $c('#6a4a26');
                foreach ([3, 8, 13, 18, 23] as $i => $x) {
                    $h = $b - $top - 8 - ($i % 2) * 3;
                    $rect($x - 1, $b - $h, 5, $h, $ink);
                    $rect($x, $b - $h + 1, 3, $h - 1, $i % 2 ? $dark : $wood);
                    $poly([$x - 1, $b - $h, $x + 3, $b - $h, $x + 1, $b - $h - 4], $wood);
                }
                $rect(2, $b - 10, 24, 2, $dark);
                $flagColor = $c('#c08a30');
                break;

            case 'slingers':
                $grass = $c('#5a9a3a');
                $dark = $c('#3a6a26');
                $oval(14, $b - 4, 28, 30 + 3 * $level, $ink);
                $oval(14, $b - 4, 26, 28 + 3 * $level, $grass);
                $oval(18, $b - 2, 16, 18, $dark);
                $oval(14, $b - 6, 10, 11, $ink);
                $oval(14, $b - 6, 8, 9, $c('#3a7ac8'));
                $rect(16, $b - 7, 1, 1, $gold);
                $rect(4, $b - 14, 4, 3, $c('#ffe8a0'));
                $rect(20, $b - 16, 4, 3, $c('#ffe8a0'));
                $flagColor = $c('#d0704a');
                break;

            case 'traps':
                $wood = $c('#9a7040');
                $roof = $c('#6a8a3a');
                $rect(6, $top + 16, 16, $b - $top - 15, $ink);
                $rect(7, $top + 17, 14, $b - $top - 17, $wood);
                $poly([4, $top + 17, 24, $top + 17, 14, $top + 8], $roof);
                $rect(11, $b - 7, 6, 7, $c('#2a1a0a'));
                // a coil of rope and a jaw trap
                $oval(23, $b - 3, 8, 6, $c('#c8a870'));
                $oval(23, $b - 3, 4, 2, $c('#8a6a3a'));
                $rect(1, $b - 3, 6, 2, $c('#a0a0a8'));
                $rect(1, $b - 5, 1, 2, $c('#a0a0a8'));
                $rect(6, $b - 5, 1, 2, $c('#a0a0a8'));
                $flagColor = $c('#d0704a');
                break;

            case 'inn':
                $wall = $c('#f0e0c0');
                $beam = $c('#6a4220');
                $roof = $c('#b04a2a');
                $rect(4, $top + 12, 20, $b - $top - 11, $ink);
                $rect(5, $top + 13, 18, $b - $top - 13, $wall);
                imageline($img, 5, $top + 20, 22, $top + 20, $beam);
                imageline($img, 14, $top + 13, 14, $b, $beam);
                $poly([2, $top + 13, 26, $top + 13, 14, $top + 2], $ink);
                $poly([4, $top + 12, 24, $top + 12, 14, $top + 4], $roof);
                $rect(19, $top + 2, 3, 6, $c('#5a4a40'));
                $rect(8, $b - 8, 5, 8, $beam);
                $rect(16, $top + 15, 4, 3, $c('#ffd060'));
                // the hanging sign
                $rect(23, $top + 15, 4, 1, $beam);
                $rect(24, $top + 16, 3, 4, $gold);
                $flagColor = $c('#d0704a');
                break;

            case 'garden':
                $leaf = $c('#3a8a3a');
                $dark = $c('#245a24');
                $oval(14, $b - 9, 28, 20 + 3 * $level, $ink);
                $oval(8, $b - 8, 14, 16, $dark);
                $oval(20, $b - 9, 14, 18, $leaf);
                $oval(13, $b - 13 - $level, 14, 14 + 2 * $level, $leaf);
                foreach ([[6, 12], [20, 15], [11, 6], [17, 9]] as [$x, $y]) {
                    $rect($x, $b - $y - $level, 2, 2, $c(['#f080a0', '#f0e060', '#ffffff', '#a0a0ff'][($x + $y) % 4]));
                }
                // eyes in the leaves
                $rect(10, $b - 9, 2, 1, $c('#ffffff'));
                $rect(15, $b - 9, 2, 1, $c('#ffffff'));
                $flagColor = $c('#d0704a');
                break;

            case 'barracks':
                $wood = $c('#5a3a24');
                $dark = $c('#3a2414');
                $rect(3, $top + 13, 22, $b - $top - 12, $ink);
                $rect(4, $top + 14, 20, $b - $top - 14, $wood);
                for ($x = 7; $x < 24; $x += 5) {
                    $rect($x, $top + 14, 1, $b - $top - 14, $dark);
                }
                $poly([1, $top + 14, 27, $top + 14, 14, $top + 2], $ink);
                $poly([3, $top + 13, 25, $top + 13, 14, $top + 4], $c('#7a2a1a'));
                foreach ([4, 10, 18, 24] as $x) {
                    $poly([$x - 1, $top + 14, $x + 1, $top + 14, $x, $top + 9], $c('#d8d0c0'));
                }
                $rect(10, $b - 10, 8, 10, $c('#140808'));
                $oval(14, $top + 9, 6, 5, $c('#e8e8e0'));
                $rect(12, $top + 9, 1, 1, $ink);
                $rect(15, $top + 9, 1, 1, $ink);
                $flagColor = $c('#a02a20');
                break;

            case 'catapult':
                $wood = $c('#6a4a2a');
                $dark = $c('#3a2414');
                $rect(3, $b - 6, 22, 4, $ink);
                $rect(4, $b - 5, 20, 2, $wood);
                $oval(7, $b - 2, 6, 6, $dark);
                $oval(21, $b - 2, 6, 6, $dark);
                $poly([8, $b - 5, 12, $b - 5, 15, $top + 12, 13, $top + 12], $wood);
                $poly([20, $b - 5, 16, $b - 5, 14, $top + 12, 16, $top + 12], $dark);
                imageline($img, 14, $top + 14, 4, $top + 2, $wood);
                imageline($img, 15, $top + 14, 5, $top + 2, $wood);
                $oval(4, $top + 2, 8, 7, $ink);
                $oval(4, $top + 2, 6, 5, $c('#8a8a80'));
                $flagColor = $c('#a02a20');
                break;

            case 'pitch':
                $pot = $c('#2a2a30');
                $fire = $c('#ff7a20');
                $poly([5, $b, 9, $b - 6, 19, $b - 6, 23, $b], $fire);
                $poly([8, $b, 11, $b - 4, 17, $b - 4, 20, $b], $c('#ffd040'));
                $oval(14, $top + 18 + intdiv($level, 2), 24, 20, $ink);
                $oval(14, $top + 18 + intdiv($level, 2), 22, 18, $pot);
                $rect(3, $top + 10, 22, 4, $ink);
                $rect(4, $top + 11, 20, 2, $c('#4a4a52'));
                $oval(14, $top + 11, 18, 4, $c('#140c14'));
                $oval(10, $top + 9, 4, 3, $c('#3a2a3a'));
                $oval(17, $top + 7, 3, 3, $c('#3a2a3a'));
                $flagColor = $c('#a02a20');
                break;

            default: // drums
                $skin = $c('#e8d8b0');
                $body = $c('#8a2a1a');
                $wood = $c('#5a3a24');
                $poly([5, $b, 9, $b - 10, 19, $b - 10, 23, $b], $wood);
                $oval(14, $top + 16, 24, 10, $ink);
                $rect(2, $top + 16, 25, $b - $top - 26, $ink);
                $rect(3, $top + 16, 23, $b - $top - 26, $body);
                for ($x = 5; $x < 25; $x += 6) {
                    imageline($img, $x, $top + 16, $x + 3, $b - 11, $c('#e8c040'));
                }
                $oval(14, $top + 16, 22, 8, $skin);
                $rect(6, $top + 6, 2, 9, $wood);
                $rect(20, $top + 6, 2, 9, $wood);
                $oval(7, $top + 6, 4, 4, $skin);
                $oval(21, $top + 6, 4, 4, $skin);
                $flagColor = $c('#a02a20');
                break;
        }
        if ($level >= 2) {
            // a flag of the side
            $rect(25, $top + 2, 1, 10, $ink);
            $rect(26, $top + 2, 2, 4, $flagColor);
        }
        if ($level >= 3) {
            $rect(1, $b - 1, 3, 1, $gold);
            $rect(24, $b - 1, 3, 1, $gold);
        }
        if ($accent !== null) {
            $oval(26, $top + 9, 5, 5, $ink);
            $oval(26, $top + 9, 3, 3, $accent);
        }
    }
}
