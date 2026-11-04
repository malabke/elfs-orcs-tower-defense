<?php

namespace App\Ui;

use JumPHP\Renderer\Renderer;

/**
 * The look of the interface, like the 3D battle: a storybook. Rounded panels of deep ink blue edged with gold,
 * wooden buttons, round medallions, and icons drawn with shapes (sharp at any size with the renderer's HD:
 * every edge is cut at the real pixels of the view texture). Colours are [r, g, b] or [r, g, b, a]: over the 3D
 * any colour can be translucent (the layer is composed by the 2D's alpha).
 */
final class Paint
{
    public const INK = [24, 30, 52, 232];
    public const INK_EDGE = [214, 176, 92];
    public const WOOD = [122, 80, 48];
    public const WOOD_LIGHT = [168, 118, 72];
    public const WOOD_DARK = [58, 36, 22];
    public const GOLD = [244, 204, 92];
    public const CREAM = [250, 238, 212];

    // ---- shapes -------------------------------------------------------------------------------------------------

    /** A rounded rectangle of one colour (a translucent one is even: no part is drawn twice). */
    public static function round(Renderer $r, float $x, float $y, float $w, float $h, float $radius, array|string $color): void
    {
        $rad = max(0.0, min($radius, $w / 2, $h / 2));
        if ($rad <= 0) {
            $r->fillRect($x, $y, $w, $h, $color);
            return;
        }
        $r->fillRect($x, $y + $rad, $w, $h - 2 * $rad, $color);
        // the top and bottom bands, row by row of real pixels: their ends follow the corners
        $k = max(1, $r->hd());
        $rows = (int)ceil($rad * $k);
        $row = $rad / $rows;
        for ($i = 0; $i < $rows; $i++) {
            $dy = $rad - ($i + 0.5) * $row;
            $inset = $rad - sqrt(max(0.0, $rad * $rad - $dy * $dy));
            $r->fillRect($x + $inset, $y + $i * $row, $w - 2 * $inset, $row, $color);
            $r->fillRect($x + $inset, $y + $h - ($i + 1) * $row, $w - 2 * $inset, $row, $color);
        }
    }

    /** A filled triangle (rows of real pixels). @param array{0: float, 1: float} $a */
    public static function triangle(Renderer $r, array $a, array $b, array $c, array|string $color): void
    {
        $pts = [$a, $b, $c];
        usort($pts, static fn (array $p, array $q) => $p[1] <=> $q[1]);
        [$p0, $p1, $p2] = $pts;
        $k = max(1, $r->hd());
        $step = 1 / $k;
        $edgeX = static function (array $u, array $v, float $y): float {
            $dy = $v[1] - $u[1];
            return abs($dy) < 1e-6 ? $u[0] : $u[0] + ($v[0] - $u[0]) * ($y - $u[1]) / $dy;
        };
        for ($y = floor($p0[1] * $k) / $k; $y < $p2[1]; $y += $step) {
            $mid = $y + $step / 2;
            if ($mid < $p0[1] || $mid > $p2[1]) {
                continue;
            }
            $xa = $edgeX($p0, $p2, $mid);
            $xb = $mid < $p1[1] ? $edgeX($p0, $p1, $mid) : $edgeX($p1, $p2, $mid);
            $r->fillRect(min($xa, $xb), $y, abs($xb - $xa), $step, $color);
        }
    }

    // ---- pieces of the interface ----------------------------------------------------------------------------------

    /** The background of the menus without 3D: an evening sky, deep blue above, warmer below, its edges darker. */
    public static function backdrop(Renderer $r, float $w, float $h): void
    {
        $steps = 24;
        for ($i = 0; $i < $steps; $i++) {
            $t = $i / ($steps - 1);
            $c = [(int)(30 + 26 * $t), (int)(38 + 8 * $t), (int)(70 - 26 * $t)];
            $r->fillRect(0, $h * $i / $steps, $w, $h / $steps + 0.5, $c);
        }
        foreach ([[0, 36], [6, 24], [12, 14]] as [$inset, $alpha]) {
            $r->fillRect(0, 0, $inset + 6, $h, [0, 0, 0, $alpha]);
            $r->fillRect($w - $inset - 6, 0, $inset + 6, $h, [0, 0, 0, $alpha]);
        }
    }

    /** A wooden header across the top ($h tall), a thread of $accent under it. */
    public static function header(Renderer $r, float $w, float $h, array|string $accent): void
    {
        $r->fillRect(0, $h, $w, 2, [0, 0, 0, 70]);
        $r->fillRect(0, 0, $w, $h, self::WOOD_DARK);
        $r->fillRect(0, 0, $w, $h - 1.5, [96, 62, 38]);
        $r->fillRect(0, 0, $w, $h * 0.42, [114, 76, 46]);
        $r->fillRect(0, 0.5, $w, 0.75, [255, 236, 200, 40]);
        $r->fillRect(0, $h - 1.5, $w, 1.5, $accent);
    }

