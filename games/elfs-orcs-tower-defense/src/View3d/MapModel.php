<?php

namespace App\View3d;

use App\Td\GameData;
use App\Td\Path;
use App\Ui\Sprites;

/**
 * The ground of a map in 3D, built once (one mesh): grass with soft patches, the road as ribbons with an edge,
 * water, trees, rocks, flowers, the stone building platforms and the castle in the side's colours.
 * Coordinates are the map's: x, z in pixels of the 480 x 240 play area, y up.
 */
final class MapModel
{
    public const ROAD = 13.0;
    public const EDGE = 15.5;
    /** the platform's top above the ground */
    public const PLATFORM = 3.2;

    /** @return array{mesh: Mesh, fog: array{0: float, 1: float, 2: float}} */
    public static function build(array $map, array $colors): array
    {
        $theme = Sprites::THEMES[$map['theme'] ?? 'meadow'] ?? Sprites::THEMES['meadow'];
        $grass = array_map(static fn (string $h) => Mesh::rgb($h), $theme['grass']);
        $road = array_map(static fn (string $h) => Mesh::rgb($h), $theme['road']);
        $fog = Mesh::mix($grass[3], [0.74, 0.86, 0.96], 0.4);
        $m = new Mesh();

        // the land around the board, and the grass of the board itself with patches of other greens
        $m->floor(-2600.0, -2600.0, 3080.0, 2840.0, -0.6, Mesh::mix($grass[2], $fog, 0.35));
        for ($ix = -6; $ix < 36; $ix++) {
            for ($iz = -4; $iz < 19; $iz++) {
                $corner = static function (int $cx, int $cz) use ($grass): array {
                    $x = $cx * 16;
                    $z = $cz * 16;
                    $pick = $grass[(crc32("g$cx,$cz") & 0xff) % 3];
                    // fade into the surroundings outside the board
                    $out = hypot(max(0, -$x, $x - 480), max(0, -$z, $z - 240));
                    return Mesh::mix($pick, $grass[2], min(0.55, $out / 130));
                };
                $m->quad([[$ix * 16, 0.0, $iz * 16], [$ix * 16 + 16, 0.0, $iz * 16], [$ix * 16 + 16, 0.0, $iz * 16 + 16], [$ix * 16, 0.0, $iz * 16 + 16]],
                    [0, 1, 0], [$corner($ix, $iz), $corner($ix + 1, $iz), $corner($ix + 1, $iz + 1), $corner($ix, $iz + 1)]);
            }
        }

        // the roads
        $paths = array_map(static fn (array $p) => Path::fromTiles($p), $map['paths']);
        foreach ($paths as $path) {
            self::road($m, $path, $road);
        }
        $nearRoad = static function (float $x, float $z) use ($paths): float {
            $best = INF;
            foreach ($paths as $p) {
                $best = min($best, $p->distanceTo($x, $z));
            }
            return $best;
        };

        // keep the scenery off the platforms and the castle
        $blocked = [];
        foreach ($map['slots'] as [$sx, $sy]) {
            for ($dy = -1; $dy < 3; $dy++) {
                for ($dx = -1; $dx < 3; $dx++) {
                    $blocked[($sx + $dx) . ',' . ($sy + $dy)] = true;
                }
            }
        }
        [$bx, $by] = $map['bastion'];
        for ($dy = -1; $dy < 7; $dy++) {
            for ($dx = -1; $dx < 4; $dx++) {
                $blocked[($bx + $dx) . ',' . ($by + $dy)] = true;
            }
        }

        $trees = self::treeVariants($theme, (string)($map['theme'] ?? 'meadow'));
        $rockCol = [Mesh::rgb('#8a8a88'), Mesh::rgb('#a2a29c'), Mesh::rgb('#6e6e6c')];
        $water = [Mesh::rgb($theme['water'][0]), Mesh::rgb($theme['water'][1])];
        $flowers = [Mesh::rgb('#f0e060'), Mesh::rgb('#f080a0'), Mesh::rgb('#ffffff'), Mesh::rgb('#a0a0ff')];
        foreach (Sprites::sceneryRows($map, (string)($map['name'] ?? 'map')) as $tz => $row) {
            for ($tx = 0, $n = strlen($row); $tx < $n; $tx++) {
                $char = $row[$tx];
                $cx = $tx * 16 + 8;
                $cz = $tz * 16 + 8;
                if ($char === '.' || isset($blocked["$tx,$tz"]) || $nearRoad($cx, $cz) < 20) {
                    continue;
                }
                // 16 bits: crc32() is signed (and its products overflow) in the browser's 32-bit PHP
                $h = crc32("s$tx;$tz") & 0xffff;
                $ox = ($h & 7) - 3.5;
                $oz = (($h >> 3) & 7) - 3.5;
                if ($char === 't') {
                    $m->add($trees[$h % count($trees)], [$cx + $ox, 0.0, $cz + $oz], 0.85 + (($h >> 6) & 7) / 14, ($h & 15) / 3);
                } elseif ($char === 'r') {
                    $c = $rockCol[$h % 3];
                    $m->ball([$cx + $ox, 1.6, $cz + $oz], [5.0, 3.4, 4.2], $c, 6, 3, [0.0, ($h & 15) / 3, 0.0]);
                    $m->ball([$cx + $ox + 4, 0.9, $cz + $oz + 2], [2.6, 1.8, 2.2], $rockCol[($h + 1) % 3], 5, 3);
                } elseif ($char === 'w') {
                    $m->floor($tx * 16, $tz * 16, $tx * 16 + 16, $tz * 16 + 16, 0.35, $water[0]);
                    $m->floor($tx * 16 + 3, $tz * 16 + 5, $tx * 16 + 8, $tz * 16 + 6.5, 0.4, $water[1]);
                    $m->floor($tx * 16 + 8, $tz * 16 + 11, $tx * 16 + 13, $tz * 16 + 12.5, 0.4, $water[1]);
                } elseif ($char === 'f') {
                    for ($k = 0; $k < 5; $k++) {
                        $fx = $tx * 16 + 2 + (($h >> $k) * 7) % 12;
                        $fz = $tz * 16 + 2 + (($h >> ($k + 3)) * 5) % 12;
                        $m->ball([$fx, 1.0, $fz], [0.9, 0.9, 0.9], $flowers[($h + $k) % 4], 4, 3);
                    }
                }
            }
        }

        // the stone platforms, with a rune of the side's colour
        $stone = Mesh::rgb('#8e8a7c');
        $stoneDark = Mesh::rgb('#5a5850');
        $rune = Mesh::mix(Mesh::rgb($colors['light'] ?? '#c8f0b0'), $stone, 0.25);
        foreach ($map['slots'] as [$sx, $sy]) {
            $cx = ($sx + 1) * 16.0;
            $cz = ($sy + 1) * 16.0;
            $m->cyl([$cx, 0.0, $cz], 16.5, 16.0, 1.4, $stoneDark, 14);
            $m->cyl([$cx, 1.4, $cz], 15.2, 14.0, self::PLATFORM - 1.4, $stone, 14);
            $m->cyl([$cx, self::PLATFORM, $cz], 8.5, 8.5, 0.25, $rune, 14);
            $m->box([$cx, self::PLATFORM + 0.3, $cz], [9.0, 0.3, 1.4], $stone);
            $m->box([$cx, self::PLATFORM + 0.3, $cz], [1.4, 0.3, 9.0], $stone);
        }

        self::castle($m, $bx * 16.0, $by * 16.0, $colors);
        return ['mesh' => $m, 'fog' => $fog];
    }

