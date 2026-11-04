<?php

namespace App\View3d;

use App\Td\Path;
use App\Ui\Sprites;

/**
 * The land of a map in 3D, built once: the painted ground (one mesh with gentle hills outside the board, coloured
 * by the shader from the GroundMap texture: grass, the road, water, soft shadows), the forest around it, the trees,
 * bushes, grass tufts, flowers and rocks of the board, the floating specks of light, the stone building platforms and
 * the castle in the side's colours. Coordinates are the map's: x, z in pixels of the 480 x 240 play area, y up; the
 * board itself is flat (y = 0), what the camera picks with a click.
 */
final class MapModel
{
    public const ROAD = 13.0;
    /** the platform's top above the ground */
    public const PLATFORM = 3.2;
    /** what the ground (and its texture) covers: x, z, width, height */
    public const RECT = [-128.0, -96.0, 768.0, 384.0];
    /** towards the sun (low in the west, a little behind the camera): long soft shadows to the east */
    public const SUN = [-0.62, 0.74, 0.16];

    /**
     * The painted colours of each theme: grass (deep, light, sunny tips), dirt (light, dark), water (deep, light),
     * leaves (shade, middle, sunny), trunk, rock (dark, light), moss, flowers, the haze of the distance, trees.
     */
    public const PALETTES = [
        'meadow' => ['grass' => ['#467f34', '#76ab44', '#b8d468'], 'dirt' => ['#dcc394', '#ad8a5c'], 'water' => ['#2c6a80', '#9ad8d4'],
            'leaves' => ['#28582f', '#5a993b', '#b6d85e'], 'trunk' => '#6b4b33', 'rock' => ['#85827a', '#d2ccbe'], 'moss' => '#88ac4e',
            'flowers' => ['#ffffff', '#f7e37a', '#f4a6ba', '#c4b8f4'], 'haze' => '#cfe0e8', 'trees' => 'round'],
        'marsh' => ['grass' => ['#3a6c44', '#5c9052', '#9fc26e'], 'dirt' => ['#a08e66', '#6e5f42'], 'water' => ['#28585e', '#78b0a6'],
            'leaves' => ['#1d4636', '#3c784e', '#8aba68'], 'trunk' => '#4e3c2c', 'rock' => ['#6c6e66', '#aaac9e'], 'moss' => '#6c9c52',
            'flowers' => ['#ffffff', '#e8e08a', '#b8d0f0', '#f0c0d0'], 'haze' => '#c2d4d2', 'trees' => 'mixed'],
        'quarry' => ['grass' => ['#8c8650', '#b0a66a', '#d8ce8e'], 'dirt' => ['#e4d8ba', '#b2a482'], 'water' => ['#3a6888', '#8cbad2'],
            'leaves' => ['#38582f', '#688a42', '#b4c872'], 'trunk' => '#6a5038', 'rock' => ['#968e7e', '#e0d8c4'], 'moss' => '#9ca462',
            'flowers' => ['#ffffff', '#f4d870', '#e8a0a0', '#d0c0f0'], 'haze' => '#e2dccb', 'trees' => 'round'],
        'autumn' => ['grass' => ['#7a7834', '#a89c48', '#d8c46c'], 'dirt' => ['#d4ac74', '#9c7446'], 'water' => ['#2c5a84', '#88b6d8'],
            'leaves' => ['#86321a', '#d26a26', '#f6bc4a'], 'trunk' => '#5a3c28', 'rock' => ['#888076', '#cabeae'], 'moss' => '#9c9c4c',
            'flowers' => ['#ffffff', '#f6d468', '#f0906a', '#e0b0d0'], 'haze' => '#eadcc4', 'trees' => 'round'],
        'snow' => ['grass' => ['#bccadc', '#e2eaf4', '#ffffff'], 'dirt' => ['#aeaab0', '#7e7c86'], 'water' => ['#48789f', '#c2e2f2'],
            'leaves' => ['#1c423e', '#2e5e56', '#eef4fa'], 'trunk' => '#4a3a32', 'rock' => ['#7a7e88', '#c6ced8'], 'moss' => '#eef2f8',
            'flowers' => ['#ffffff', '#d8e8ff', '#ffffff', '#c8d8f0'], 'haze' => '#dde8f2', 'trees' => 'pine'],
        'ash' => ['grass' => ['#47413b', '#625a50', '#8a7c6a'], 'dirt' => ['#90705a', '#5c4234'], 'water' => ['#c03c0c', '#ffc050'],
            'leaves' => ['#2a2420', '#4a3c30', '#7a6650'], 'trunk' => '#2e241e', 'rock' => ['#4c4642', '#8c847c'], 'moss' => '#6a5e52',
            'flowers' => ['#ffb060', '#ff8040', '#ffd080', '#ff6030'], 'haze' => '#b8aaa0', 'trees' => 'bare'],
    ];

