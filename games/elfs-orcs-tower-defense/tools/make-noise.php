<?php

/**
 * Writes assets/textures/noise.png: 128 x 128 tileable value noise, one pattern per channel, used by the 3D shader
 * (src/Ui/World3d.php) to paint the ground and the sky's shadows:
 *   R  big soft patches (4 cells across)      G  middle patches (8 cells)
 *   B  fine grain (32 cells)                   A  clouds (2 cells, 4 octaves)
 * Run it again only to change the patterns: bin/jumphp php games/elfs-orcs-tower-defense tools/make-noise.php
 */

const SIZE = 128;

/** Random values on a period x period lattice that wraps around. @return list<float> */
function lattice(int $period, int $seed): array
{
    mt_srand($seed);
    $g = [];
    for ($i = 0; $i < $period * $period; $i++) {
        $g[] = mt_rand() / mt_getrandmax();
    }
    return $g;
}

/** Smooth value noise at (x, y) in texels, the lattice repeating every SIZE texels. */
function noise(array $g, int $period, float $x, float $y): float
{
    $cell = SIZE / $period;
    $gx = $x / $cell;
    $gy = $y / $cell;
    $x0 = (int)floor($gx);
    $y0 = (int)floor($gy);
    $fx = $gx - $x0;
    $fy = $gy - $y0;
    $sx = $fx * $fx * $fx * ($fx * ($fx * 6 - 15) + 10);
    $sy = $fy * $fy * $fy * ($fy * ($fy * 6 - 15) + 10);
    $at = static fn (int $i, int $j): float => $g[(($j % $period + $period) % $period) * $period + (($i % $period + $period) % $period)];
    $a = $at($x0, $y0) + ($at($x0 + 1, $y0) - $at($x0, $y0)) * $sx;
    $b = $at($x0, $y0 + 1) + ($at($x0 + 1, $y0 + 1) - $at($x0, $y0 + 1)) * $sx;
    return $a + ($b - $a) * $sy;
}

/** @param array<int, float> $octaves period => weight @return list<float> SIZE x SIZE values stretched to 0..1 */
function channel(array $octaves, int $seed): array
{
    $lattices = [];
    foreach ($octaves as $period => $weight) {
        $lattices[$period] = lattice($period, $seed + $period);
    }
    $out = [];
    for ($y = 0; $y < SIZE; $y++) {
        for ($x = 0; $x < SIZE; $x++) {
            $v = 0.0;
            foreach ($octaves as $period => $weight) {
                $v += $weight * noise($lattices[$period], $period, $x, $y);
            }
            $out[] = $v;
        }
    }
    $lo = min($out);
    $hi = max($out);
    return array_map(static fn (float $v) => ($v - $lo) / (($hi - $lo) ?: 1.0), $out);
}

$r = channel([4 => 0.6, 8 => 0.28, 16 => 0.12], 11);
$g = channel([8 => 0.55, 16 => 0.3, 32 => 0.15], 23);
$b = channel([32 => 0.65, 64 => 0.35], 37);
$a = channel([2 => 0.5, 4 => 0.26, 8 => 0.15, 16 => 0.09], 41);

$img = imagecreatetruecolor(SIZE, SIZE);
imagealphablending($img, false);
imagesavealpha($img, true);
for ($y = 0; $y < SIZE; $y++) {
    for ($x = 0; $x < SIZE; $x++) {
        $i = $y * SIZE + $x;
        $alpha = 127 - (int)round($a[$i] * 127);      // GD: 0 = opaque
        imagesetpixel($img, $x, $y, imagecolorallocatealpha($img, (int)round($r[$i] * 255), (int)round($g[$i] * 255), (int)round($b[$i] * 255), $alpha));
    }
}
$dir = dirname(__DIR__) . '/assets/textures';
@mkdir($dir, 0777, true);
imagepng($img, "$dir/noise.png", 9);
echo "assets/textures/noise.png ", SIZE, 'x', SIZE, "\n";