    /** One road: an edge ribbon under a lighter one, discs at the corners, a few pebbles. */
    private static function road(Mesh $m, Path $path, array $road): void
    {
        $pts = $path->points;
        // the road runs on past the board at both ends
        $first = $pts[0];
        $second = $pts[1];
        $dx = $second[0] - $first[0];
        $dz = $second[1] - $first[1];
        $len = hypot($dx, $dz) ?: 1.0;
        array_unshift($pts, [$first[0] - $dx / $len * 80, $first[1] - $dz / $len * 80]);
        $edge = $road[3];
        $n = count($pts);
        for ($i = 1; $i < $n; $i++) {
            [$ax, $az] = $pts[$i - 1];
            [$bx, $bz] = $pts[$i];
            $dx = $bx - $ax;
            $dz = $bz - $az;
            $len = hypot($dx, $dz) ?: 1.0;
            $nx = -$dz / $len;
            $nz = $dx / $len;
            foreach ([[self::EDGE, 0.12, $edge, $edge], [self::ROAD, 0.3, $road[0], $road[0]]] as [$w, $y, $c0, $c1]) {
                $m->quad([[$ax + $nx * $w, $y, $az + $nz * $w], [$bx + $nx * $w, $y, $bz + $nz * $w], [$bx - $nx * $w, $y, $bz - $nz * $w], [$ax - $nx * $w, $y, $az - $nz * $w]],
                    [0, 1, 0], [$c0, $c0, $c1, $c1]);
            }
            // pebbles on the road
            for ($d = 6.0; $d < $len; $d += 9.0) {
                $h = crc32("p$ax,$az,$d") & 0xffff;
                $off = (($h & 15) / 15 - 0.5) * 2 * (self::ROAD - 3);
                $m->box([$ax + $dx / $len * $d + $nx * $off, 0.45, $az + $dz / $len * $d + $nz * $off], [1.8, 0.5, 1.3], $road[4], [0.0, ($h >> 4) / 7, 0.0]);
            }
        }
        for ($i = 1; $i < $n - 1; $i++) {
            $m->cyl([$pts[$i][0], 0.0, $pts[$i][1]], self::EDGE, self::EDGE, 0.12, $edge, 16);
            $m->cyl([$pts[$i][0], 0.0, $pts[$i][1]], self::ROAD, self::ROAD, 0.3, $road[0], 16);
        }
    }