    /**
     * @return array{mesh: Mesh, fog: array{0: float, 1: float, 2: float}, ground: string, palette: array<string, array{0: float, 1: float, 2: float}>, lava: bool}
     */
    public static function build(array $map, array $colors): array
    {
        $theme = (string)($map['theme'] ?? 'meadow');
        $pal = self::PALETTES[$theme] ?? self::PALETTES['meadow'];
        $paths = array_map(static fn (array $p) => Path::fromTiles($p), $map['paths']);
        $roadDistance = static function (float $x, float $z) use ($paths): float {
            $best = INF;
            foreach ($paths as $p) {
                $best = min($best, $p->distanceTo($x, $z));
            }
            return $best;
        };
        [$bx, $by] = $map['bastion'];
        $castle = [$bx * 16.0, $by * 16.0];

        // what the ground is made of, then its shadows
        $ground = new GroundMap();
        foreach ($paths as $path) {
            $pts = self::roadPoints($path);
            for ($i = 1, $n = count($pts); $i < $n; $i++) {
                $ground->segment(GroundMap::ROAD, $pts[$i - 1][0], $pts[$i - 1][1], $pts[$i][0], $pts[$i][1], 2 * self::ROAD);
            }
        }
        $rows = Sprites::sceneryRows($map, (string)($map['name'] ?? 'map'));
        foreach ($rows as $tz => $row) {
            for ($tx = 0, $n = strlen($row); $tx < $n; $tx++) {
                if ($row[$tx] === 'w' && $roadDistance($tx * 16 + 8, $tz * 16 + 8) > 18) {
                    $ground->spot(GroundMap::WATER, $tx * 16 + 8.0 + (Mesh::hash($tx, $tz, 21) - 0.5) * 3, $tz * 16 + 8.0 + (Mesh::hash($tx, $tz, 22) - 0.5) * 3,
                        14.0 + 2.0 * Mesh::hash($tx, $tz, 23));
                }
            }
        }

        $m = new Mesh();
        self::terrain($m, $roadDistance);

        // keep the scenery off the platforms and the castle
        $blocked = [];
        foreach ($map['slots'] as [$sx, $sy]) {
            for ($dy = -1; $dy < 3; $dy++) {
                for ($dx = -1; $dx < 3; $dx++) {
                    $blocked[($sx + $dx) . ',' . ($sy + $dy)] = true;
                }
            }
        }
        for ($dy = -1; $dy < 7; $dy++) {
            for ($dx = -1; $dx < 5; $dx++) {
                $blocked[($bx + $dx) . ',' . ($by + $dy)] = true;
            }
        }
        $free = static function (float $x, float $z) use ($blocked, $castle): bool {
            if ($x > $castle[0] - 10 && $x < $castle[0] + 80 && $z > $castle[1] - 14 && $z < $castle[1] + 110) {
                return false;
            }
            return !isset($blocked[(int)floor($x / 16) . ',' . (int)floor($z / 16)]);
        };

        // trees: those of the board, then the forest all around it
        $variants = self::treeVariants($pal, false);
        $farVariants = self::treeVariants($pal, true);
        $plant = static function (float $x, float $z, int $h, float $scale, bool $far = false) use ($m, $ground, $variants, $farVariants, $roadDistance): void {
            $set = $far ? $farVariants : $variants;
            $tree = $set[abs($h) % count($set)];
            $y = self::height($x, $z, $roadDistance);
            $tint = 0.9 + 0.2 * Mesh::hash($h, 7);
            $m->add($tree['mesh'], [$x, $y - 0.4, $z], $scale, Mesh::hash($h, 3) * 6.283, [$tint, $tint * (0.97 + 0.06 * Mesh::hash($h, 5)), $tint]);
            self::castShadow($ground, $x, $z, $tree, $scale);
        };
        foreach ($rows as $tz => $row) {
            for ($tx = 0, $n = strlen($row); $tx < $n; $tx++) {
                $cx = $tx * 16 + 8 + (Mesh::hash($tx, $tz, 1) - 0.5) * 7;
                $cz = $tz * 16 + 8 + (Mesh::hash($tx, $tz, 2) - 0.5) * 7;
                if ($row[$tx] !== 't' || !$free($cx, $cz) || $roadDistance($cx, $cz) < 21) {
                    continue;
                }
                $plant($cx, $cz, $tx * 31 + $tz * 17, 0.9 + 0.4 * Mesh::hash($tx, $tz, 3));
            }
        }
        // the forest: three rows beyond the far edge, a few trees beside the board (the camera sees no more of it)
        for ($gz = -70.0; $gz < 30.0; $gz += 23.0) {
            for ($gx = -58.0; $gx < 545.0; $gx += 23.0) {
                $x = $gx + (Mesh::hash((int)$gx, (int)$gz, 1) - 0.5) * 16;
                $z = $gz + (Mesh::hash((int)$gx, (int)$gz, 2) - 0.5) * 16;
                $out = self::outside($x, $z);
                if ($out < 9 || ($z > -4 && $x > -4 && $x < 484) || Mesh::hash((int)$gx, (int)$gz, 4) > 0.78 || !$free($x, $z) || $roadDistance($x, $z) < 30) {
                    continue;
                }
                $plant($x, $z, (int)($gx * 7 + $gz * 3), 1.1 + 0.45 * Mesh::hash((int)$gx, (int)$gz, 5), $z < -30);
            }
        }

        self::smallThings($m, $ground, $rows, $pal, $free, $roadDistance, $paths);
        self::platforms($m, $ground, $map['slots'], $colors, $pal);
        self::castle($m, $ground, $castle[0], $castle[1], $colors);
        self::motes($m, $pal);

        $c = static fn (int $i, string $k): array => Mesh::rgb($pal[$k][$i]);
        return [
            'mesh' => $m,
            'fog' => Mesh::rgb($pal['haze']),
            'ground' => $ground->rgba(),
            'palette' => [
                'u_grass0' => $c(0, 'grass'), 'u_grass1' => $c(1, 'grass'), 'u_grass2' => $c(2, 'grass'),
                'u_dirt0' => $c(0, 'dirt'), 'u_dirt1' => $c(1, 'dirt'),
                'u_water0' => $c(0, 'water'), 'u_water1' => $c(1, 'water'),
            ],
            'lava' => $theme === 'ash',
        ];
    }