    /** A panel: a soft shadow, a thin gold edge, deep ink inside (or $fill). */
    public static function panel(Renderer $r, float $x, float $y, float $w, float $h, array|string|null $edge = null, array|string|null $fill = null, float $radius = 4.0): void
    {
        self::round($r, $x + 1, $y + 2, $w, $h, $radius, [0, 0, 0, 80]);
        self::round($r, $x, $y, $w, $h, $radius, $edge ?? self::INK_EDGE);
        self::round($r, $x + 1, $y + 1, $w - 2, $h - 2, max(0.0, $radius - 1), $fill ?? self::INK);
        self::round($r, $x + 2, $y + 1.5, $w - 4, min(2.0, $h / 4), 1.0, [255, 255, 255, 18]);
    }

    /** A wooden button: darker edge, a lighter top, warm when the pointer is over it, grey when it cannot be pressed. */
    public static function button(Renderer $r, float $x, float $y, float $w, float $h, bool $enabled = true, bool $hover = false, bool $lit = false): void
    {
        $base = !$enabled ? [92, 84, 78] : ($hover ? [150, 104, 62] : self::WOOD);
        $top = !$enabled ? [112, 104, 98] : ($hover ? [196, 146, 92] : self::WOOD_LIGHT);
        self::round($r, $x + 0.5, $y + 1.5, $w, $h, 4, [0, 0, 0, 90]);
        self::round($r, $x, $y, $w, $h, 4, $lit ? self::GOLD : self::WOOD_DARK);
        self::round($r, $x + 1, $y + 1, $w - 2, $h - 2, 3, $base);
        self::round($r, $x + 1.5, $y + 1.5, $w - 3, ($h - 3) * 0.45, 2.5, $top);
        self::round($r, $x + 3, $y + 2, $w - 6, 1.0, 0.5, [255, 255, 255, 40]);
    }

    /** A round medallion (map buttons, ring menu): a gold ring when chosen. */
    public static function medallion(Renderer $r, float $cx, float $cy, float $radius, bool $chosen = false, bool $enabled = true): void
    {
        $r->fillCircle($cx + 0.5, $cy + 1.5, $radius + 0.5, [0, 0, 0, 90]);
        $r->fillCircle($cx, $cy, $radius, $chosen ? self::GOLD : self::WOOD_DARK);
        $r->fillCircle($cx, $cy, $radius - 1.5, $enabled ? [52, 62, 92] : [70, 66, 70]);
        $r->fillCircle($cx, $cy - $radius * 0.18, $radius - 3.2, $enabled ? [70, 84, 120] : [86, 82, 86]);
    }

    /** The top $part (0..1) of a disc, in $color (what is left to wait, over a round button). */
    public static function discTop(Renderer $r, float $cx, float $cy, float $radius, float $part, array|string $color): void
    {
        $part = max(0.0, min(1.0, $part));
        if ($part <= 0) {
            return;
        }
        $k = max(1, $r->hd());
        $rows = (int)ceil(2 * $radius * $k * $part);
        for ($i = 0; $i < $rows; $i++) {
            $y = -$radius + $i / $k;
            $dy = $y + 0.5 / $k;
            $dx = sqrt(max(0.0, $radius * $radius - $dy * $dy));
            $r->fillRect($cx - $dx, $cy + $y, 2 * $dx, 1 / $k, $color);
        }
    }

    /** A thin bar (time left, health): its back, its part filled. */
    public static function bar(Renderer $r, float $x, float $y, float $w, float $h, float $ratio, array|string $color): void
    {
        self::round($r, $x - 0.5, $y - 0.5, $w + 1, $h + 1, ($h + 1) / 2, [20, 14, 12, 220]);
        if ($ratio > 0) {
            self::round($r, $x, $y, $w * max(0.0, min(1.0, $ratio)), $h, $h / 2, $color);
        }
    }

    // ---- icons ------------------------------------------------------------------------------------------------------

