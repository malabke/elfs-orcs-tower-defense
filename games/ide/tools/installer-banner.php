<?php

// The installer's pictures (installer/install.yaml) from the IDE's icon: a 344 x 840 banner and the icon.
// bin/jumphp php games/ide tools/installer-banner.php

$dir = dirname(__DIR__);
$icon = imagecreatefrompng("$dir/assets/icon.png");
$w = 344;
$h = 840;
$banner = imagecreatetruecolor($w, $h);
for ($y = 0; $y < $h; $y++) {
    $t = $y / $h;
    $c = imagecolorallocate($banner, (int)(30 + 10 * $t), (int)(32 + 8 * $t), (int)(44 + 30 * $t));
    imageline($banner, 0, $y, $w, $y, $c);
}
imagealphablending($banner, true);
$size = 224;
imagecopyresampled($banner, $icon, (int)(($w - $size) / 2), 140, 0, 0, $size, $size, imagesx($icon), imagesy($icon));
$accent = imagecolorallocate($banner, 217, 119, 87);
imagefilledrectangle($banner, 60, 420, $w - 60, 425, $accent);
$white = imagecolorallocate($banner, 235, 235, 240);
foreach (['JumPHP', 'IDE'] as $i => $word) {
    $scale = 6;
    $text = imagecreatetruecolor(imagefontwidth(5) * strlen($word), imagefontheight(5));
    imagecolortransparent($text, imagecolorallocate($text, 0, 0, 0));
    imagestring($text, 5, 0, 0, $word, imagecolorallocate($text, 235, 235, 240));
    $tw = imagesx($text) * $scale;
    imagecopyresized($banner, $text, (int)(($w - $tw) / 2), 470 + $i * 110, 0, 0, $tw, imagesy($text) * $scale, imagesx($text), imagesy($text));
}
imagepng($banner, "$dir/installer/jumphp-ide-banner.png");
copy("$dir/assets/icon.png", "$dir/installer/jumphp-ide-icon.png");
echo "installer/jumphp-ide-banner.png\n";