    /** How high the land is: flat on the board, gentle hills around it (none along the roads). */
    public static function height(float $x, float $z, ?callable $roadDistance = null): float
    {
        $out = self::outside($x, $z);
        if ($out <= 4.0) {
            return 0.0;
        }
        $t = min(1.0, ($out - 4.0) / 80.0);
        $hill = 0.5 + 0.28 * sin($x * 0.021 + 1.3) * cos($z * 0.017 - 0.4) + 0.22 * sin($x * 0.047 - $z * 0.031 + 2.1);
        $h = $t * $t * (3 - 2 * $t) * (6.0 + 30.0 * $hill) * ($z > 240 ? 0.35 : 1.0);
        if ($roadDistance !== null) {
            $d = max(0.0, min(1.0, ($roadDistance($x, $z) - 24.0) / 50.0));
            $h *= $d * $d * (3 - 2 * $d);
        }
        return $h;
    }

    /** How far a point is outside the board (0 on it). */
    private static function outside(float $x, float $z): float
    {
        return hypot(max(0.0, -$x, $x - 480.0), max(0.0, -$z, $z - 240.0));
    }

    /** The points of a road, run on past the board where it comes in. @return list<array{0: float, 1: float}> */
    private static function roadPoints(Path $path): array
    {
        $pts = $path->points;
        [$fx, $fz] = $pts[0];
        $dx = $pts[1][0] - $fx;
        $dz = $pts[1][1] - $fz;
        $len = hypot($dx, $dz) ?: 1.0;
        array_unshift($pts, [$fx - $dx / $len * 120, $fz - $dz / $len * 120]);
        return $pts;
    }

    /** The ground: one sheet of 16 px squares, its hills, painted by the shader (material GROUND). */
    private static function terrain(Mesh $m, callable $roadDistance): void
    {
        [$x0, $z0, $w, $h] = self::RECT;
        $step = 16.0;
        $nx = (int)($w / $step);
        $nz = (int)($h / $step);
        $y = [];
        for ($j = 0; $j <= $nz + 1; $j++) {
            for ($i = 0; $i <= $nx + 1; $i++) {
                $y[$j][$i] = self::height($x0 + ($i - 0.5) * $step, $z0 + ($j - 0.5) * $step, $roadDistance);
            }
        }
        $at = static function (int $i, int $j) use ($y, $x0, $z0, $step): array {
            // the corner (i, j) sits between the samples (i, j) and (i + 1, j + 1)
            $hy = ($y[$j][$i] + $y[$j][$i + 1] + $y[$j + 1][$i] + $y[$j + 1][$i + 1]) / 4;
            $gx = ($y[$j][$i + 1] + $y[$j + 1][$i + 1] - $y[$j][$i] - $y[$j + 1][$i]) / (2 * $step);
            $gz = ($y[$j + 1][$i] + $y[$j + 1][$i + 1] - $y[$j][$i] - $y[$j][$i + 1]) / (2 * $step);
            $len = sqrt($gx * $gx + 1 + $gz * $gz);
            return [[$x0 + $i * $step, $hy, $z0 + $j * $step], [-$gx / $len, 1 / $len, -$gz / $len]];
        };
        $m->material(Mesh::GROUND);
        $white = [1.0, 1.0, 1.0];
        for ($j = 0; $j < $nz; $j++) {
            for ($i = 0; $i < $nx; $i++) {
                [$p0, $n0] = $at($i, $j);
                [$p1, $n1] = $at($i + 1, $j);
                [$p2, $n2] = $at($i + 1, $j + 1);
                [$p3, $n3] = $at($i, $j + 1);
                $m->quad([$p0, $p1, $p2, $p3], [$n0, $n1, $n2, $n3], [$white, $white, $white, $white]);
            }
        }
        // far away, beyond the hills
        $m->material(Mesh::SOLID);
        $m->floor($x0 - 2400, $z0 - 2400, $x0 + $w + 2400, $z0 + $h + 2400, -1.0, [0.42, 0.56, 0.34]);
    }

    /** A soft shadow on the ground for a tree (to the east of it), and the darker ground at its foot. */
    private static function castShadow(GroundMap $g, float $x, float $z, array $tree, float $scale): void
    {
        [$sx, $sz, $k] = self::shadowStep();
        $r = $tree['r'] * $scale;
        $h = $tree['h'] * $scale;
        if ($tree['kind'] === 'pine') {
            foreach ([[0.35, 0.95], [0.6, 0.75], [0.85, 0.5], [1.1, 0.25]] as [$f, $rf]) {
                $g->darken(GroundMap::SUN, $x + $sx * $h * $k * $f, $z + $sz * $h * $k * $f, $r * $rf + 2.0, 0.62, 0.25);
            }
        } else {
            $g->darken(GroundMap::SUN, $x + $sx * $h * $k, $z + $sz * $h * $k, $r, 0.6, 0.3);
            foreach ([0.25, 0.5, 0.75] as $f) {
                $g->darken(GroundMap::SUN, $x + $sx * $h * $k * $f * 0.8, $z + $sz * $h * $k * $f * 0.8, 1.8 * $scale, 0.45, 0.2);
            }
        }
        $g->darken(GroundMap::OPEN, $x, $z, 9.0 * $scale, 0.42, 0.15);
    }

