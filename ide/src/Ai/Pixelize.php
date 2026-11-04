<?php

namespace JumPHP\Ide\Ai;

/** A big picture made pixel art: scaled down to the sprite's size, at most 16 colours, see-through pixels kept. */
final class Pixelize
{
    private const CHARS = 'abcdefghijklmnop';

    /** @return array{palette: array<string, string>, frames: list<list<string>>} what ResourceGenerator::validate('sprite') reads */
    public static function sprite(string $picture, int $width, int $height, int $colours = 16): array
    {
        if (!function_exists('imagecreatefromstring')) {
            throw new \RuntimeException('This PHP has no GD: pictures cannot be made pixel art.');
        }
        $src = @imagecreatefromstring($picture);
        if ($src === false) {
            throw new \RuntimeException('The service\'s answer is not a picture.');
        }
        $small = imagecreatetruecolor($width, $height);
        imagealphablending($small, false);
        imagesavealpha($small, true);
        imagefill($small, 0, 0, imagecolorallocatealpha($small, 0, 0, 0, 127));
        imagecopyresampled($small, $src, 0, 0, 0, 0, $width, $height, imagesx($src), imagesy($src));
        $solid = [];
        for ($y = 0; $y < $height; $y++) {
            for ($x = 0; $x < $width; $x++) {
                $c = imagecolorat($small, $x, $y);
                $solid[$y][$x] = (($c >> 24) & 0x7f) < 64 ? $c & 0xffffff : null;
            }
        }
        // the colours of the opaque pixels reduced to $colours (GD's quantizer on an opaque copy)
        $opaque = imagecreatetruecolor($width, $height);
        for ($y = 0; $y < $height; $y++) {
            for ($x = 0; $x < $width; $x++) {
                imagesetpixel($opaque, $x, $y, $solid[$y][$x] ?? 0);
            }
        }
        imagetruecolortopalette($opaque, false, max(1, min(16, $colours)));
        $palette = [];
        $index = [];
        $frame = [];
        for ($y = 0; $y < $height; $y++) {
            $row = '';
            for ($x = 0; $x < $width; $x++) {
                if ($solid[$y][$x] === null) {
                    $row .= '.';
                    continue;
                }
                $i = imagecolorat($opaque, $x, $y);
                if (!isset($index[$i])) {
                    $rgb = imagecolorsforindex($opaque, $i);
                    $index[$i] = self::CHARS[count($index)] ?? 'p';
                    $palette[$index[$i]] = sprintf('#%02x%02x%02x', $rgb['red'], $rgb['green'], $rgb['blue']);
                }
                $row .= $index[$i];
            }
            $frame[] = $row;
        }
        if (!$palette) {
            throw new \RuntimeException('The picture is fully transparent at this size.');
        }
        return ['palette' => $palette, 'frames' => [$frame]];
    }
}
