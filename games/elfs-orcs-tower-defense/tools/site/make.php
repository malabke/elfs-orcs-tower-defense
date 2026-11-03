<?php

/**
 * Cuts the frames of tests/SiteShotsTest.php (var/screenshots/tests/site-*.png) into the pictures of the web site
 * (site/images/), the directory's thumbnail and the page icon (assets/). Run it after a change of look:
 *   bin/jumphp test elfs-orcs-tower-defense
 *   bin/jumphp php games/elfs-orcs-tower-defense tools/site/make.php
 * Frames are 960 x 540 (the game at x2): copied as they are, crops scaled with nearest neighbour (no smoothing).
 */

$game = dirname(__DIR__, 2);
$shots = "$game/var/screenshots/tests";
$images = "$game/site/images";
@mkdir($images, 0777, true);

function load(string $file): GdImage
{
    $img = is_file($file) ? imagecreatefrompng($file) : false;
    if ($img === false) {
        fwrite(STDERR, "missing $file: run bin/jumphp test first\n");
        exit(1);
    }
    return $img;
}

/** A part of $src, scaled to $w x $h without smoothing. */
function crop(GdImage $src, int $sx, int $sy, int $sw, int $sh, int $w, int $h): GdImage
{
    $out = imagecreatetruecolor($w, $h);
    imagecopyresized($out, $src, 0, 0, $sx, $sy, $w, $h, $sw, $sh);
    return $out;
}

function save(GdImage $img, string $file): void
{
    imagepng($img, $file, 9);
    echo str_replace(dirname(__DIR__, 2) . '/', '', $file), ' ', imagesx($img), 'x', imagesy($img), "\n";
}

$names = ['title', 'sides', 'maps', 'battle-thornvale', 'battle-greystone', 'battle-mistfen', 'battle-emberwood', 'battle-frostpeak', 'battle-lastgate'];
foreach ($names as $name) {
    save(load("$shots/site-$name.png"), "$images/$name.png");
}

$title = load("$shots/site-title.png");
// the picture shared by social networks: 1200 x 630 from the title (x1.25, 630 rows kept)
$og = crop($title, 0, 17, 960, 504, 1200, 630);
save($og, "$images/og.png");
// the directory's picture (16:9) and the page icon (the castle)
save($title, "$game/assets/thumbnail.png");
$castleFrame = load("$shots/site-battle-thornvale.png");
save(crop($castleFrame, 830, 320, 130, 130, 64, 64), "$images/favicon.png");
save(crop($castleFrame, 830, 320, 130, 130, 256, 256), "$game/assets/icon.png");