    /** Where a shadow falls: [x per unit, z per unit, length per unit of height]. @return array{0: float, 1: float, 2: float} */
    public static function shadowStep(): array
    {
        [$lx, $ly, $lz] = self::SUN;
        $len = hypot($lx, $lz);
        return [-$lx / $len, -$lz / $len, $len / $ly];
    }

    /**
     * A few trees to place with add(); $far: lighter ones for the forest far away (fewer, coarser lumps).
     * @return list<array{mesh: Mesh, r: float, h: float, kind: string}>
     */
    private static function treeVariants(array $pal, bool $far): array
    {
        $kind = $pal['trees'];
        $out = [];
        for ($k = 0; $k < ($far ? 3 : 5); $k++) {
            $shape = match ($kind) {
                'pine' => 'pine',
                'bare' => $k < 3 ? 'bare' : 'pine',
                'mixed' => $k % 2 === 0 ? 'pine' : 'round',
                default => $k === 4 ? 'tall' : 'round',
            };
            $out[] = match ($shape) {
                'pine' => self::pine($pal, $k, $far),
                'bare' => self::bareTree($pal, $k),
                'tall' => self::tallTree($pal, $k),
                default => self::roundTree($pal, $k, $far),
            };
        }
        return $out;
    }

    /** A broad tree: a trunk with two branches under a big lumpy crown of leaves, lit from above. */
    private static function roundTree(array $pal, int $seed, bool $far = false): array
    {
        $t = new Mesh();
        [$shade, $mid, $sun] = array_map(static fn (string $h) => Mesh::rgb($h), $pal['leaves']);
        $bark = Mesh::rgb($pal['trunk']);
        $trunk = 6.5 + 2.5 * Mesh::hash($seed, 1);
        $t->cyl([0, 0, 0], 1.9, 1.0, $trunk + 3.0, Mesh::times($bark, 0.7), 6, [0, 0, 0], false, $bark);
        if (!$far) {
            foreach ([[-1.0, 0.5], [1.0, -0.8]] as [$side, $lean]) {
                $t->cyl([0, $trunk * 0.6, 0], 0.8, 0.35, 5.5, $bark, 5, [$lean * 0.7, 0, $side * 0.75], false);
            }
        }
        $t->material(Mesh::LEAVES);
        $r = 6.6 + 1.6 * Mesh::hash($seed, 2);
        $cy = $trunk + $r * 0.62;
        $low = Mesh::mix($shade, $mid, 0.25);
        $t->ball([0, $cy, 0], [$r, $r * 0.8, $r], $low, 6, $far ? 4 : 5, [0, $seed * 1.3, 0], $sun, 0.11, $seed);
        for ($i = 0; $i < ($far ? 2 : 3); $i++) {
            $a = $i * 2.1 + $seed * 0.9;
            $s = $r * (0.52 + 0.12 * Mesh::hash($seed, $i, 3));
            $t->ball([cos($a) * $r * 0.72, $cy + ($i - 1) * $r * 0.22, sin($a) * $r * 0.72], [$s, $s * 0.82, $s], $low, $far ? 5 : 6, 4, [0, $a, 0], $sun, 0.12, $seed + $i);
        }
        if (!$far) {
            $t->ball([-$r * 0.1, $cy + $r * 0.55, $r * 0.12], [$r * 0.55, $r * 0.48, $r * 0.55], $mid, 6, 4, [0, 0, 0], $sun, 0.1, $seed + 5);
        }
        return ['mesh' => $t, 'r' => $r * 1.3, 'h' => $cy, 'kind' => 'round'];
    }

    /** A tall slim tree (cypress, poplar): stacked lumps. */
    private static function tallTree(array $pal, int $seed): array
    {
        $t = new Mesh();
        [$shade, $mid, $sun] = array_map(static fn (string $h) => Mesh::rgb($h), $pal['leaves']);
        $t->cyl([0, 0, 0], 1.3, 0.8, 5.0, Mesh::rgb($pal['trunk'], 0.8), 6, [0, 0, 0], false);
        $t->material(Mesh::LEAVES);
        foreach ([[6.5, 4.6, 5.2], [11.5, 4.2, 5.0], [16.0, 3.4, 4.4], [19.8, 2.2, 3.0]] as $i => [$y, $r, $hh]) {
            $t->ball([0, $y, 0], [$r, $hh, $r], Mesh::mix($shade, $mid, 0.3 + $i * 0.1), 6, 4, [0, $i * 1.1, 0], $sun, 0.1, $seed + $i);
        }
        return ['mesh' => $t, 'r' => 5.0, 'h' => 13.0, 'kind' => 'pine'];
    }

    /** A fir: a trunk under tiers of cones, dark below, lighter (or snowy) at the tips. */
    private static function pine(array $pal, int $seed, bool $far = false): array
    {
        $t = new Mesh();
        [$shade, $mid, $sun] = array_map(static fn (string $h) => Mesh::rgb($h), $pal['leaves']);
        $t->cyl([0, 0, 0], 1.4, 0.8, 5.0, Mesh::rgb($pal['trunk'], 0.75), 6, [0, 0, 0], false);
        $t->material(Mesh::LEAVES * 0.5);
        $tall = 1.0 + 0.15 * Mesh::hash($seed, 1);
        foreach ([[3.0, 7.6, 8.0], [7.6, 6.2, 7.4], [11.8, 4.6, 6.6], [15.6, 3.0, 5.6]] as $i => [$y, $rb, $h]) {
            $t->cyl([0, $y * $tall, 0], $rb, 0.0, $h * $tall, Mesh::times($shade, 0.9), $far ? 7 : 9, [0, $i * 0.5 + $seed, 0], false,
                Mesh::mix($mid, $sun, 0.25 + $i * 0.2));
        }
        return ['mesh' => $t, 'r' => 6.0, 'h' => 12.0 * $tall, 'kind' => 'pine'];
    }

