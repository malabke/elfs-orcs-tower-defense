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

// the picture shared by social networks (1200 x 630: the battle, x1.25, 630 rows kept) and the directory's picture (16:9)
$battle = load("$shots/site-battle-thornvale.png");
save(crop($battle, 0, 22, 960, 504, 1200, 630), "$images/og.png");
save($battle, "$game/assets/thumbnail.png");
// the page icon: the castle of the side, seen from above (a square cut of the right edge of the picture)
save(crop($battle, 790, 250, 170, 170, 64, 64), "$images/favicon.png");
save(crop($battle, 790, 250, 170, 170, 256, 256), "$game/assets/icon.png");

// the game's own cards (assets/sprites/cards/, drawn smoothed by Sprites::drawPicture()): each map seen in 3D for the
// map screen (the board under the top bar), each side's four towers for the side screen (tests/ModelShotsTest.php)
$cards = "$game/assets/sprites/cards";
@mkdir($cards, 0777, true);
foreach (['thornvale', 'mistfen', 'greystone', 'emberwood', 'frostpeak', 'lastgate'] as $map) {
    $out = imagecreatetruecolor(360, 180);
    // the board under the top bar, without the round buttons of its bottom left corner
    imagecopyresampled($out, load("$shots/site-battle-$map.png"), 0, 0, 60, 60, 360, 180, 840, 420);
    save($out, "$cards/map-$map.png");
}
foreach (['elves', 'dwarves', 'horsefolk', 'littlefolk', 'orcs'] as $side) {
    $frame = load("$shots/towers-$side.png");
    $spots = json_decode((string)@file_get_contents("$shots/towers-$side.json"), true);
    if (!is_array($spots) || count($spots) !== 4) {
        fwrite(STDERR, "missing $shots/towers-$side.json: run tests/ModelShotsTest.php first\n");
        exit(1);
    }
    // four pictures of 100 x 140 (the tower and a little of its platform), side by side
    $out = imagecreatetruecolor(4 * 100, 140);
    foreach ($spots as $i => [$sx, $sy]) {
        // ($sx, $sy): the middle of the platform on the ground; the tower stands over it
        imagecopyresampled($out, $frame, $i * 100, 0, (int)$sx - 50, (int)$sy - 106, 100, 140, 100, 140);
    }
    save($out, "$cards/side-$side.png");
}
