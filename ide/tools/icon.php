<?php
// The IDE's icon: a rounded square, violet to blue, with "J>" in white pixel letters.
$s = 256;
$im = imagecreatetruecolor($s, $s);
imagesavealpha($im, true);
imagefill($im, 0, 0, imagecolorallocatealpha($im, 0, 0, 0, 127));
for ($y = 0; $y < $s; $y++) {
    for ($x = 0; $x < $s; $x++) {
        $r = 44; $dx = max(0, max($r - $x, $x - ($s - 1 - $r))); $dy = max(0, max($r - $y, $y - ($s - 1 - $r)));
        if ($dx * $dx + $dy * $dy > $r * $r) continue;
        $t = ($x + $y) / (2 * $s);
        imagesetpixel($im, $x, $y, imagecolorallocate($im, (int)(123 + (53 - 123) * $t), (int)(92 + (116 - 92) * $t), (int)(240)));
    }
}
$white = imagecolorallocate($im, 255, 255, 255);
$px = 16;
$glyph = ['....###.....', '.....#......', '.....#..#...', '.....#...#..', '.#...#....#.', '.#...#...#..', '..###...#...', '............'];
foreach ($glyph as $gy => $row) foreach (str_split($row) as $gx => $c) if ($c === '#') imagefilledrectangle($im, 32 + $gx * $px, 64 + $gy * $px, 32 + $gx * $px + $px - 1, 64 + $gy * $px + $px - 1, $white);
imagepng($im, $argv[1]);