    /** A dead tree of the burnt lands: a trunk and bare branches. */
    private static function bareTree(array $pal, int $seed): array
    {
        $t = new Mesh();
        $bark = Mesh::rgb($pal['trunk']);
        $t->cyl([0, 0, 0], 1.6, 0.7, 13.0, Mesh::times($bark, 0.8), 6, [0, 0, 0], false, Mesh::times($bark, 1.3));
        for ($i = 0; $i < 4; $i++) {
            $a = $i * 1.7 + $seed;
            $t->cyl([0, 5.0 + $i * 2.2, 0], 0.6, 0.15, 6.0 - $i * 0.6, Mesh::times($bark, 1.2), 4, [cos($a) * 0.9, 0, sin($a) * 0.9], false);
        }
        return ['mesh' => $t, 'r' => 3.0, 'h' => 9.0, 'kind' => 'pine'];
    }

    /** Bushes along the roads, grass tufts, flowers, rocks: what makes the board look lived in. */
    private static function smallThings(Mesh $m, GroundMap $ground, array $rows, array $pal, callable $free, callable $roadDistance, array $paths): void
    {
        [$shade, $mid, $sun] = array_map(static fn (string $h) => Mesh::rgb($h), $pal['leaves']);
        [$g0, $g1, $g2] = array_map(static fn (string $h) => Mesh::rgb($h), $pal['grass']);
        $bushes = [];
        for ($k = 0; $k < 3; $k++) {
            $b = (new Mesh())->material(Mesh::LEAVES * 0.6);
            $low = Mesh::mix($shade, $mid, 0.35);
            $b->ball([0, 2.4, 0], [4.4, 3.4, 4.4], $low, 6, 4, [0, $k, 0], $sun, 0.12, $k);
            $b->ball([3.3, 1.9, 1.5 - $k], [3.0, 2.6, 3.0], $low, 6, 4, [0, $k + 1, 0], $sun, 0.12, $k + 3);
            if ($k !== 1) {
                $b->ball([-2.9, 1.7, -1.0], [2.8, 2.3, 2.8], $low, 6, 4, [0, $k + 2, 0], $sun, 0.12, $k + 6);
            }
            $bushes[] = $b;
        }
        // bushes on both sides of the roads, here and there
        foreach ($paths as $pi => $path) {
            $pts = self::roadPoints($path);
            for ($i = 1, $n = count($pts); $i < $n; $i++) {
                [$ax, $az] = $pts[$i - 1];
                [$bx, $bz] = $pts[$i];
                $len = hypot($bx - $ax, $bz - $az) ?: 1.0;
                [$dx, $dz] = [($bx - $ax) / $len, ($bz - $az) / $len];
                for ($d = 8.0; $d < $len; $d += 19.0) {
                    foreach ([-1, 1] as $side) {
                        $h = (int)($d * 13 + $i * 101 + $pi * 997 + $side * 7);
                        if (Mesh::hash($h, 1) > 0.42) {
                            continue;
                        }
                        $off = self::ROAD + 7.0 + Mesh::hash($h, 2) * 6.0;
                        $x = $ax + $dx * $d - $dz * $off * $side;
                        $z = $az + $dz * $d + $dx * $off * $side;
                        if (!$free($x, $z) || $roadDistance($x, $z) < self::ROAD + 5 || $ground->at(GroundMap::WATER, $x, $z) > 0.3) {
                            continue;
                        }
                        $s = 0.8 + 0.45 * Mesh::hash($h, 3);
                        $m->add($bushes[$h % 3], [$x, self::height($x, $z, $roadDistance), $z], $s, Mesh::hash($h, 4) * 6.283);
                        $ground->darken(GroundMap::OPEN, $x, $z, 7.0 * $s, 0.38, 0.2);
                        [$sx, $sz, $k] = self::shadowStep();
                        $ground->darken(GroundMap::SUN, $x + $sx * 3.0 * $s * $k, $z + $sz * 3.0 * $s * $k, 5.0 * $s, 0.5, 0.3);
                    }
                }
            }
        }

        // rocks of the scenery, mossy on top
        $rock = array_map(static fn (string $h) => Mesh::rgb($h), $pal['rock']);
        $moss = Mesh::rgb($pal['moss']);
        $flowers = array_map(static fn (string $h) => Mesh::rgb($h), $pal['flowers']);
        foreach ($rows as $tz => $row) {
            for ($tx = 0, $n = strlen($row); $tx < $n; $tx++) {
                $char = $row[$tx];
                $cx = $tx * 16 + 8 + (Mesh::hash($tx, $tz, 11) - 0.5) * 6;
                $cz = $tz * 16 + 8 + (Mesh::hash($tx, $tz, 12) - 0.5) * 6;
                if (!$free($cx, $cz) || $roadDistance($cx, $cz) < 19) {
                    continue;
                }
                if ($char === 'r') {
                    $s = 0.8 + 0.5 * Mesh::hash($tx, $tz, 13);
                    $top = Mesh::mix($rock[1], $moss, 0.45);
                    $m->ball([$cx, 1.4 * $s, $cz], [5.2 * $s, 3.6 * $s, 4.4 * $s], $rock[0], 7, 4, [0.15, $tx + $tz, 0.1], $top, 0.13, $tx * 3 + $tz);
                    $m->ball([$cx + 4.6 * $s, 0.7 * $s, $cz + 2.4 * $s], [2.4 * $s, 1.8 * $s, 2.2 * $s], $rock[0], 6, 3, [0, $tx, 0], $top, 0.1, $tz);
                    $ground->darken(GroundMap::OPEN, $cx, $cz, 9.0 * $s, 0.45, 0.25);
                    [$sx, $sz, $k] = self::shadowStep();
                    $ground->darken(GroundMap::SUN, $cx + $sx * 2.5 * $s * $k, $cz + $sz * 2.5 * $s * $k, 5.5 * $s, 0.55, 0.35);
                } elseif ($char === 'f') {
                    for ($f = 0; $f < 9; $f++) {
                        $fx = $tx * 16 + 1 + Mesh::hash($tx, $tz, $f, 1) * 14;
                        $fz = $tz * 16 + 1 + Mesh::hash($tx, $tz, $f, 2) * 14;
                        self::flower($m, $fx, $fz, $flowers[($tx + $tz + $f) % 4], $g1);
                    }
                }
            }
        }

        // grass tufts all over the grass, thicker along the roads, and a flower here and there
        $m->material(Mesh::GRASS);
        for ($z = -6.0; $z < 250.0; $z += 9.0) {
            for ($x = -6.0; $x < 488.0; $x += 9.0) {
                $h = (int)($x * 17 + $z * 131);
                $px = $x + (Mesh::hash($h, 1) - 0.5) * 8;
                $pz = $z + (Mesh::hash($h, 2) - 0.5) * 8;
                $road = $ground->at(GroundMap::ROAD, $px, $pz);
                $edge = $road > 0.32 && $road < 0.5;
                if ($road >= 0.47 || $ground->at(GroundMap::WATER, $px, $pz) > 0.35 || !$free($px, $pz)) {
                    continue;
                }
                $roll = Mesh::hash($h, 3);
                if ($roll < ($edge ? 0.75 : 0.2)) {
                    self::tuft($m, $px, $pz, $h, $g0, $g2, $edge ? 1.15 : 0.9);
                } elseif ($roll > 0.965 && $pal['trees'] !== 'pine') {
                    self::flower($m, $px, $pz, $flowers[abs($h) % 4], $g1);
                }
            }
        }
        $m->material(Mesh::SOLID);
    }