    /** @return list<Mesh> a few trees, to place with add(): pines, or round trees (autumn, sometimes meadow) */
    private static function treeVariants(array $theme, string $name): array
    {
        [$dark, $mid, $light] = array_map(static fn (string $h) => Mesh::rgb($h), $theme['tree']);
        $trunk = Mesh::rgb('#4a3018');
        $variants = [];
        for ($k = 0; $k < 3; $k++) {
            $t = new Mesh();
            $round = $name === 'autumn' || ($name === 'meadow' && $k === 2);
            $t->cyl([0, 0, 0], 1.9, 1.3, 5.5, $trunk, 6);
            if ($round) {
                $t->ball([0, 10.0, 0], [6.4, 5.8, 6.4], $mid, 7, 4);
                $t->ball([-1.5, 12.0, -1.2], [4.0, 3.4, 4.0], $light, 6, 3);
                $t->ball([2.2, 8.0, 1.8], [3.6, 3.2, 3.6], $dark, 6, 3);
            } else {
                $t->cyl([0, 3.0, 0], 7.4 - $k * 0.4, 0.0, 10.5, $dark, 7);
                $t->cyl([0, 8.5, 0], 5.6 - $k * 0.3, 0.0, 9.0, $mid, 7);
                $t->cyl([0, 13.5, 0], 3.8 - $k * 0.2, 0.0, 7.0, $light, 7);
            }
            $variants[] = $t;
        }
        return $variants;
    }

    /** The castle the player defends: a wall with a gate where the road ends, two towers, banners of the side. */
    private static function castle(Mesh $m, float $x0, float $z0, array $colors): void
    {
        $stone = Mesh::rgb('#d8d4c4');
        $shade = Mesh::rgb('#a8a496');
        $dark = Mesh::rgb('#3a2614');
        $main = Mesh::rgb($colors['main'] ?? '#3fa860');
        $roof = Mesh::rgb($colors['dark'] ?? '#1d5434', 1.15);
        $gold = Mesh::rgb('#e8c040');
        $cx = $x0 + 30;
        $cz = $z0 + 48;
        // the curtain wall, with its battlements
        $m->slab([$cx, 0.0, $cz], [26.0, 26.0, 58.0], $stone);
        $m->slab([$cx + 14.0, 0.0, $cz], [3.0, 26.0, 58.0], $shade);
        for ($z = $cz - 26; $z <= $cz + 26; $z += 7) {
            foreach ([-11.5, 11.5] as $dx) {
                $m->slab([$cx + $dx, 26.0, $z], [3.2, 4.0, 4.2], $shade);
            }
        }
        // the gate, where the road arrives
        $m->slab([$cx - 13.0, 0.0, $cz], [3.0, 15.0, 13.0], $dark);
        $m->slab([$cx - 13.6, 15.0, $cz], [2.0, 2.4, 15.0], $shade);
        $m->slab([$cx - 13.6, 18.0, $cz], [1.0, 9.0, 5.5], $main);
        $m->slab([$cx - 13.6, 27.0, $cz], [1.1, 1.4, 6.5], $gold);
        // two towers with pointed roofs
        foreach ([-1, 1] as $side) {
            $tz = $cz + $side * 32.0;
            $m->cyl([$cx, 0.0, $tz], 10.0, 9.0, 40.0, $stone, 10);
            $m->cyl([$cx, 37.0, $tz], 11.5, 11.5, 3.6, $shade, 10);
            $m->cyl([$cx, 40.6, $tz], 12.6, 0.0, 19.0, $roof, 10);
            $m->cyl([$cx, 58.0, $tz], 0.5, 0.5, 9.0, $shade, 4);
            $m->slab([$cx - 3.4, 63.0, $tz], [6.8, 3.8, 0.8], $main);
            $m->slab([$cx - 9.4, 24.0, $tz], [1.0, 5.4, 2.6], $dark);
        }
        // the keep, behind the gate
        $m->slab([$cx + 5.0, 0.0, $cz], [15.0, 38.0, 17.0], $stone);
        $m->cyl([$cx + 5.0, 38.0, $cz], 13.0, 0.0, 15.0, $roof, 4, [0, M_PI / 4, 0]);
        $m->cyl([$cx + 5.0, 52.0, $cz], 0.5, 0.5, 9.0, $shade, 4);
        $m->slab([$cx + 1.6, 57.0, $cz], [6.8, 3.8, 0.8], $main);
        $m->slab([$cx - 3.0, 24.0, $cz], [1.0, 5.4, 2.6], $dark);
    }
}