    /** An icon drawn with shapes, centred on ($cx, $cy), about $size wide. */
    public static function icon(Renderer $r, string $name, float $cx, float $cy, float $size = 10.0): void
    {
        $s = $size / 10;
        switch ($name) {
            case 'heart':
                $red = [236, 76, 92];
                $r->fillCircle($cx - 2.3 * $s, $cy - 1.4 * $s, 2.8 * $s, $red);
                $r->fillCircle($cx + 2.3 * $s, $cy - 1.4 * $s, 2.8 * $s, $red);
                self::triangle($r, [$cx - 5.0 * $s, $cy - 0.6 * $s], [$cx + 5.0 * $s, $cy - 0.6 * $s], [$cx, $cy + 4.8 * $s], $red);
                $r->fillCircle($cx - 2.8 * $s, $cy - 2.4 * $s, 1.0 * $s, [255, 190, 196]);
                break;
            case 'coin':
                $r->fillCircle($cx, $cy, 4.8 * $s, [178, 120, 28]);
                $r->fillCircle($cx, $cy, 3.9 * $s, [250, 204, 72]);
                $r->fillCircle($cx, $cy, 2.6 * $s, [228, 172, 44]);
                $r->fillRect($cx - 0.6 * $s, $cy - 1.8 * $s, 1.2 * $s, 3.6 * $s, [250, 214, 96]);
                $r->fillCircle($cx - 1.9 * $s, $cy - 2.0 * $s, 0.9 * $s, [255, 244, 190]);
                break;
            case 'flag':
                $r->fillRect($cx - 3.6 * $s, $cy - 5.0 * $s, 1.2 * $s, 10.0 * $s, [226, 214, 196]);
                self::triangle($r, [$cx - 2.4 * $s, $cy - 5.0 * $s], [$cx + 4.6 * $s, $cy - 2.6 * $s], [$cx - 2.4 * $s, $cy - 0.2 * $s], [222, 70, 60]);
                self::triangle($r, [$cx - 2.4 * $s, $cy - 5.0 * $s], [$cx + 4.6 * $s, $cy - 2.6 * $s], [$cx - 2.4 * $s, $cy - 3.4 * $s], [244, 112, 96]);
                break;
            case 'skull':
                $bone = [240, 234, 220];
                $r->fillCircle($cx, $cy - 1.0 * $s, 4.2 * $s, $bone);
                self::round($r, $cx - 2.6 * $s, $cy + 1.6 * $s, 5.2 * $s, 3.0 * $s, 1.0 * $s, $bone);
                $r->fillCircle($cx - 1.6 * $s, $cy - 0.8 * $s, 1.2 * $s, [40, 30, 40]);
                $r->fillCircle($cx + 1.6 * $s, $cy - 0.8 * $s, 1.2 * $s, [40, 30, 40]);
                $r->fillRect($cx - 0.4 * $s, $cy + 2.2 * $s, 0.8 * $s, 1.6 * $s, [120, 110, 110]);
                break;
            case 'pause':
                self::round($r, $cx - 3.4 * $s, $cy - 4.0 * $s, 2.4 * $s, 8.0 * $s, 1.0 * $s, self::CREAM);
                self::round($r, $cx + 1.0 * $s, $cy - 4.0 * $s, 2.4 * $s, 8.0 * $s, 1.0 * $s, self::CREAM);
                break;
            case 'play':
                self::triangle($r, [$cx - 2.8 * $s, $cy - 4.0 * $s], [$cx + 3.6 * $s, $cy], [$cx - 2.8 * $s, $cy + 4.0 * $s], self::CREAM);
                break;
            case 'star':
            case 'star_off':
                $fill = $name === 'star' ? [252, 210, 72] : [86, 82, 96];
                $light = $name === 'star' ? [255, 238, 160] : [110, 106, 120];
                self::star($r, $cx, $cy + 0.3 * $s, 5.0 * $s, 2.1 * $s, $fill);
                self::star($r, $cx - 0.4 * $s, $cy - 0.3 * $s, 2.6 * $s, 1.1 * $s, $light);
                break;
            case 'up':
                self::triangle($r, [$cx, $cy - 4.6 * $s], [$cx + 4.4 * $s, $cy + 0.4 * $s], [$cx - 4.4 * $s, $cy + 0.4 * $s], [140, 230, 120]);
                $r->fillRect($cx - 1.6 * $s, $cy, 3.2 * $s, 4.4 * $s, [140, 230, 120]);
                break;
            case 'target':
                $r->fillCircle($cx, $cy, 4.6 * $s, [236, 90, 80]);
                $r->fillCircle($cx, $cy, 3.2 * $s, [250, 240, 230]);
                $r->fillCircle($cx, $cy, 1.8 * $s, [236, 90, 80]);
                $r->fillCircle($cx, $cy, 0.7 * $s, [250, 240, 230]);
                break;
            case 'sell':
                self::icon($r, 'coin', $cx + 0.8 * $s, $cy + 0.8 * $s, $size * 0.8);
                $r->fillRect($cx - 5.0 * $s, $cy - 4.2 * $s, 4.6 * $s, 1.6 * $s, [160, 240, 140]);
                break;
            case 'lock':
                $r->fillCircle($cx, $cy - 1.8 * $s, 2.8 * $s, [200, 200, 210]);
                $r->fillCircle($cx, $cy - 1.8 * $s, 1.6 * $s, [60, 56, 70]);
                self::round($r, $cx - 3.8 * $s, $cy - 1.0 * $s, 7.6 * $s, 5.8 * $s, 1.2 * $s, [236, 190, 72]);
                $r->fillCircle($cx, $cy + 1.4 * $s, 0.9 * $s, [90, 60, 20]);
                break;
            default:
                $r->fillCircle($cx, $cy, 4.0 * $s, self::CREAM);
        }
    }

    /** A five-pointed star of outer radius $ro, inner $ri. */
    private static function star(Renderer $r, float $cx, float $cy, float $ro, float $ri, array|string $color): void
    {
        $points = [];
        for ($i = 0; $i < 10; $i++) {
            $a = -M_PI / 2 + $i * M_PI / 5;
            $rad = $i % 2 === 0 ? $ro : $ri;
            $points[] = [$cx + cos($a) * $rad, $cy + sin($a) * $rad];
        }
        for ($i = 0; $i < 10; $i++) {
            self::triangle($r, [$cx, $cy], $points[$i], $points[($i + 1) % 10], $color);
        }
    }
}