    /** A tuft of 4 blades of grass, dark at the foot, light at the tips (they sway). */
    private static function tuft(Mesh $m, float $x, float $z, int $h, array $foot, array $tip, float $size): void
    {
        $m->material(Mesh::GRASS);
        $up = [0.0, 1.0, 0.0];
        for ($b = 0; $b < 4; $b++) {
            $a = Mesh::hash($h, $b, 4) * 6.283;
            $len = (2.2 + 1.8 * Mesh::hash($h, $b, 5)) * $size;
            $bx = $x + cos($a) * 0.9;
            $bz = $z + sin($a) * 0.9;
            $lean = 0.6 + 0.6 * Mesh::hash($h, $b, 6);
            $m->tri([$bx - sin($a) * 0.55, 0.05, $bz + cos($a) * 0.55], [$bx + sin($a) * 0.55, 0.05, $bz - cos($a) * 0.55],
                [$bx + cos($a) * $lean, $len, $bz + sin($a) * $lean], $up, Mesh::times($foot, 0.8), Mesh::times($foot, 0.8), $tip);
        }
    }

    /** A small flower on a stem. */
    private static function flower(Mesh $m, float $x, float $z, array $col, array $stem): void
    {
        $m->material(Mesh::GRASS);
        $up = [0.0, 1.0, 0.0];
        $y = 1.7;
        $m->tri([$x - 0.3, 0.0, $z], [$x + 0.3, 0.0, $z], [$x, $y, $z], $up, $stem, $stem, $stem);
        $m->quad([[$x - 0.9, $y, $z], [$x, $y + 0.2, $z - 0.9], [$x + 0.9, $y, $z], [$x, $y + 0.2, $z + 0.9]], $up, [$col, $col, $col, $col]);
        $m->material(Mesh::SOLID);
    }

    /** Specks of light floating over the board (pollen in the sun, embers on burnt land). */
    private static function motes(Mesh $m, array $pal): void
    {
        $col = $pal['trees'] === 'bare' ? Mesh::rgb('#ffa040', 1.3) : Mesh::rgb('#fff6c8', 1.25);
        for ($i = 0; $i < 46; $i++) {
            $m->material(Mesh::MOTE + Mesh::hash($i, 9) * 0.99);
            $m->ball([20 + Mesh::hash($i, 1) * 440, 7 + Mesh::hash($i, 2) * 22, 8 + Mesh::hash($i, 3) * 228], [0.55, 0.55, 0.55], $col, 4, 2);
        }
        $m->material(Mesh::SOLID);
    }

    /** The round stone platforms where towers are built, with an inlay of the side's colour. */
    private static function platforms(Mesh $m, GroundMap $ground, array $slots, array $colors, array $pal): void
    {
        $stoneDark = Mesh::rgb('#6c665a');
        $stone = Mesh::rgb('#9a927e');
        $stoneTop = Mesh::rgb('#b2a994');
        $rune = Mesh::mix(Mesh::rgb($colors['light'] ?? '#c8f0b0'), $stoneTop, 0.35);
        $moss = Mesh::rgb($pal['moss']);
        [$sx, $sz, $k] = self::shadowStep();
        foreach ($slots as $i => [$tx, $ty]) {
            $cx = ($tx + 1) * 16.0;
            $cz = ($ty + 1) * 16.0;
            $m->cyl([$cx, -0.2, $cz], 16.8, 16.2, 1.5, Mesh::times($stoneDark, 0.85), 20, [0, 0, 0], false, $stoneDark);
            $m->cyl([$cx, 1.3, $cz], 15.6, 14.8, self::PLATFORM - 1.3, $stone, 20, [0, 0, 0], true, $stoneTop);
            $m->cyl([$cx, self::PLATFORM, $cz], 10.2, 10.2, 0.12, $rune, 20);
            $m->cyl([$cx, self::PLATFORM, $cz], 9.0, 9.0, 0.18, $stoneTop, 20);
            foreach ([0.0, M_PI / 2] as $a) {
                $m->box([$cx, self::PLATFORM + 0.2, $cz], [7.0, 0.12, 1.2], $rune, [0, $a, 0]);
            }
            // a little moss on the rim
            $m->material(Mesh::LEAVES * 0.3);
            for ($b = 0; $b < 3; $b++) {
                $a = Mesh::hash($i, $b, 1) * 6.283;
                $m->ball([$cx + cos($a) * 15.0, 1.0, $cz + sin($a) * 15.0], [2.6, 1.3, 2.2], Mesh::times($moss, 0.8), 6, 3, [0, $a, 0], $moss, 0.15, $b);
            }
            $m->material(Mesh::SOLID);
            $ground->darken(GroundMap::OPEN, $cx, $cz, 23.0, 0.42, 0.68);
            $ground->darken(GroundMap::SUN, $cx + $sx * 3.0 * $k, $cz + $sz * 3.0 * $k, 17.5, 0.5, 0.8);
        }
    }

    /**
     * The castle the player defends, where the road ends (its gate faces the road, west): a curtain wall with
     * battlements, a gatehouse, round towers with pointed roofs of the side's colour and pennants, a tall keep.
     */
    private static function castle(Mesh $m, GroundMap $ground, float $x0, float $z0, array $colors): void
    {
        $wall = Mesh::rgb('#efe6d0');
        $wallLow = Mesh::rgb('#cfc2a4');
        $plinth = Mesh::rgb('#9c927e');
        $dark = Mesh::rgb('#2c2018');
        $wood = Mesh::rgb('#7a5230');
        $main = Mesh::rgb($colors['main'] ?? '#3fa860');
        $roof = Mesh::mix(Mesh::rgb($colors['dark'] ?? '#1d5434'), $main, 0.45);
        $roofTop = Mesh::mix($main, [1.0, 1.0, 1.0], 0.2);
        $gold = Mesh::rgb('#f0c850');
        $glow = Mesh::rgb('#ffd890', 1.25);
        [$sx, $sz, $k] = self::shadowStep();
        $gx = $x0 + 16.0;
        $gz = $z0 + 48.0;
        $wx = $gx + 8.0;

        // the curtain wall along the road's end, battlements on top
        $m->slab([$wx, -0.5, $gz], [9.0, 6.0, 82.0], $plinth, [0, 0, 0], $wallLow);
        $m->slab([$wx, 5.0, $gz], [7.0, 18.0, 80.0], $wallLow, [0, 0, 0], $wall);
        for ($z = $gz - 37.0; $z <= $gz + 37.0; $z += 5.3) {
            $m->slab([$wx - 2.6, 23.0, $z], [2.2, 3.4, 3.0], $wall);
            $m->slab([$wx + 2.6, 23.0, $z], [2.2, 3.4, 3.0], $wall);
        }
        $m->slab([$wx, 22.6, $gz], [8.0, 0.8, 81.0], $wallLow);
        // the gatehouse: a taller block, an arched gate with its doors, a banner above
        $m->slab([$wx - 1.0, -0.5, $gz], [12.0, 31.0, 22.0], $wallLow, [0, 0, 0], $wall);
        $m->slab([$wx - 1.0, 30.5, $gz], [13.4, 1.4, 23.4], $plinth);
        foreach ([-1, 1] as $side) {
            $m->box([$wx - 1.0, 36.0, $gz + $side * 6.0], [13.0, 1.0, 12.8], $roof, [$side * 0.62, 0, 0], $roofTop);
        }
        // (the gatehouse's front is at x = gx + 1: the gate opens in it, under an arch)
        $m->slab([$gx + 0.6, -0.5, $gz], [1.4, 12.0, 11.0], $dark);
        $m->cyl([$gx + 1.3, 11.5, $gz], 5.5, 5.5, 1.4, $dark, 12, [0, 0, M_PI / 2]);
        $m->slab([$gx - 0.3, -0.5, $gz], [0.5, 10.8, 9.6], $wood, [0, 0, 0], Mesh::times($wood, 1.2));
        $m->slab([$gx - 0.6, 0.0, $gz], [0.3, 10.2, 0.6], $dark);
        foreach ([-1, 1] as $side) {
            $m->slab([$gx - 0.6, 3.0 + $side * 2.5 + 2.5, $gz], [0.3, 0.5, 9.0], Mesh::rgb('#4a3424'));
        }
        $m->slab([$gx + 0.6, 18.0, $gz], [0.6, 10.0, 7.0], $main, [0, 0, 0], Mesh::times($main, 1.15));
        $m->slab([$gx + 0.4, 25.0, $gz], [0.7, 2.2, 3.2], $gold);
        foreach ([-1, 1] as $side) {
            $m->slab([$gx + 0.7, 18.0, $gz + $side * 7.5], [0.6, 3.6, 1.6], Mesh::rgb('#3a2c24'));
        }

        // towers at the ends of the wall and a slim one behind, all with pointed roofs and pennants
        $tower = static function (float $x, float $z, float $r, float $h, float $roofH) use ($m, $wall, $wallLow, $plinth, $roof, $roofTop, $dark, $gold, $main, $glow, $ground, $sx, $sz, $k): void {
            $m->cyl([$x, -0.5, $z], $r + 1.2, $r + 0.6, 5.0, $plinth, 16, [0, 0, 0], false, $wallLow);
            $m->cyl([$x, 4.5, $z], $r + 0.2, $r - 0.4, $h - 4.5, $wallLow, 16, [0, 0, 0], false, $wall);
            $m->cyl([$x, $h - 3.0, $z], $r + 1.6, $r + 1.6, 3.0, $wall, 16, [0, 0, 0], true, Mesh::times($wall, 1.04));
            $m->cyl([$x, $h - 4.0, $z], $r + 0.2, $r + 1.6, 1.0, $wallLow, 16, [0, 0, 0], false);
            $m->cyl([$x, $h - 0.2, $z], $r + 3.2, 0.0, $roofH, $roof, 16, [0, 0, 0], false, $roofTop);
            $m->cyl([$x, $h + $roofH - 1.0, $z], 0.35, 0.35, 7.0, Mesh::rgb('#5a4a3a'), 4);
            $m->ball([$x, $h + $roofH + 6.4, $z], [0.8, 0.8, 0.8], $gold, 5, 3);
            $m->quad([[$x, $h + $roofH + 5.6, $z], [$x + 6.0, $h + $roofH + 4.6, $z], [$x + 6.0, $h + $roofH + 3.2, $z], [$x, $h + $roofH + 2.8, $z]],
                [0, 0, 1], [$main, Mesh::times($main, 1.1), Mesh::times($main, 1.1), $main]);
            // windows, one of them lit
            foreach ([[0.45, M_PI * 0.75], [0.7, M_PI * 0.5], [0.55, M_PI * 1.1]] as $i => [$f, $a]) {
                $m->box([$x + cos($a) * ($r - 0.2), $h * $f, $z + sin($a) * ($r - 0.2)], [1.4, 3.2, 1.4], $i === 1 ? $glow : $dark, [0, -$a, 0]);
            }
            $ground->darken(GroundMap::OPEN, $x, $z, $r + 7.0, 0.45, 0.6);
            for ($f = 0.2; $f <= 1.05; $f += 0.2) {
                $ground->darken(GroundMap::SUN, $x + $sx * ($h + $roofH * 0.4) * $k * $f, $z + $sz * ($h + $roofH * 0.4) * $k * $f,
                    ($r + 2.0) * (1.1 - $f * 0.55), 0.62, 0.45);
            }
        };
        $tower($wx, $gz - 41.0, 8.0, 40.0, 17.0);
        $tower($wx, $gz + 41.0, 8.0, 36.0, 16.0);
        $tower($wx + 26.0, $gz - 20.0, 6.0, 48.0, 15.0);
        // the keep: a big round tower in the middle, a balcony and the tallest roof
        $kx = $wx + 22.0;
        $kz = $gz + 6.0;
        $tower($kx, $kz, 11.0, 56.0, 24.0);
        $m->cyl([$kx, 36.0, $kz], 12.6, 12.6, 1.2, $wallLow, 16);
        // a house against the wall, inside
        $m->slab([$wx + 12.0, -0.5, $gz + 26.0], [12.0, 16.0, 14.0], $wall, [0, 0, 0], Mesh::times($wall, 1.03));
        foreach ([-1, 1] as $side) {
            $m->box([$wx + 12.0, 19.0, $gz + 26.0 + $side * 3.8], [13.4, 0.9, 8.6], Mesh::rgb('#b8583a'), [$side * 0.75, 0, 0], Mesh::rgb('#d07a52'));
        }
        // ivy at the foot of the wall
        $m->material(Mesh::LEAVES * 0.4);
        foreach ([-30.0, -18.0, 16.0, 26.0, 33.0] as $i => $dz) {
            $leaf = Mesh::rgb('#3c7a34');
            $m->ball([$wx - 4.0, 3.0 + ($i % 2) * 3.0, $gz + $dz], [1.6, 4.5 + ($i % 3), 3.4], $leaf, 6, 4, [0, 0, 0], Mesh::rgb('#8cc256'), 0.15, $i);
        }
        $m->material(Mesh::SOLID);
        $ground->darken(GroundMap::OPEN, $wx, $gz, 46.0, 0.35, 0.7);
        for ($f = 0.15; $f <= 1.0; $f += 0.17) {
            $ground->darken(GroundMap::SUN, $wx + $sx * 24.0 * $k * $f + 3.0, $gz + $sz * 24.0 * $k * $f, 18.0, 0.5, 0.7);
        }
    }
}
