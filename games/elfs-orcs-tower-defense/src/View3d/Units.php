<?php

namespace App\View3d;

/**
 * The creatures of the game in 3D (enemies, soldiers, heroes, mounts), sculpted from smooth surfaces with sturdy,
 * readable shapes: broad shoulders, big hands and weapons, faces (brow, jaw, nose, ears, tusks), armour that shines,
 * each people its own build (hunched orcs with big fists, stocky dwarves, slender elves, round little folk). Each
 * has a skeleton (Skeleton) posed every frame: walk, fight, idle, stun, ride, fly. Facing +x, feet on y = 0, about
 * 15 units tall for a man at size 1 (World3d::creature() scales them by size()).
 */
final class Units
{
    // the bones of a person
    public const HIPS = 0;
    public const CHEST = 1;
    public const HEAD = 2;
    public const ARM_R = 3;
    public const FORE_R = 4;
    public const HAND_R = 5;
    public const ARM_L = 6;
    public const FORE_L = 7;
    public const HAND_L = 8;
    public const THIGH_R = 9;
    public const SHIN_R = 10;
    public const THIGH_L = 11;
    public const SHIN_L = 12;
    public const CAPE = 13;
    public const FOOT_R = 14;
    public const FOOT_L = 15;

    // the bones of a mount: legs are LEG + 2 * (0 front right, 1 front left, 2 back right, 3 back left), + 1 below
    private const BODY = 0;
    private const NECK = 1;
    private const SKULL = 2;
    private const TAIL = 3;
    private const LEG = 4;

    // the bones of a flyer
    private const F_BODY = 0;
    private const F_HEAD = 1;
    private const WING_R = 2;
    private const TIP_R = 3;
    private const WING_L = 4;
    private const TIP_L = 5;
    private const F_TAIL = 6;

    // the bones of the ram
    private const CART = 0;
    private const WHEEL = 1;
    private const LOG = 5;

    /** The animations: walk, fight and fly are cycles (t: 0..1 a cycle), idle and stun go on (t in seconds). */
    public const ANIMS = ['walk', 'fight', 'idle', 'stun', 'fly'];

    /**
     * k: human | beast (a mount, with or without a rider) | wings | ram.
     * Human: race (man elf orc troll goblin dwarf halfling), skin, cloth, legs, armor (a metal cuirass and pauldrons),
     * pads (leather pauldrons), helm [type, colour] (cap helm horns hood hat crown), hair + style (long crest curls),
     * beard, weapon [type, colour] (club axe sword twin spear bow dagger pick staff stick), shield colour, eye, cape,
     * robe (down to the feet), barefoot, boots, bulk (width), size.
     */
    public const KINDS = [
        'grunt' => ['k' => 'human', 'race' => 'orc', 'skin' => '#6aa044', 'cloth' => '#7a4a2a', 'legs' => '#4e2e18', 'pads' => '#5c3a22', 'hair' => '#24201a', 'style' => 'crest', 'weapon' => ['club', '#6a4a2a'], 'eye' => '#ff4030', 'bulk' => 1.15, 'size' => 1.05],
        'iron_orc' => ['k' => 'human', 'race' => 'orc', 'skin' => '#5a9036', 'cloth' => '#4c5058', 'legs' => '#3c4048', 'armor' => '#8a8f99', 'helm' => ['helm', '#8a8f99'], 'weapon' => ['axe', '#c0c4cc'], 'shield' => '#5a3a20', 'eye' => '#ff6020', 'bulk' => 1.35, 'size' => 1.12],
        'goblin' => ['k' => 'human', 'race' => 'goblin', 'skin' => '#a8bc44', 'cloth' => '#6a4a8a', 'legs' => '#4a3a60', 'weapon' => ['dagger', '#c8c8d0'], 'eye' => '#ffe030', 'bulk' => 0.85, 'size' => 0.85],
        'warg' => ['k' => 'beast', 'body' => '#6e5e4e', 'mane' => '#4a3c30', 'snout' => 'wolf', 'rider' => ['race' => 'orc', 'skin' => '#6aa044', 'cloth' => '#7a4a2a', 'legs' => '#4e2e18', 'pads' => '#5c3a22', 'hair' => '#24201a', 'style' => 'crest', 'weapon' => ['club', '#6a4a2a'], 'eye' => '#ff4030', 'bulk' => 1.05, 'size' => 0.8], 'size' => 1.0],
        'bat' => ['k' => 'wings', 'body' => '#4a3650', 'wing' => '#6a4a7a', 'eye' => '#ff5040', 'size' => 1.0],
        'ram' => ['k' => 'ram', 'size' => 1.0],
        'troll' => ['k' => 'human', 'race' => 'troll', 'skin' => '#8494a8', 'cloth' => '#6a5030', 'legs' => '#4a3420', 'hair' => '#3a4a3a', 'style' => 'crest', 'weapon' => ['club', '#5a4028'], 'eye' => '#ffd030', 'bulk' => 1.6, 'size' => 1.9],
        'warlord' => ['k' => 'human', 'race' => 'orc', 'skin' => '#5a8a34', 'cloth' => '#8a2018', 'legs' => '#3a0c08', 'armor' => '#46464e', 'helm' => ['horns', '#3a3a40'], 'weapon' => ['axe', '#d0d0d0'], 'eye' => '#ff3010', 'cape' => '#4a0c08', 'bulk' => 1.5, 'size' => 1.35],
        'elf' => ['k' => 'human', 'race' => 'elf', 'skin' => '#f4d8bc', 'cloth' => '#3f8a50', 'legs' => '#1d5434', 'hair' => '#f2d870', 'style' => 'long', 'weapon' => ['bow', '#8a6a3a'], 'eye' => '#2a6a3a', 'cape' => '#2a6a3c', 'boots' => '#5a4028', 'bulk' => 0.9, 'size' => 1.0],
        'blade' => ['k' => 'human', 'race' => 'elf', 'skin' => '#f4d8bc', 'cloth' => '#7a5ab0', 'legs' => '#3a2a6a', 'hair' => '#eceaf4', 'style' => 'long', 'weapon' => ['twin', '#e4ecf4'], 'eye' => '#3a2a6a', 'boots' => '#4a3a5a', 'bulk' => 0.92, 'size' => 1.0],
        'warden' => ['k' => 'human', 'race' => 'elf', 'skin' => '#f8e4cc', 'cloth' => '#f2f0e2', 'legs' => '#c8a040', 'hair' => '#fff0a8', 'style' => 'long', 'weapon' => ['staff', '#a8e8ff'], 'cape' => '#c8a040', 'eye' => '#2a6aa0', 'robe' => true, 'bulk' => 1.0, 'size' => 1.1],
        'eagle' => ['k' => 'wings', 'body' => '#6a4420', 'wing' => '#8e5c2c', 'eye' => '#ffd020', 'beak' => true, 'size' => 1.3],
        'dwarf' => ['k' => 'human', 'race' => 'dwarf', 'skin' => '#ecbc94', 'cloth' => '#6a7280', 'legs' => '#3a4048', 'armor' => '#a0a6b0', 'helm' => ['helm', '#a0a6b0'], 'beard' => '#cc6e2c', 'shield' => '#8a6a3a', 'weapon' => ['sword', '#d0d0d8'], 'eye' => '#3a3028', 'bulk' => 1.45, 'size' => 0.95],
        'miner' => ['k' => 'human', 'race' => 'dwarf', 'skin' => '#ecbc94', 'cloth' => '#8a5a2a', 'legs' => '#3a2a20', 'helm' => ['cap', '#e8c040'], 'beard' => '#8a5a2a', 'weapon' => ['pick', '#a8a8b0'], 'eye' => '#3a3028', 'pads' => '#6a4a2a', 'bulk' => 1.35, 'size' => 0.95],
        'runebearer' => ['k' => 'human', 'race' => 'dwarf', 'skin' => '#ecbc94', 'cloth' => '#3a5a9a', 'legs' => '#1e3050', 'helm' => ['hood', '#3a5a9a'], 'beard' => '#ececec', 'weapon' => ['staff', '#80e0ff'], 'eye' => '#3a3028', 'robe' => true, 'bulk' => 1.3, 'size' => 1.0],
        'thane' => ['k' => 'human', 'race' => 'dwarf', 'skin' => '#ecbc94', 'cloth' => '#a8302a', 'legs' => '#5a1810', 'armor' => '#d8b040', 'helm' => ['helm', '#ecc444'], 'beard' => '#cc6e2c', 'shield' => '#ecc444', 'weapon' => ['axe', '#ececf4'], 'eye' => '#3a3028', 'cape' => '#6a1810', 'bulk' => 1.6, 'size' => 1.1],
        'rider' => ['k' => 'beast', 'body' => '#a8743c', 'mane' => '#6a4a26', 'snout' => 'horse', 'cloth' => '#c48c30', 'rider' => ['race' => 'man', 'skin' => '#f2cca4', 'cloth' => '#c48c30', 'legs' => '#5a3a10', 'weapon' => ['spear', '#8a6a3a'], 'helm' => ['cap', '#c48c30'], 'hair' => '#8a5a2a', 'eye' => '#3a2a1a', 'shield' => '#c48c30', 'size' => 0.82], 'size' => 1.0],
        'horsearcher' => ['k' => 'beast', 'body' => '#dcd4c4', 'mane' => '#9a9080', 'snout' => 'horse', 'cloth' => '#3f7a40', 'rider' => ['race' => 'man', 'skin' => '#f2cca4', 'cloth' => '#3f7a40', 'legs' => '#245024', 'weapon' => ['bow', '#8a6a3a'], 'helm' => ['hood', '#3f7a40'], 'eye' => '#3a2a1a', 'size' => 0.82], 'size' => 1.0],
        'horselord' => ['k' => 'beast', 'body' => '#f4f2ee', 'mane' => '#c4c0b8', 'snout' => 'horse', 'cloth' => '#a8302a', 'rider' => ['race' => 'man', 'skin' => '#f2cca4', 'cloth' => '#ecc444', 'legs' => '#8a6a20', 'armor' => '#d8b040', 'weapon' => ['spear', '#ecc444'], 'helm' => ['crown', '#ecc444'], 'hair' => '#c8a060', 'cape' => '#a8302a', 'eye' => '#3a2a1a', 'size' => 0.86], 'size' => 1.15],
        'scout' => ['k' => 'human', 'race' => 'man', 'skin' => '#e4bc94', 'cloth' => '#3a3a44', 'legs' => '#2a2a34', 'helm' => ['hood', '#3a3a44'], 'weapon' => ['dagger', '#c8c8d0'], 'eye' => '#e8e0a0', 'cape' => '#2a2a34', 'boots' => '#2a2026', 'bulk' => 0.88, 'size' => 0.95],
        'littleman' => ['k' => 'human', 'race' => 'halfling', 'skin' => '#ecc49c', 'cloth' => '#5a8a3a', 'legs' => '#7a5a32', 'hair' => '#8a5a2a', 'style' => 'curls', 'barefoot' => true, 'weapon' => ['stick', '#8a6a3a'], 'eye' => '#3a2a1a', 'bulk' => 1.0, 'size' => 0.75],
        'captain' => ['k' => 'human', 'race' => 'halfling', 'skin' => '#ecc49c', 'cloth' => '#c84a3a', 'legs' => '#6a4a2a', 'hair' => '#c88a3a', 'style' => 'curls', 'helm' => ['hat', '#e8c040'], 'weapon' => ['sword', '#e8c040'], 'cape' => '#3a5a8a', 'eye' => '#3a2a1a', 'bulk' => 1.05, 'size' => 0.9],
    ];

    /** Each people's build: lengths and widths against a man's. */
    private const RACES = [
        'man' => ['legs' => 1.0, 'torso' => 1.0, 'arms' => 1.0, 'head' => 1.0, 'hunch' => 0.0, 'shoulders' => 1.0, 'hand' => 1.0, 'chest' => 1.0, 'belly' => 1.0],
        'elf' => ['legs' => 1.08, 'torso' => 1.0, 'arms' => 1.02, 'head' => 0.95, 'hunch' => 0.0, 'shoulders' => 0.92, 'hand' => 0.88, 'chest' => 0.9, 'belly' => 0.92],
        'orc' => ['legs' => 0.92, 'torso' => 1.02, 'arms' => 1.12, 'head' => 1.0, 'hunch' => 0.6, 'shoulders' => 1.12, 'hand' => 1.28, 'chest' => 1.1, 'belly' => 1.0],
        'troll' => ['legs' => 0.95, 'torso' => 1.08, 'arms' => 1.32, 'head' => 0.85, 'hunch' => 1.1, 'shoulders' => 1.12, 'hand' => 1.35, 'chest' => 1.12, 'belly' => 1.12],
        'goblin' => ['legs' => 0.82, 'torso' => 0.82, 'arms' => 1.1, 'head' => 1.28, 'hunch' => 0.7, 'shoulders' => 0.86, 'hand' => 1.12, 'chest' => 0.86, 'belly' => 1.1],
        'dwarf' => ['legs' => 0.7, 'torso' => 0.95, 'arms' => 0.92, 'head' => 1.06, 'hunch' => 0.0, 'shoulders' => 1.06, 'hand' => 1.15, 'chest' => 1.16, 'belly' => 1.1],
        'halfling' => ['legs' => 0.72, 'torso' => 0.86, 'arms' => 0.88, 'head' => 1.16, 'hunch' => 0.0, 'shoulders' => 0.86, 'hand' => 0.95, 'chest' => 0.95, 'belly' => 1.2],
    ];

    /** The heads, from the chin up: [height, half depth (front-back), half width, forwards], at head size 1. */
    private const HEADS = [
        'man' => [[0.0, 0.28, 0.32, 0.92], [0.25, 0.75, 0.8, 0.76], [0.75, 1.2, 1.12, 0.5], [1.35, 1.5, 1.35, 0.28], [1.95, 1.6, 1.42, 0.14], [2.6, 1.58, 1.4, 0.05], [3.15, 1.2, 1.08, -0.05], [3.5, 0.0, 0.0, -0.1]],
        'elf' => [[0.0, 0.2, 0.24, 0.96], [0.3, 0.66, 0.7, 0.76], [0.85, 1.1, 1.0, 0.45], [1.45, 1.42, 1.26, 0.25], [2.05, 1.52, 1.32, 0.12], [2.7, 1.5, 1.32, 0.03], [3.25, 1.12, 1.0, -0.08], [3.6, 0.0, 0.0, -0.12]],
        'orc' => [[0.0, 0.5, 0.75, 1.12], [0.3, 1.22, 1.35, 0.96], [0.85, 1.52, 1.5, 0.62], [1.45, 1.55, 1.42, 0.32], [2.05, 1.5, 1.36, 0.12], [2.6, 1.42, 1.28, -0.02], [3.05, 1.05, 0.95, -0.15], [3.3, 0.0, 0.0, -0.2]],
        'troll' => [[0.0, 0.6, 0.7, 1.45], [0.3, 1.2, 1.2, 1.22], [0.85, 1.45, 1.35, 0.8], [1.4, 1.45, 1.3, 0.4], [1.95, 1.35, 1.2, 0.1], [2.45, 1.1, 1.0, -0.1], [2.8, 0.0, 0.0, -0.25]],
        'goblin' => [[0.0, 0.2, 0.25, 1.15], [0.3, 0.6, 0.72, 0.92], [0.8, 1.15, 1.18, 0.55], [1.45, 1.6, 1.55, 0.2], [2.15, 1.78, 1.7, 0.0], [2.85, 1.7, 1.6, -0.15], [3.4, 1.2, 1.12, -0.25], [3.75, 0.0, 0.0, -0.3]],
        'dwarf' => [[0.0, 0.35, 0.4, 0.9], [0.3, 0.85, 0.95, 0.75], [0.8, 1.3, 1.25, 0.5], [1.4, 1.58, 1.45, 0.28], [2.0, 1.66, 1.5, 0.12], [2.6, 1.6, 1.46, 0.03], [3.1, 1.2, 1.1, -0.06], [3.4, 0.0, 0.0, -0.1]],
        'halfling' => [[0.0, 0.35, 0.4, 0.8], [0.3, 0.9, 0.95, 0.65], [0.85, 1.4, 1.36, 0.38], [1.5, 1.62, 1.5, 0.18], [2.15, 1.62, 1.5, 0.05], [2.75, 1.4, 1.3, -0.05], [3.2, 0.0, 0.0, -0.12]],
    ];

    /** @var array<string, Skeleton> */
    private static array $skeletons = [];
    /** @var array<string, list<float>> */
    private static array $poses = [];
    /** @var array<string, array<string, mixed>> */
    private static array $frames = [];

    // ---- what the scenes ask -------------------------------------------------------------------------------------

    public static function size(string $sprite): float
    {
        return (float)(self::KINDS[$sprite]['size'] ?? 1.0);
    }

    /** About how tall (units, with its size) a creature stands, to place its bar and its marks. */
    public static function height(string $sprite): float
    {
        $d = self::KINDS[$sprite] ?? self::KINDS['grunt'];
        $base = match ($d['k']) {
            'beast' => 19.0,
            'wings' => 7.0,
            'ram' => 13.0,
            default => 15.5,
        };
        return $base * (float)$d['size'];
    }

    /** How far (units, size 1) a creature goes in one walking cycle: walk's t = distance / (stride * scale). */
    public static function stride(string $sprite): float
    {
        $d = self::KINDS[$sprite] ?? self::KINDS['grunt'];
        if ($d['k'] === 'beast') {
            return 22.0;
        }
        if ($d['k'] === 'ram') {
            // a turn of its wheels (2.8 units round) a cycle
            return 2 * M_PI * 2.8;
        }
        if ($d['k'] !== 'human') {
            return 14.0;
        }
        $f = self::frame($d);
        $amp = !empty($d['robe']) ? 0.32 : 0.55;
        return 4.0 * ($f['hip'] - 1.05) * sin($amp);
    }

    /** The models a creature is drawn with: its body, and its rider when it has one. @return list<string> */
    public static function parts(string $sprite): array
    {
        return isset(self::KINDS[$sprite]['rider']) ? ['body', 'rider'] : ['body'];
    }

    /** A creature's model (its rest pose; each vertex on its bone). */
    public static function build(string $sprite, string $part = 'body'): Mesh
    {
        $d = self::KINDS[$sprite] ?? self::KINDS['grunt'];
        if ($part === 'rider' && isset($d['rider'])) {
            $r = $d['rider'];
            return self::person($r)->scaleAll(self::riderSize($r));
        }
        return match ($d['k']) {
            'beast' => self::beast($d),
            'wings' => self::wings($d),
            'ram' => self::ram(),
            default => self::person($d),
        };
    }

    /** A rider against a standing person (a rider sits a little smaller than one on foot). */
    private static function riderSize(array $rider): float
    {
        return (float)($rider['size'] ?? 0.82);
    }

    public static function skeleton(string $sprite, string $part = 'body'): Skeleton
    {
        return self::$skeletons["$sprite:$part"] ??= self::makeSkeleton($sprite, $part);
    }

    /**
     * A pose's bone matrices (Skeleton::matrices(): u_bones). $anim: one of ANIMS; walk / fight / fly: $t in cycles
     * (any number: only its fraction counts), idle / stun: $t in seconds. Kept: every creature of a kind shares them.
     * @return list<float>
     */
    public static function pose(string $sprite, string $anim, float $t, string $part = 'body'): array
    {
        $cyclic = $anim !== 'idle' && $anim !== 'stun';
        // 32 steps a cycle, 8 a second for the others (a loop of 10 s): a few MB for a battle's creatures (the
        // browser's PHP has 128 MB)
        $q = $cyclic ? (int)floor(($t - floor($t)) * 32) : ((int)floor($t * 8) % 80 + 80) % 80;
        $key = "$sprite:$part:$anim:$q";
        if (!isset(self::$poses[$key])) {
            if (count(self::$poses) > 2500) {
                self::$poses = [];
            }
            self::$poses[$key] = self::makePose($sprite, $part, $anim, $cyclic ? $q / 32 : $q / 8);
        }
        return self::$poses[$key];
    }

    /** A mesh's vertices moved by a pose (what the shader does): [x, y, z] each. @param list<float> $pose @return \Generator<int, array{0: float, 1: float, 2: float}> */
    public static function posed(Mesh $m, array $pose): \Generator
    {
        $v = $m->v;
        for ($i = 0, $n = count($v); $i < $n; $i += Mesh::STRIDE) {
            $mat = $v[$i + 9];
            $p = [$v[$i], $v[$i + 1], $v[$i + 2]];
            if ($mat > Mesh::BONE - 0.5) {
                $bone = (int)round($mat > Mesh::METAL_BONE - 0.5 ? $mat - Mesh::METAL_BONE : $mat - Mesh::BONE);
                $p = Skeleton::apply($pose, $bone, $p);
            }
            yield $p;
        }
    }

    /**
     * The box round a creature in a pose, all its parts (size 1): [[min x, y, z], [max x, y, z]].
     * @return array{0: array{0: float, 1: float, 2: float}, 1: array{0: float, 1: float, 2: float}}
     */
    public static function bounds(string $sprite, string $anim = 'idle', float $t = 0.0): array
    {
        $min = [INF, INF, INF];
        $max = [-INF, -INF, -INF];
        foreach (self::parts($sprite) as $part) {
            foreach (self::posed(self::build($sprite, $part), self::pose($sprite, $anim, $t, $part)) as $p) {
                for ($a = 0; $a < 3; $a++) {
                    $min[$a] = min($min[$a], $p[$a]);
                    $max[$a] = max($max[$a], $p[$a]);
                }
            }
        }
        return $min[0] === INF ? [[0.0, 0.0, 0.0], [1.0, 1.0, 1.0]] : [$min, $max];
    }

    // ---- people ---------------------------------------------------------------------------------------------------

    /** The joints of a person at rest (size 1). @return array<string, mixed> */
    private static function frame(array $d): array
    {
        $key = json_encode([$d['race'] ?? 'man', $d['bulk'] ?? 1.0]) ?: '';
        if (isset(self::$frames[$key])) {
            return self::$frames[$key];
        }
        $race = (string)($d['race'] ?? 'man');
        $b = (float)($d['bulk'] ?? 1.0);
        $p = self::RACES[$race] ?? self::RACES['man'];
        $hunch = $p['hunch'];
        $hip = 6.2 * $p['legs'];
        $shoulder = $hip + 4.4 * $p['torso'] - 0.35 * $hunch;
        $neck = $shoulder + 0.5;
        $sw = (2.45 + 0.35 * $b) * $p['shoulders'];
        $arms = $p['arms'];
        $f = [
            'race' => $race, 'b' => $b, 'hunch' => $hunch, 'hip' => $hip, 'knee' => 1.05 + ($hip - 1.05) * 0.5,
            'waist' => $hip + 0.9 * $p['torso'], 'shoulder' => $shoulder, 'neck' => $neck,
            'sw' => $sw, 'hw' => 1.1 * (0.7 + 0.3 * $b), 'arms' => $arms, 'head' => $p['head'] * 1.1,
            'hand' => $p['hand'] * (0.8 + 0.2 * $b), 'chest' => $p['chest'], 'belly' => $p['belly'],
            'legsK' => 0.6 + 0.4 * $b, 'armsK' => 0.55 + 0.45 * $b,
        ];
        // the arms' joints, the head's chin, the neck's pivot
        foreach ([1 => 'r', -1 => 'l'] as $s => $side) {
            $sh = [0.15 + 0.5 * $hunch, $shoulder - 0.3, $s * $sw];
            $el = [$sh[0] + 0.15, $sh[1] - 3.0 * $arms, $s * ($sw + 0.45)];
            $wr = [$el[0] + 1.2 * $arms, $el[1] - 2.3 * $arms, $s * ($sw + 0.55)];
            $f["sh_$side"] = $sh;
            $f["el_$side"] = $el;
            $f["wr_$side"] = $wr;
            $f["hand_$side"] = [$wr[0] + 0.5 * $f['hand'], $wr[1] - 0.3 * $f['hand'], $wr[2]];
            $f["hip_$side"] = [0.0, $hip, $s * $f['hw']];
            $f["knee_$side"] = [0.2, $f['knee'], $s * ($f['hw'] + 0.1)];
            $f["ankle_$side"] = [0.0, 1.05, $s * ($f['hw'] + 0.15)];
        }
        $f['neckPivot'] = [0.2 + 0.65 * $hunch, $neck - 0.1, 0.0];
        $f['chin'] = [0.3 + 1.0 * $hunch, $neck + 0.25 - 0.3 * $hunch, 0.0];
        return self::$frames[$key] = $f;
    }

    /** A colour darker or lighter along a part (t 0 at its start: $from, 1 at its end: $to), a little lighter in front. */
    private static function shade(array $col, float $from, float $to): \Closure
    {
        return static fn (float $t, float $a): array => Mesh::times($col, ($from + ($to - $from) * $t) * (1.0 + 0.06 * cos($a)));
    }

    /** A person, its weapon in the right hand (a bow in the left), in its rest pose. */
    private static function person(array $d): Mesh
    {
        $f = self::frame($d);
        $m = new Mesh();
        $race = $f['race'];
        $b = $f['b'];
        $hunch = $f['hunch'];
        $skin = Mesh::rgb($d['skin']);
        $cloth = Mesh::rgb($d['cloth']);
        $legs = Mesh::rgb($d['legs']);
        $leather = Mesh::rgb($d['belt'] ?? '#5c3a24');
        $armor = isset($d['armor']) ? Mesh::rgb($d['armor']) : null;
        $robe = !empty($d['robe']);
        $bare = in_array($race, ['orc', 'troll', 'goblin'], true);
        $lk = $f['legsK'];
        $ak = $f['armsK'];
        $waist = $f['waist'];
        $hip = $f['hip'];
        $sy = $f['shoulder'];

        // ---- legs: thighs, knees, shins, boots (bare feet for the little folk)
        foreach ([1 => 'r', -1 => 'l'] as $s => $side) {
            $m->bone($s > 0 ? self::THIGH_R : self::THIGH_L);
            $m->limb($f["hip_$side"], $f["knee_$side"], [[0.0, 1.18 * $lk], [0.3, 1.3 * $lk], [0.75, 1.02 * $lk], [1.0, 0.84 * $lk]],
                self::shade($legs, 1.0, 0.86), 8);
            $m->bone($s > 0 ? self::SHIN_R : self::SHIN_L);
            $m->ball($f["knee_$side"], [0.86 * $lk, 0.8, 0.86 * $lk], Mesh::times($legs, 0.9), 8, 4);
            $m->limb($f["knee_$side"], $f["ankle_$side"], [[0.0, 0.86 * $lk], [0.3, 0.98 * $lk], [0.85, 0.7 * $lk], [1.0, 0.66 * $lk]],
                self::shade($legs, 0.92, 0.78), 8);
            self::foot($m, $d, $f["ankle_$side"], $s, $lk);
        }

        // ---- hips: the seat, a kilt or the tunic's hem (a robe down to the feet), the belt and its buckle
        $m->bone(self::HIPS);
        $m->lathe([[$hip - 0.7, 1.28 * $b, 1.62 * $b], [$hip + 0.1, 1.5 * $b, 1.9 * $b], [$waist + 0.25, 1.46 * $b, 1.88 * $b]], $legs, 12);
        $bl = $f['belly'];
        if ($robe) {
            $m->lathe([[$waist + 0.1, 1.68 * $b * $bl, 2.08 * $b * $bl], [$hip - 0.4, 2.0 * $b, 2.4 * $b], [2.6, 2.45 * $b, 2.8 * $b], [0.55, 2.7 * $b, 3.05 * $b]],
                self::shade($cloth, 1.04, 0.82), 16, [0, 0, 0], [0, 0, 0], null, 2,
                static fn (float $t, float $a): float => 1.0 + 0.05 * $t * cos(7 * $a));
        } else {
            $kilt = match ($race) {
                'orc', 'troll', 'goblin' => $leather,
                'dwarf' => $armor !== null ? Mesh::times($armor, 0.85) : $cloth,
                default => $cloth,
            };
            $strips = in_array($race, ['orc', 'troll', 'goblin'], true);
            $m->metal($race === 'dwarf' && $armor !== null);
            $m->lathe([[$waist + 0.1, 1.7 * $b * $bl, 2.1 * $b * $bl], [$hip - 0.15, 1.92 * $b, 2.32 * $b], [$hip - 1.55, 2.08 * $b, 2.5 * $b]],
                static fn (float $t, float $a): array => Mesh::times($kilt, (1.02 - 0.2 * $t) * ($strips && sin(5 * $a) > 0.3 ? 0.84 : 1.0)),
                16, [0, 0, 0], [0, 0, 0], null, 2,
                static fn (float $t, float $a): float => 1.0 + ($strips ? 0.08 : 0.05) * $t * cos(8 * $a));
            $m->metal(false);
        }
        $m->lathe([[$waist - 0.35, 1.72 * $b * $bl, 2.12 * $b * $bl], [$waist + 0.35, 1.72 * $b * $bl, 2.12 * $b * $bl]], self::shade($leather, 0.9, 1.05), 16);
        $m->metal(true);
        $m->plate([[-0.42, -0.36], [0.42, -0.36], [0.42, 0.36], [-0.42, 0.36]], [1.72 * $b * $bl + 0.08, $waist, 0.0], [0.0, 0.0, 1.0], [0.0, 1.0, 0.0],
            0.22, Mesh::rgb('#e8c04c'), Mesh::rgb('#b08a2c'));
        $m->metal(false);

        // ---- the body: a tunic, a vest over a bare chest, or a cuirass that shines
        $m->bone(self::CHEST, $armor !== null);
        $chest = $f['chest'];
        $torso = $armor ?? ($bare ? $leather : $cloth);
        $h = $hunch;
        $rings = [
            [$waist - 0.35, 1.42 * $b * $bl, 1.82 * $b * $bl, 0.0],
            [$waist + 0.6, 1.58 * $b * $bl, 2.05 * $b * $bl, 0.08 + 0.12 * $h],
            [$waist + 1.6, 1.8 * $b * $chest, 2.4 * $b * $chest, 0.18 + 0.3 * $h],
            [$sy - 0.95, 1.9 * $b * $chest, 2.68 * $b * $chest, 0.25 + 0.45 * $h],
            [$sy - 0.2, 1.62 * $b * $chest, 2.62 * $b * $chest, 0.2 + 0.55 * $h],
            [$sy + 0.3, 1.05 * $b, 1.6 * $b, 0.15 + 0.6 * $h],
            [$f['neck'] + 0.05, 0.66 * (0.7 + 0.3 * $b), 0.72 * (0.7 + 0.3 * $b), 0.2 + 0.65 * $h],
        ];
        $open = $bare && $armor === null;
        $m->lathe($rings, static function (float $t, float $a) use ($torso, $skin, $open, $armor): array {
            // a vest open on a bare chest (orcs, trolls, goblins), a trim round the cuirass' edge
            if ($open && cos($a) > 0.55 && $t > 0.25) {
                return Mesh::times($skin, 0.92 + 0.15 * $t);
            }
            $k = (0.84 + 0.24 * $t) * (1.0 + 0.07 * cos($a));
            if ($armor !== null && ($t < 0.08 || abs(sin($a)) < 0.12 && cos($a) > 0)) {
                $k *= 0.78;
            }
            return Mesh::times($torso, $k);
        }, 16, [0, 0, 0], [0, 0, 0], null, 2);
        $m->metal(false);
        if ($armor !== null || isset($d['cape'])) {
            // a collar
            $m->limb([$rings[5][3] - 0.2, $sy + 0.1, 0.0], [$rings[6][3], $f['neck'] + 0.25, 0.0], [[0.0, 1.35 * $b], [1.0, 0.95 * $b]],
                Mesh::times($armor ?? Mesh::rgb($d['cape']), 0.8), 14);
        }
        if (($d['weapon'][0] ?? '') === 'bow') {
            // a quiver of arrows on the back
            $q0 = [-1.7 * $b, $waist + 1.4, -0.6];
            $q1 = [-2.2 * $b, $sy + 1.1, 0.7];
            $m->limb($q0, $q1, [[0.0, 0.0], [0.05, 0.62], [0.95, 0.66], [1.0, 0.5]], self::shade($leather, 0.9, 1.1), 8);
            foreach ([-0.25, 0.0, 0.25] as $k => $dz) {
                $m->limb([$q1[0], $q1[1] - 0.2, $q1[2] + $dz], [$q1[0] - 0.15, $q1[1] + 1.1, $q1[2] + $dz + 0.1], [[0.0, 0.12], [0.6, 0.22], [1.0, 0.0]],
                    Mesh::rgb($k === 1 ? '#e8e0d0' : '#c84a3a'), 4);
            }
        }

        // ---- arms: round shoulders, upper arms, elbows, forearms with bracers, big fists; pauldrons
        $pads = $armor ?? (isset($d['pads']) ? Mesh::rgb($d['pads']) : null);
        foreach ([1 => 'r', -1 => 'l'] as $s => $side) {
            $sh = $f["sh_$side"];
            $el = $f["el_$side"];
            $wr = $f["wr_$side"];
            $sleeve = $bare ? $skin : ($armor !== null && $race !== 'dwarf' ? $skin : $cloth);
            $m->bone($s > 0 ? self::ARM_R : self::ARM_L);
            $m->ball($sh, [1.08 * $ak, 1.02 * $ak, 1.05 * $ak], Mesh::times($sleeve, 1.02), 8, 4);
            $m->limb($sh, $el, [[0.0, 0.98 * $ak], [0.3, 1.1 * $ak], [0.75, 0.86 * $ak], [1.0, 0.74 * $ak]], self::shade($sleeve, 1.04, 0.9), 8);
            if ($pads !== null) {
                $m->metal($armor !== null);
                $base = [$sh[0], $sh[1] + 0.1, $s * ($f['sw'] - 0.45)];
                $tip = [$sh[0] - 0.1, $sh[1] + 1.05, $s * ($f['sw'] + 1.2 * $ak)];
                $m->lathe([[0.0, 1.55 * $ak, 1.4 * $ak], [0.45, 1.5 * $ak, 1.36 * $ak], [0.85, 0.95 * $ak, 0.86 * $ak], [1.0, 0.0, 0.0]],
                    self::shade(Mesh::times($pads, 1.05), 0.82, 1.12), 12, $base, [0, 0, 0], $tip);
                if (in_array($race, ['orc', 'troll'], true)) {
                    // spikes on an orc's pauldrons
                    foreach ([-0.6, 0.4] as $dx) {
                        $root = [$sh[0] + $dx, $sh[1] + 1.15, $s * ($f['sw'] + 0.3)];
                        $m->limb($root, [$root[0] - 0.2, $root[1] + 1.3, $root[2] + $s * 0.35], [[0.0, 0.34], [1.0, 0.0]], Mesh::rgb('#d8d0c0'), 6);
                    }
                }
                $m->metal(false);
            }
            $m->bone($s > 0 ? self::FORE_R : self::FORE_L);
            $fore = $bare || $armor !== null ? $skin : $cloth;
            $m->ball($el, [0.78 * $ak, 0.76 * $ak, 0.78 * $ak], Mesh::times($fore, 0.96), 8, 4);
            $m->limb($el, $wr, [[0.0, 0.78 * $ak], [0.3, 0.92 * $ak], [1.0, 0.6 * $ak]], self::shade($fore, 1.0, 0.92), 8);
            // a bracer
            $mid = [$el[0] + ($wr[0] - $el[0]) * 0.5, $el[1] + ($wr[1] - $el[1]) * 0.5, $el[2] + ($wr[2] - $el[2]) * 0.5];
            $end = [$wr[0] + ($wr[0] - $el[0]) * 0.04, $wr[1] + ($wr[1] - $el[1]) * 0.04, $wr[2]];
            $m->metal($armor !== null);
            $m->limb($mid, $end, [[0.0, 0.94 * $ak], [0.15, 0.98 * $ak], [1.0, 0.74 * $ak]], self::shade($armor ?? $leather, 1.08, 0.86), 8);
            $m->metal(false);
            $m->bone($s > 0 ? self::HAND_R : self::HAND_L);
            $hk = $f['hand'];
            $hand = $f["hand_$side"];
            $m->ball($hand, [0.74 * $hk, 0.78 * $hk, 0.64 * $hk], Mesh::times($skin, 0.96), 8, 5, [0, 0, 0], Mesh::times($skin, 1.06));
            $m->ball([$hand[0] + 0.38 * $hk, $hand[1] + 0.22 * $hk, $hand[2] - $s * 0.36 * $hk], [0.3 * $hk, 0.26 * $hk, 0.26 * $hk], $skin, 6, 3);
        }

        // ---- the head and the neck
        $m->bone(self::HEAD);
        $np = $f['neckPivot'];
        $chin = $f['chin'];
        $nr = 0.75 * (0.7 + 0.3 * $b) * ($bare ? 1.25 : 1.0);
        $m->limb([$np[0] - 0.15, $np[1] - 0.2, 0.0], [$chin[0] - 0.3, $chin[1] + 0.9, 0.0], [[0.0, $nr], [1.0, 0.85 * $nr]], Mesh::times($skin, 0.88), 8);
        self::head($m, $d, $f);

        // ---- the cape, the weapon, the shield
        if (isset($d['cape'])) {
            self::cape($m, $f, Mesh::rgb($d['cape']));
        }
        $weapon = $d['weapon'] ?? null;
        if ($weapon !== null) {
            // oversized, as fighters' weapons read best from afar: grown about the fist that holds them
            $arms = new Mesh();
            self::weapon($arms, (string)$weapon[0], Mesh::rgb($weapon[1]), $f);
            if (!in_array($weapon[0], ['staff', 'bow'], true)) {
                self::growAbout($arms, [self::HAND_R => $f['hand_r'], self::HAND_L => $f['hand_l']], 1.22);
            }
            $m->add($arms);
        }
        if (isset($d['shield'])) {
            self::shield($m, Mesh::rgb($d['shield']), $f, $race);
        }
        return $m;
    }

    /** A boot (its shaft with the shin, its foot on the foot's bone: kept flat on the ground) or a bare foot. */
    private static function foot(Mesh $m, array $d, array $ankle, int $s, float $lk): void
    {
        [$x, , $z] = $ankle;
        $bone = $s > 0 ? self::FOOT_R : self::FOOT_L;
        if (!empty($d['barefoot'])) {
            $m->bone($bone);
            $skin = Mesh::rgb($d['skin']);
            $m->limb([$x - 0.65, 0.5, $z], [$x + 2.2, 0.42, $z + $s * 0.15], [[0.0, 0.0], [0.1, 0.4], [0.35, 0.5], [0.75, 0.48], [0.95, 0.34], [1.0, 0.0]],
                self::shade($skin, 0.95, 1.0), 10, 1.55);
            // hairy on top, toes in front
            $m->ball([$x + 0.6, 0.85, $z], [0.9, 0.3, 0.6], Mesh::rgb($d['hair'] ?? '#8a5a2a'), 6, 3);
            foreach ([-0.42, 0.0, 0.42] as $dz) {
                $m->ball([$x + 2.05, 0.3, $z + $s * 0.15 + $dz], [0.24, 0.2, 0.2], Mesh::times($skin, 1.02), 5, 3);
            }
            return;
        }
        $boot = Mesh::rgb($d['boots'] ?? '#4a3020');
        // the shaft round the ankle, its cuff turned down
        $m->limb([$x - 0.05, 2.75, $z], [$x, 0.85, $z], [[0.0, 1.06 * $lk], [0.12, 1.14 * $lk], [0.28, 0.96 * $lk], [1.0, 0.9 * $lk]],
            self::shade(Mesh::times($boot, 1.12), 1.0, 0.86), 8);
        // the foot: a round toe, a flat sole
        $m->bone($bone);
        $m->limb([$x - 0.75, 0.52, $z], [$x + 2.05, 0.44, $z], [[0.0, 0.0], [0.08, 0.4], [0.3, 0.52], [0.7, 0.5], [0.93, 0.38], [1.0, 0.0]],
            self::shade($boot, 0.9, 1.05), 10, 1.5);
    }

    /** Where the front of a head is at a height above its chin (its rings, relative). */
    private static function front(array $rings, float $y): float
    {
        $n = count($rings);
        for ($i = 1; $i < $n; $i++) {
            if ($y <= $rings[$i][0] || $i === $n - 1) {
                [$y0, $rx0, , $dx0] = $rings[$i - 1];
                [$y1, $rx1, , $dx1] = $rings[$i];
                $t = $y1 > $y0 ? max(0.0, min(1.0, ($y - $y0) / ($y1 - $y0))) : 0.0;
                return $dx0 + $rx0 + ($dx1 + $rx1 - $dx0 - $rx0) * $t;
            }
        }
        return 0.0;
    }

    /** How wide (half) a head is at a height above its chin. */
    private static function side(array $rings, float $y): float
    {
        return self::ringAt($rings, $y)[1];
    }

    /** A head's ring at a height above its chin, between its given ones: [half depth, half width, forwards]. @return array{0: float, 1: float, 2: float} */
    private static function ringAt(array $rings, float $y): array
    {
        $n = count($rings);
        for ($i = 1; $i < $n; $i++) {
            if ($y <= $rings[$i][0] || $i === $n - 1) {
                [$y0, $rx0, $rz0, $dx0] = $rings[$i - 1];
                [$y1, $rx1, $rz1, $dx1] = $rings[$i];
                $t = $y1 > $y0 ? max(0.0, min(1.0, ($y - $y0) / ($y1 - $y0))) : 0.0;
                return [$rx0 + ($rx1 - $rx0) * $t, $rz0 + ($rz1 - $rz0) * $t, $dx0 + ($dx1 - $dx0) * $t];
            }
        }
        return [0.0, 0.0, 0.0];
    }

    /**
     * A shell over a head (a helmet, a hood, hair): its rings at those heights above the chin (head units), $grow
     * times the head's, moved back by $back and up by $lift, closed over the top.
     * @param list<float> $heights
     * @return list<array<int, float>>
     */
    private static function shell(array $rings, array $heights, float $grow, float $back, float $lift, float $hx, float $hy): array
    {
        $out = [];
        foreach ($heights as $h) {
            [$rx, $rz, $dx] = self::ringAt($rings, $h);
            $out[] = [$hy + $h + $lift, $rx * $grow, $rz * $grow, $hx + $dx - $back, 0.0];
        }
        $top = $rings[count($rings) - 1];
        $out[] = [$hy + $top[0] + $lift + 0.1 * $grow, 0.0, 0.0, $hx + $top[3] - $back, 0.0];
        return $out;
    }

    /** The skull of a person's people, its face (eyes, brows, nose, mouth, ears, tusks), its hair, beard, helmet. */
    private static function head(Mesh $m, array $d, array $f): void
    {
        $race = $f['race'];
        $k = $f['head'];
        $rings = array_map(static fn (array $r): array => [$r[0] * $k, $r[1] * $k, $r[2] * $k, $r[3] * $k], self::HEADS[$race] ?? self::HEADS['man']);
        [$hx, $hy] = $f['chin'];
        $skin = Mesh::rgb($d['skin']);
        $top = $rings[count($rings) - 1][0];
        $rosy = in_array($race, ['man', 'halfling', 'dwarf'], true);
        $m->lathe(array_map(static fn (array $r): array => [$hy + $r[0], $r[1], $r[2], $hx + $r[3], 0.0], $rings),
            static function (float $t, float $a) use ($skin, $rosy): array {
                $c = Mesh::times($skin, 0.84 + 0.22 * min(1.0, $t * 1.6));
                // rosy cheeks
                if ($rosy && $t > 0.2 && $t < 0.48 && cos($a) > 0.35 && abs(sin($a)) > 0.45) {
                    $c = Mesh::mix($c, [0.95, 0.55, 0.5], 0.35);
                }
                return $c;
            }, 14, [0, 0, 0], [0, 0, 0], null, 2);

        $at = static fn (float $y, float $in = 0.0): float => $hx + self::front($rings, $y * $k) - $in * $k;
        $eyeY = match ($race) {
            'orc' => 1.78, 'troll' => 1.5, 'goblin' => 1.9, 'halfling' => 1.72, 'elf' => 2.0, default => 1.9,
        };
        $eyeZ = match ($race) {
            'orc' => 0.62, 'troll' => 0.58, 'goblin' => 0.72, 'halfling' => 0.6, 'dwarf' => 0.58, default => 0.55,
        } * $k;
        $glow = in_array($race, ['orc', 'troll', 'goblin'], true);
        $eye = Mesh::rgb($d['eye'] ?? '#2a2a2a', $glow ? 1.35 : 1.0);
        $ey = $hy + $eyeY * $k;
        foreach ([-1, 1] as $s) {
            $ex = $at($eyeY, 0.16);
            if ($glow) {
                $m->ball([$ex + 0.06, $ey, $s * $eyeZ], [0.2 * $k, 0.16 * $k, 0.2 * $k], $eye, 6, 4);
            } else {
                $m->ball([$ex, $ey, $s * $eyeZ], [0.24 * $k, 0.24 * $k, 0.26 * $k], Mesh::rgb('#f4f0e8'), 6, 4);
                $m->ball([$ex + 0.16 * $k, $ey, $s * $eyeZ], [0.13 * $k, 0.17 * $k, 0.15 * $k], $eye, 5, 3);
            }
        }

        // brows: heavy and angry on orcs, bushy on dwarves
        $brow = match ($race) {
            'dwarf' => Mesh::rgb($d['beard'] ?? '#8a5a2a'),
            'elf', 'man', 'halfling' => Mesh::rgb($d['hair'] ?? '#5a4030', 0.9),
            default => Mesh::times($skin, 0.72),
        };
        $by = $eyeY + ($glow ? 0.3 : 0.42);
        foreach ([-1, 1] as $s) {
            $inner = [$at($by, 0.1), $hy + ($by - ($glow ? 0.14 : 0.0)) * $k, $s * 0.18 * $k];
            $outer = [$at($by, 0.45), $hy + ($by + ($glow ? 0.16 : 0.04)) * $k, $s * ($eyeZ + 0.5 * $k)];
            $r = match ($race) {
                'orc', 'troll' => 0.3, 'dwarf' => 0.28, 'goblin' => 0.2, default => 0.13,
            } * $k;
            $m->limb($inner, $outer, [[0.0, $r * 0.7], [0.4, $r], [1.0, $r * 0.55]], $brow, 6);
        }

        // the nose
        $noseY = $eyeY - 0.15;
        $nx = $at($noseY, 0.05);
        switch ($race) {
            case 'goblin':
                $m->limb([$nx - 0.2, $hy + $noseY * $k, 0.0], [$nx + 1.5 * $k, $hy + ($noseY - 0.75) * $k, 0.0], [[0.0, 0.34 * $k], [0.5, 0.26 * $k], [1.0, 0.0]], Mesh::times($skin, 0.95), 7);
                break;
            case 'dwarf':
                $m->ball([$nx + 0.22 * $k, $hy + ($noseY - 0.5) * $k, 0.0], [0.42 * $k, 0.4 * $k, 0.4 * $k], Mesh::mix($skin, [0.92, 0.55, 0.5], 0.25), 7, 4);
                break;
            case 'orc':
            case 'troll':
                $m->ball([$nx + 0.08 * $k, $hy + ($noseY - 0.42) * $k, 0.0], [0.3 * $k, 0.26 * $k, 0.42 * $k], Mesh::times($skin, 0.9), 7, 4);
                break;
            default:
                $m->limb([$nx - 0.1 * $k, $hy + ($noseY + 0.1) * $k, 0.0], [$nx + 0.3 * $k, $hy + ($noseY - 0.5) * $k, 0.0], [[0.0, 0.14 * $k], [0.7, 0.24 * $k], [1.0, 0.16 * $k]], Mesh::times($skin, 0.98), 6);
        }

        // the mouth: a line, or the wide mouth of an orc with its tusks
        if (!isset($d['beard'])) {
            $my = $glow ? 0.62 : 0.82;
            $mw = ($glow ? 0.85 : 0.42) * $k;
            $mx = $at($my, 0.06);
            $m->limb([$mx, $hy + $my * $k, -$mw], [$mx, $hy + $my * $k, $mw], [[0.0, 0.05 * $k], [0.5, 0.09 * $k], [1.0, 0.05 * $k]], Mesh::rgb('#3a1e1a'), 4);
        }
        if (in_array($race, ['orc', 'troll'], true)) {
            foreach ([-1, 1] as $s) {
                $root = [$at(0.55, 0.25), $hy + 0.55 * $k, $s * 0.62 * $k];
                $m->limb($root, [$root[0] + 0.32 * $k, $root[1] + ($race === 'troll' ? 1.25 : 0.95) * $k, $s * 0.82 * $k], [[0.0, 0.2 * $k], [0.6, 0.15 * $k], [1.0, 0.0]], Mesh::rgb('#f2ead2'), 6);
            }
        }

        // ears
        $earY = $eyeY - 0.05;
        $ez = self::side($rings, $earY * $k);
        $ex = $hx + 0.0;
        foreach ([-1, 1] as $s) {
            $root = [$ex - 0.1 * $k, $hy + $earY * $k, $s * ($ez - 0.1 * $k)];
            switch ($race) {
                case 'elf':
                    $m->limb($root, [$root[0] - 0.75 * $k, $root[1] + 1.5 * $k, $s * ($ez + 1.0 * $k)], [[0.0, 0.32 * $k], [0.45, 0.3 * $k], [1.0, 0.0]], $skin, 6, 0.45);
                    break;
                case 'goblin':
                    $m->limb($root, [$root[0] - 0.3 * $k, $root[1] + 0.7 * $k, $s * ($ez + 2.6 * $k)], [[0.0, 0.5 * $k], [0.3, 0.58 * $k], [1.0, 0.0]], Mesh::times($skin, 0.95), 7, 0.4);
                    break;
                case 'orc':
                case 'troll':
                    $m->limb($root, [$root[0] - 0.55 * $k, $root[1] + 0.45 * $k, $s * ($ez + 1.0 * $k)], [[0.0, 0.32 * $k], [0.4, 0.3 * $k], [1.0, 0.0]], Mesh::times($skin, 0.92), 6, 0.5);
                    break;
                default:
                    if (!isset($d['helm']) || $d['helm'][0] === 'crown' || $d['helm'][0] === 'hat') {
                        $m->ball([$root[0], $root[1], $s * $ez], [0.34 * $k, 0.48 * $k, 0.2 * $k], Mesh::times($skin, 0.94), 6, 3);
                    }
            }
        }

        // hair, beard, helmet
        $hair = isset($d['hair']) ? Mesh::rgb($d['hair']) : null;
        $helm = $d['helm'] ?? null;
        $style = (string)($d['style'] ?? '');
        if ($hair !== null && ($helm === null || in_array($helm[0], ['crown', 'hat', 'cap'], true))) {
            self::hair($m, $style, $hair, $rings, $hx, $hy, $k);
        }
        if (isset($d['beard'])) {
            self::beard($m, Mesh::rgb($d['beard']), $at, $hy, $k);
        }
        if ($helm !== null) {
            self::helm($m, (string)$helm[0], Mesh::rgb($helm[1]), $rings, $hx, $hy, $k, $top);
        }
    }

    /** Hair: long (down the back), a crest (orcs, trolls), curls (the little folk); none: bald. */
    private static function hair(Mesh $m, string $style, array $col, array $rings, float $hx, float $hy, float $k): void
    {
        $top = $rings[count($rings) - 1][0];
        $cap = static fn (float $grow, float $back): array => self::shell($rings, [1.3 * $k, 1.9 * $k, 0.5 * (1.9 * $k + $top), $top - 0.35 * $k],
            $grow, $back, 0.0, $hx, $hy);
        switch ($style) {
            case 'long':
                $m->lathe($cap(1.08, 0.18), self::shade($col, 0.9, 1.12), 14, [0, 0, 0], [0, 0, 0], null, 2);
                // down the back, flowing
                $m->limb([$hx - 1.0 * $k, $hy + 2.6 * $k, 0.0], [$hx - 1.9 * $k, $hy - 2.2 * $k, 0.0], [[0.0, 1.2 * $k], [0.35, 1.15 * $k], [0.85, 0.6 * $k], [1.0, 0.0]],
                    self::shade($col, 1.05, 0.8), 10, 1.15);
                break;
            case 'crest':
                // a crest of hair from the brow to the nape
                for ($i = 0; $i < 5; $i++) {
                    $t = $i / 4;
                    $a = M_PI * (0.18 + 0.62 * $t);
                    $root = [$hx + cos($a) * 1.35 * $k - 0.2, $hy + (2.0 + sin($a) * 1.25) * $k, 0.0];
                    $m->limb($root, [$root[0] - 0.55 * $k, $root[1] + (0.9 - 0.2 * abs($t - 0.4)) * $k, 0.0], [[0.0, 0.42 * $k], [1.0, 0.0]], Mesh::times($col, 0.9 + 0.2 * $t), 6, 0.55);
                }
                break;
            case 'curls':
                $m->lathe($cap(1.06, 0.1), self::shade($col, 0.92, 1.08), 14, [0, 0, 0], [0, 0, 0], null, 2);
                for ($i = 0; $i < 14; $i++) {
                    $a = $i * 2.4;
                    $u = 0.25 + 0.7 * Mesh::hash($i, 41);
                    $y = $hy + (1.5 + 1.6 * $u) * $k;
                    $rad = self::side($rings, ($y - $hy)) * 1.02;
                    $m->ball([$hx - 0.1 + cos($a) * $rad * 0.95 - 0.15, $y, sin($a) * $rad], [0.48 * $k, 0.44 * $k, 0.48 * $k], Mesh::times($col, 0.85 + 0.3 * Mesh::hash($i, 43)), 6, 3);
                }
                break;
        }
    }

    /** A dwarf's beard: big, down to the chest, two braids; a moustache. @param callable(float, float): float $at */
    private static function beard(Mesh $m, array $col, callable $at, float $hy, float $k): void
    {
        $mx = $at(0.95, 0.35);
        $m->lathe([[0.0, 0.0, 0.0], [0.08, 1.05 * $k, 1.3 * $k], [0.35, 1.25 * $k, 1.55 * $k], [0.7, 1.0 * $k, 1.25 * $k], [1.0, 0.0, 0.0]],
            self::shade($col, 1.08, 0.86), 12, [$mx - 0.2 * $k, $hy + 1.35 * $k, 0.0], [0, 0, 0], [$mx + 0.6 * $k, $hy - 2.4 * $k, 0.0], 2);
        foreach ([-1, 1] as $s) {
            $m->limb([$mx + 0.2 * $k, $hy + 1.15 * $k, $s * 0.1], [$mx + 0.1 * $k, $hy + 0.55 * $k, $s * 0.95 * $k], [[0.0, 0.3 * $k], [1.0, 0.1 * $k]], Mesh::times($col, 1.1), 6);
            $m->limb([$mx + 0.5 * $k, $hy - 2.0 * $k, $s * 0.55 * $k], [$mx + 0.7 * $k, $hy - 3.2 * $k, $s * 0.55 * $k], [[0.0, 0.32 * $k], [0.8, 0.24 * $k], [1.0, 0.0]], Mesh::times($col, 0.92), 6);
        }
    }

    /** A helmet, a hood, a cap, a hat or a crown. */
    private static function helm(Mesh $m, string $type, array $col, array $rings, float $hx, float $hy, float $k, float $top): void
    {
        $hi = Mesh::times($col, 1.15);
        $shell = static fn (float $from, float $grow, float $back, float $lift = 0.0): array => self::shell($rings,
            [$from * $k, 0.5 * ($from * $k + $top), $top - 0.3 * $k], $grow, $back, $lift, $hx, $hy);
        switch ($type) {
            case 'helm':
            case 'horns':
                $m->metal(true);
                $m->lathe($shell(2.0, 1.12, 0.05, 0.0), self::shade($col, 0.86, 1.15), 14, [0, 0, 0], [0, 0, 0], null, 2);
                // its rim, a nose guard, a ridge on top
                [$rrx, $rrz, $rdx] = self::ringAt($rings, 2.05 * $k);
                $rim = [$hy + 2.05 * $k];
                $m->lathe([[$rim[0] - 0.24, $rrx * 1.17, $rrz * 1.17, $hx + $rdx - 0.05], [$rim[0] + 0.24, $rrx * 1.15, $rrz * 1.15, $hx + $rdx - 0.05]],
                    Mesh::times($col, 0.78), 14);
                $m->plate([[-0.16, 0.0], [0.16, 0.0], [0.12, -1.0], [0.0, -1.2], [-0.12, -1.0]], [$hx + self::front($rings, 2.0 * $k) + 0.08, $rim[0] + 0.1, 0.0],
                    [0.0, 0.0, 1.0], [0.0, 1.0, 0.0], 0.2 * $k, $hi);
                $m->limb([$hx + 1.2 * $k, $hy + $top + 0.15, 0.0], [$hx - 1.4 * $k, $hy + $top - 0.35, 0.0], [[0.0, 0.0], [0.2, 0.28 * $k], [0.8, 0.28 * $k], [1.0, 0.0]], $hi, 6, 0.5);
                if ($type === 'horns') {
                    foreach ([-1, 1] as $s) {
                        $a = [$hx - 0.1, $hy + 2.75 * $k, $s * 1.35 * $k];
                        $b = [$hx + 0.4 * $k, $hy + 3.4 * $k, $s * 2.6 * $k];
                        $c = [$hx + 1.0 * $k, $hy + 4.9 * $k, $s * 2.9 * $k];
                        $e = [$hx + 1.1 * $k, $hy + 6.1 * $k, $s * 2.4 * $k];
                        $bone = Mesh::rgb('#ece4cc');
                        $m->metal(false);
                        $m->limb($a, $b, [[0.0, 0.62 * $k], [1.0, 0.5 * $k]], Mesh::times($bone, 0.85), 8);
                        $m->limb($b, $c, [[0.0, 0.5 * $k], [1.0, 0.34 * $k]], $bone, 8);
                        $m->limb($c, $e, [[0.0, 0.34 * $k], [1.0, 0.0]], Mesh::times($bone, 1.08), 8);
                    }
                }
                $m->metal(false);
                break;
            case 'cap':
                $m->metal(true);
                $m->lathe($shell(2.1, 1.1, 0.05, 0.05), self::shade($col, 0.85, 1.12), 14, [0, 0, 0], [0, 0, 0], null, 2);
                $m->metal(false);
                $rimY = $hy + 2.15 * $k;
                $m->lathe([[$rimY - 0.12, 1.9 * $k, 1.8 * $k, $hx + 0.25], [$rimY + 0.12, 1.86 * $k, 1.76 * $k, $hx + 0.22]], Mesh::times($col, 0.8), 14);
                // a lamp in front (the miners)
                $m->ball([$hx + self::front($rings, 2.5 * $k) + 0.2, $hy + 2.55 * $k, 0.0], [0.38 * $k, 0.38 * $k, 0.38 * $k], Mesh::rgb('#fff2a0', 1.2), 6, 4);
                break;
            case 'hood':
                $m->lathe(array_merge([[$hy + 0.25 * $k, 1.42 * $k, 1.62 * $k, $hx - 0.55 * $k, 0.0]],
                    self::shell($rings, [1.0 * $k, 1.8 * $k, 2.5 * $k, $top - 0.35 * $k], 1.15, 0.4 * $k, 0.12, $hx, $hy)),
                    self::shade($col, 0.8, 1.08), 14, [0, 0, 0], [0, 0, 0], null, 2);
                // the point of the hood, falling on the back
                $m->limb([$hx - 1.1 * $k, $hy + 2.9 * $k, 0.0], [$hx - 2.6 * $k, $hy + 1.2 * $k, 0.0], [[0.0, 0.9 * $k], [1.0, 0.0]], Mesh::times($col, 0.9), 8);
                // over the shoulders
                $m->lathe([[$hy - 0.5, 2.1 * $k, 2.5 * $k, $hx - 0.6 * $k], [$hy + 0.6 * $k, 1.5 * $k, 1.75 * $k, $hx - 0.55 * $k]], self::shade($col, 0.78, 0.92), 14);
                break;
            case 'hat':
                $brimY = $hy + 2.6 * $k;
                $m->lathe([[$brimY - 0.1, 0.0, 0.0, $hx], [$brimY - 0.08, 3.0 * $k, 2.8 * $k, $hx], [$brimY + 0.12, 3.1 * $k, 2.9 * $k, $hx], [$brimY + 0.2, 1.5 * $k, 1.4 * $k, $hx],
                    [$brimY + 1.6 * $k, 1.35 * $k, 1.25 * $k, $hx - 0.2], [$brimY + 1.9 * $k, 0.0, 0.0, $hx - 0.25]], self::shade($col, 0.8, 1.1), 14);
                $m->limb([$hx - 0.8 * $k, $brimY + 1.2 * $k, 1.0 * $k], [$hx - 3.6 * $k, $brimY + 3.0 * $k, 1.6 * $k], [[0.0, 0.3 * $k], [0.4, 0.42 * $k], [1.0, 0.0]], Mesh::rgb('#f4f0e8'), 6, 0.35);
                break;
            case 'crown':
                $m->metal(true);
                $y0 = $hy + 2.55 * $k;
                $m->lathe([[$y0, 1.62 * $k, 1.48 * $k, $hx + 0.02], [$y0 + 0.7 * $k, 1.66 * $k, 1.52 * $k, $hx]], self::shade($col, 0.9, 1.1), 14);
                for ($i = 0; $i < 7; $i++) {
                    $a = $i * 2 * M_PI / 7;
                    $root = [$hx + cos($a) * 1.6 * $k, $y0 + 0.6 * $k, sin($a) * 1.48 * $k];
                    $m->limb($root, [$root[0] * 1.0 + cos($a) * 0.1, $root[1] + 0.8 * $k, $root[2] + sin($a) * 0.1], [[0.0, 0.26 * $k], [1.0, 0.0]], $hi, 4);
                }
                $m->metal(false);
                $m->ball([$hx + 1.66 * $k + 0.05, $y0 + 0.35 * $k, 0.0], [0.2 * $k, 0.26 * $k, 0.26 * $k], Mesh::rgb('#e84a4a', 1.2), 5, 3);
                break;
        }
    }

    /** The cape: from the shoulders down to the knees, round the back, in folds. */
    private static function cape(Mesh $m, array $f, array $colour): void
    {
        $m->bone(self::CAPE);
        $b = $f['b'];
        $top = $f['shoulder'] + 0.25;
        $bottom = max(1.6, $f['hip'] - 2.6);
        $grid = [];
        $rows = 6;
        $cols = 9;
        for ($r = 0; $r < $rows; $r++) {
            $v = $r / ($rows - 1);
            $y = $top + ($bottom - $top) * $v;
            $half = (2.2 + 0.7 * $v) * $b;
            for ($c = 0; $c < $cols; $c++) {
                $u = $c / ($cols - 1) * 2 - 1;
                $a = $u * 1.45;
                $x = -cos($a) * (1.25 + 0.95 * $v) * $b - 0.3 - 0.2 * $v + $f['hunch'] * 0.5 * (1 - $v);
                $fold = 0.22 * $v * sin($u * 7.5);
                $grid[$r][$c] = [$x - $fold, $y, sin($a) * $half];
            }
        }
        $m->sheet($grid, static fn (float $row, float $col): array => Mesh::times($colour, (1.02 - 0.24 * $row) * (1.0 - 0.08 * abs(sin($col * 23.6)))),
            [0.0, $top - 1.5, 0.0]);
    }

    /** Every vertex of $m grown by $k about the point of its bone ($centres: bone => point; others stay). */
    private static function growAbout(Mesh $m, array $centres, float $k): void
    {
        for ($i = 0, $n = count($m->v); $i < $n; $i += Mesh::STRIDE) {
            $mat = $m->v[$i + 9];
            $c = $centres[(int)round($mat > Mesh::METAL_BONE - 0.5 ? $mat - Mesh::METAL_BONE : $mat - Mesh::BONE)] ?? null;
            if ($c !== null) {
                for ($a = 0; $a < 3; $a++) {
                    $m->v[$i + $a] = $c[$a] + ($m->v[$i + $a] - $c[$a]) * $k;
                }
            }
        }
    }

    /** @return array{0: float, 1: float, 2: float} */
    private static function unit(array $v): array
    {
        $l = sqrt($v[0] ** 2 + $v[1] ** 2 + $v[2] ** 2) ?: 1.0;
        return [$v[0] / $l, $v[1] / $l, $v[2] / $l];
    }

    /** $p + $d * $t. @return array{0: float, 1: float, 2: float} */
    private static function along(array $p, array $d, float $t): array
    {
        return [$p[0] + $d[0] * $t, $p[1] + $d[1] * $t, $p[2] + $d[2] * $t];
    }

    /** The weapon, in the right fist (a bow in the left; twin blades in both), standing up a little forwards. */
    private static function weapon(Mesh $m, string $type, array $col, array $f): void
    {
        $wood = Mesh::rgb('#7a5434');
        $grip = Mesh::rgb('#4a2e1c');
        $gold = Mesh::rgb('#e2b84a');
        $steel = Mesh::rgb('#d4d8e0');
        $hi = Mesh::times($col, 1.18);
        // up, a little forwards and away from the body
        $d = self::unit([0.5, 1.0, 0.22]);
        // the width of a blade: forwards, square to the haft
        $w = self::unit([$d[1], -$d[0], 0.0]);
        $hand = $f['hand_r'];
        $m->bone(self::HAND_R);
        switch ($type) {
            case 'club':
                $m->limb(self::along($hand, $d, -1.0), self::along($hand, $d, 6.8), [[0.0, 0.3], [0.15, 0.36], [0.5, 0.62], [0.88, 0.98], [1.0, 0.0]],
                    self::shade($col, 0.9, 1.15), 9);
                $m->metal(true);
                foreach ([[3.6, 0.3], [4.6, 2.4], [5.3, 4.5], [4.2, 3.6], [5.6, 1.2]] as [$t, $a]) {
                    $p = self::along($hand, $d, $t);
                    $out = [$w[0] * cos($a), $w[1] * cos($a), sin($a)];
                    $r = 0.32 + 0.66 * ($t + 1.0) / 7.8;
                    $m->limb(self::along($p, $out, $r * 0.8), self::along($p, $out, $r + 0.6), [[0.0, 0.2], [1.0, 0.0]], Mesh::rgb('#b8b8c0'), 5);
                }
                $m->metal(false);
                break;
            case 'axe':
                $m->limb(self::along($hand, $d, -1.4), self::along($hand, $d, 6.6), [[0.0, 0.27], [1.0, 0.24]], self::shade($wood, 0.88, 1.1), 7);
                $m->metal(true);
                $o = self::along($hand, $d, 5.5);
                $m->plate([[0.0, 1.0], [0.0, -0.8], [0.9, -1.1], [1.7, -1.9], [2.35, -1.45], [2.55, 0.1], [2.35, 1.6], [1.6, 1.95], [0.8, 1.3]],
                    $o, $w, $d, 0.36, $col, $hi);
                $m->plate([[0.0, 0.45], [0.0, -0.45], [-1.2, 0.0]], $o, $w, $d, 0.32, Mesh::times($col, 0.85));
                $m->limb(self::along($o, $d, -0.95), self::along($o, $d, 1.15), [[0.0, 0.34], [1.0, 0.34]], Mesh::times($col, 0.7), 7);
                $m->metal(false);
                break;
            case 'sword':
                $m->limb(self::along($hand, $d, -0.8), self::along($hand, $d, 0.8), [[0.0, 0.24], [1.0, 0.24]], $grip, 6);
                $m->metal(true);
                $m->ball(self::along($hand, $d, -0.9), [0.32, 0.32, 0.32], $gold, 6, 4);
                $g = self::along($hand, $d, 0.85);
                $m->limb(self::along($g, $w, -1.4), self::along($g, $w, 1.4), [[0.0, 0.2], [0.5, 0.28], [1.0, 0.2]], $gold, 6);
                $m->plate([[-0.48, 0.0], [0.48, 0.0], [0.42, 4.6], [0.0, 5.6], [-0.42, 4.6]], $g, $w, $d, 0.22, $col, $hi);
                $m->metal(false);
                break;
            case 'twin':
                foreach (['r' => self::HAND_R, 'l' => self::HAND_L] as $side => $bone) {
                    $m->bone($bone);
                    $h = $f["hand_$side"];
                    $dd = $side === 'l' ? [$d[0], $d[1], -$d[2]] : $d;
                    $m->limb(self::along($h, $dd, -0.6), self::along($h, $dd, 0.6), [[0.0, 0.2], [1.0, 0.2]], $grip, 6);
                    $m->metal(true);
                    $g = self::along($h, $dd, 0.62);
                    $m->limb(self::along($g, $w, -0.7), self::along($g, $w, 0.7), [[0.0, 0.14], [0.5, 0.2], [1.0, 0.14]], $gold, 5);
                    $m->plate([[-0.3, 0.0], [0.28, 0.0], [0.42, 2.0], [0.18, 3.6], [-0.2, 2.4]], $g, $w, $dd, 0.14, $col, $hi);
                    $m->metal(false);
                }
                break;
            case 'spear':
                $m->limb(self::along($hand, $d, -4.6), self::along($hand, $d, 10.0), [[0.0, 0.22], [1.0, 0.2]], self::shade($col, 0.9, 1.12), 6);
                $m->metal(true);
                $m->plate([[-0.52, 0.0], [0.52, 0.0], [0.58, 1.1], [0.0, 3.1], [-0.58, 1.1]], self::along($hand, $d, 9.9), $w, $d, 0.24, $steel, Mesh::times($steel, 1.12));
                $m->metal(false);
                break;
            case 'dagger':
                $m->limb(self::along($hand, $d, -0.6), self::along($hand, $d, 0.6), [[0.0, 0.2], [1.0, 0.2]], $grip, 6);
                $m->metal(true);
                $g = self::along($hand, $d, 0.6);
                $m->limb(self::along($g, $w, -0.6), self::along($g, $w, 0.6), [[0.0, 0.14], [1.0, 0.14]], Mesh::rgb('#8a8a92'), 5);
                $m->plate([[-0.28, 0.0], [0.28, 0.0], [0.2, 1.6], [0.0, 2.5], [-0.2, 1.6]], $g, $w, $d, 0.14, $col, $hi);
                $m->metal(false);
                break;
            case 'pick':
                $m->limb(self::along($hand, $d, -1.4), self::along($hand, $d, 6.2), [[0.0, 0.26], [1.0, 0.24]], self::shade($wood, 0.88, 1.1), 7);
                $m->metal(true);
                $o = self::along($hand, $d, 5.9);
                $tip = self::along(self::along($o, $w, 2.8), $d, -1.0);
                $m->limb($o, $tip, [[0.0, 0.42], [0.5, 0.3], [1.0, 0.0]], $col, 6);
                $m->limb($o, self::along(self::along($o, $w, -1.3), $d, -0.3), [[0.0, 0.42], [1.0, 0.3]], Mesh::times($col, 0.9), 6);
                $m->metal(false);
                break;
            case 'staff':
                // from above the ground (short people hold it low; it stays clear of the ground when they stagger) to
                // well over the head
                $low = min(5.4, ($hand[1] - 0.9) / $d[1]);
                $m->lathe([[0.0, 0.0, 0.0], [0.02, 0.32, 0.32], [0.95, 0.36, 0.36], [1.0, 0.42, 0.42]], self::shade($wood, 0.85, 1.12), 7,
                    self::along($hand, $d, -$low), [0, 0, 0], self::along($hand, $d, 4.6), 3,
                    static fn (float $t, float $a): float => 1.0 + 0.22 * max(0.0, sin($t * 29.0)));
                $top = self::along($hand, $d, 4.6);
                foreach ([-1, 1] as $s) {
                    $m->limb($top, [$top[0] + $w[0] * 0.5 * $s, $top[1] + 1.2, $top[2] + 0.3 * $s], [[0.0, 0.2], [1.0, 0.06]], $wood, 5);
                }
                $m->lathe([[0.0, 0.0, 0.0], [0.45, 0.72, 0.72], [1.0, 0.0, 0.0]], Mesh::times($col, 1.4), 6, self::along($top, [0.0, 1.0, 0.0], 0.6),
                    [0, 0, 0], self::along($top, [0.0, 1.0, 0.0], 2.6), 1);
                break;
            case 'stick':
                $m->limb(self::along($hand, $d, -1.2), self::along($hand, $d, 5.0), [[0.0, 0.3], [0.6, 0.24], [1.0, 0.18]], self::shade($col, 0.9, 1.1), 6);
                $m->ball(self::along($hand, $d, 3.2), [0.34, 0.34, 0.34], Mesh::times($col, 0.85), 5, 3);
                break;
            case 'bow':
                $m->bone(self::HAND_L);
                $g = $f['hand_l'];
                $pts = [[-0.7, 4.3], [-0.15, 3.0], [0.25, 1.5], [0.35, 0.0], [0.25, -1.5], [-0.15, -3.0], [-0.7, -4.3]];
                $radii = [0.14, 0.22, 0.28, 0.3, 0.28, 0.22, 0.14];
                for ($i = 0; $i < 6; $i++) {
                    $m->limb([$g[0] + $pts[$i][0], $g[1] + $pts[$i][1], $g[2]], [$g[0] + $pts[$i + 1][0], $g[1] + $pts[$i + 1][1], $g[2]],
                        [[0.0, $radii[$i]], [1.0, $radii[$i + 1]]], self::shade($col, 0.95, 1.1), 6);
                }
                $m->limb([$g[0] - 0.72, $g[1] + 4.25, $g[2]], [$g[0] - 0.72, $g[1] - 4.25, $g[2]], [[0.0, 0.05], [1.0, 0.05]], Mesh::rgb('#efe6d0'), 3);
                break;
        }
    }

    /** A round shield on the left forearm, its face outwards: a rim, a painted face, a boss. */
    private static function shield(Mesh $m, array $col, array $f, string $race): void
    {
        $m->bone(self::FORE_L);
        $el = $f['el_l'];
        $wr = $f['wr_l'];
        $c = [($el[0] + $wr[0]) / 2 + 0.2, ($el[1] + $wr[1]) / 2 + 0.1, ($el[2] + $wr[2]) / 2 - 0.95];
        $r = ($race === 'dwarf' ? 2.55 : 2.3) * (0.85 + 0.15 * $f['b']);
        $out = self::unit([0.25, 0.05, -1.0]);
        $rim = Mesh::rgb('#a8acb4');
        $boss = Mesh::rgb('#d8c070');
        $m->lathe([[0.0, 0.0, 0.0], [0.04, $r * 0.96, $r * 0.96], [0.2, $r, $r], [0.36, $r * 0.93, $r * 0.93], [0.55, $r * 0.78, $r * 0.78],
            [0.7, $r * 0.3, $r * 0.3], [0.85, $r * 0.24, $r * 0.24], [1.0, 0.0, 0.0]],
            static function (float $t, float $a) use ($col, $rim, $boss): array {
                if ($t < 0.38) {
                    return Mesh::times($rim, 0.85 + 0.35 * $t);
                }
                if ($t > 0.62) {
                    return $boss;
                }
                // a painted band across the face
                return Mesh::times($col, abs(sin($a)) < 0.3 ? 0.7 : 1.0);
            }, 16, $c, [0, 0, 0], self::along($c, $out, 0.95), 2);
    }

    // ---- mounts -----------------------------------------------------------------------------------------------------

    /** The joints of a horse or a wolf at rest. @return array<string, array<int, float>> */
    private static function beastFrame(bool $wolf): array
    {
        return $wolf ? [
            'body' => [0.0, 6.6, 0.0], 'neck' => [3.7, 7.6, 0.0], 'poll' => [5.4, 9.6, 0.0], 'tail' => [-5.2, 7.5, 0.0],
            'fup' => [3.6, 6.3, 1.15], 'fmid' => [3.9, 3.2, 1.2], 'ffoot' => [3.75, 0.65, 1.2],
            'bup' => [-3.9, 6.6, 1.2], 'bmid' => [-4.65, 3.4, 1.25], 'bfoot' => [-4.15, 0.65, 1.25],
            'seat' => [-0.3, 8.75, 0.0],
        ] : [
            'body' => [0.0, 8.4, 0.0], 'neck' => [3.9, 9.8, 0.0], 'poll' => [6.5, 14.5, 0.0], 'tail' => [-6.2, 9.7, 0.0],
            'fup' => [3.5, 8.2, 1.25], 'fmid' => [3.8, 4.3, 1.3], 'ffoot' => [3.65, 1.15, 1.3],
            'bup' => [-4.5, 8.6, 1.3], 'bmid' => [-5.15, 4.5, 1.35], 'bfoot' => [-4.6, 1.15, 1.35],
            'seat' => [-0.5, 10.95, 0.0],
        ];
    }

    /** A horse or a wolf, standing on its four legs, a saddle when it carries someone. */
    private static function beast(array $d): Mesh
    {
        $wolf = ($d['snout'] ?? 'horse') === 'wolf';
        $p = self::beastFrame($wolf);
        $m = new Mesh();
        $body = Mesh::rgb($d['body']);
        $mane = Mesh::rgb($d['mane']);
        $hoof = Mesh::rgb($wolf ? '#3a2e26' : '#2e2622');
        $topLight = static fn (array $col, float $k = 1.0): \Closure => static fn (float $t, float $a): array => Mesh::times($col, (0.8 + 0.22 * cos($a)) * $k);

        // the body along x (its top lighter); a wolf's coat shaggy
        $m->bone(self::BODY);
        if ($wolf) {
            $m->lathe([[0.0, 0.0, 0.0, 0.3], [0.06, 1.6, 1.45, 0.35], [0.28, 2.0, 1.8, 0.15], [0.55, 1.85, 1.7, -0.15], [0.8, 2.45, 1.95, 0.3], [0.94, 2.0, 1.75, 0.5], [1.0, 0.0, 0.0, 0.55]],
                $topLight($body), 16, [-5.4, 6.6, 0.0], [0, 0, 0], [4.9, 6.9, 0.0], 2,
                static fn (float $t, float $a): float => 1.0 + 0.06 * sin(13 * $a + 9 * $t));
        } else {
            $m->lathe([[0.0, 0.0, 0.0, 0.2], [0.05, 1.9, 1.65, 0.35], [0.2, 2.7, 2.2, 0.35], [0.45, 2.5, 2.15, -0.1], [0.7, 2.6, 2.15, 0.0], [0.88, 2.75, 2.05, 0.15], [0.97, 1.85, 1.55, 0.25], [1.0, 0.0, 0.0, 0.25]],
                $topLight($body), 16, [-6.4, 8.4, 0.0], [0, 0, 0], [5.4, 8.5, 0.0], 2);
        }
        if (isset($d['rider'])) {
            [$sx, $sy] = $p['seat'];
            $blanket = Mesh::rgb($d['cloth'] ?? '#8a3a2a');
            $m->ball([$sx, $sy - 0.45, 0.0], [$wolf ? 2.6 : 3.2, 0.42, $wolf ? 2.25 : 2.75], $blanket, 12, 4);
            $m->ball([$sx, $sy - 0.1, 0.0], [$wolf ? 1.9 : 2.4, 0.6, $wolf ? 1.9 : 2.35], Mesh::rgb('#6a3a20'), 10, 4, [0, 0, 0], Mesh::rgb('#8a5230'));
        }

        // the neck and its mane (a wolf's ruff)
        $m->bone(self::NECK);
        if ($wolf) {
            $m->limb([3.5, 7.4, 0.0], [5.6, 9.6, 0.0], [[0.0, 2.0], [1.0, 1.55]], $topLight($body), 12, 0.85);
            $m->lathe([[0.0, 2.3, 2.2], [0.5, 2.6, 2.45], [1.0, 1.85, 1.75]], $topLight($mane), 14, [3.4, 7.0, 0.0], [0, 0, 0], [5.0, 9.2, 0.0], 2,
                static fn (float $t, float $a): float => 1.0 + 0.14 * max(0.0, sin(11 * $a)));
        } else {
            $m->limb([3.8, 9.6, 0.0], [6.6, 14.2, 0.0], [[0.0, 1.9], [0.5, 1.45], [1.0, 1.12]], $topLight($body), 12, 0.72);
            $m->limb([6.1, 15.2, 0.0], [3.0, 11.4, 0.0], [[0.0, 0.42], [0.3, 0.72], [1.0, 0.35]], self::shade($mane, 1.1, 0.85), 8, 0.34);
        }

        // the head
        $m->bone(self::SKULL);
        $eyeCol = $wolf ? Mesh::rgb($d['eye'] ?? '#ff4030', 1.4) : Mesh::rgb('#1a1414');
        if ($wolf) {
            $m->lathe([[0.0, 0.0, 0.0], [0.1, 1.45, 1.3], [0.35, 1.42, 1.32], [0.6, 0.95, 0.88], [0.9, 0.55, 0.52], [1.0, 0.0, 0.0]],
                $topLight($body), 14, [5.3, 10.2, 0.0], [0, 0, 0], [9.3, 8.7, 0.0], 2);
            $m->limb([5.9, 9.0, 0.0], [8.9, 7.9, 0.0], [[0.0, 0.7], [0.7, 0.42], [1.0, 0.2]], Mesh::times($body, 0.8), 8);
            $m->ball([9.25, 8.85, 0.0], [0.32, 0.28, 0.32], Mesh::rgb('#1a1414'), 6, 3);
            foreach ([-1, 1] as $s) {
                $m->limb([5.9, 11.2, $s * 0.75], [5.5, 13.0, $s * 0.98], [[0.0, 0.52], [1.0, 0.0]], Mesh::times($body, 0.9), 6, 0.5);
                $m->ball([7.0, 10.55, $s * 0.86], [0.22, 0.16, 0.18], $eyeCol, 5, 3);
                $m->limb([8.45, 8.3, $s * 0.35], [8.55, 7.45, $s * 0.4], [[0.0, 0.14], [1.0, 0.0]], Mesh::rgb('#f2ead2'), 4);
            }
        } else {
            $m->lathe([[0.0, 0.0, 0.0], [0.08, 1.15, 0.95], [0.32, 1.12, 0.92], [0.68, 0.8, 0.72], [0.9, 0.72, 0.7], [1.0, 0.0, 0.0]],
                static fn (float $t, float $a): array => Mesh::times($body, (0.82 + 0.2 * cos($a)) * ($t > 0.8 ? 0.78 : 1.0)), 14, [6.4, 14.9, 0.0], [0, 0, 0], [9.7, 12.0, 0.0], 2);
            $m->limb([6.8, 15.8, 0.0], [7.6, 15.0, 0.0], [[0.0, 0.42], [1.0, 0.1]], $mane, 6, 0.6);
            foreach ([-1, 1] as $s) {
                $m->limb([6.6, 15.6, $s * 0.55], [6.3, 17.1, $s * 0.7], [[0.0, 0.38], [1.0, 0.0]], Mesh::times($body, 0.92), 6, 0.55);
                $m->ball([7.4, 14.65, $s * 0.9], [0.26, 0.24, 0.16], $eyeCol, 5, 3);
                $m->ball([9.5, 12.2, $s * 0.42], [0.16, 0.16, 0.12], Mesh::rgb('#1a1414'), 4, 2);
            }
        }

        // the tail
        $m->bone(self::TAIL);
        if ($wolf) {
            $m->limb([-5.2, 7.6, 0.0], [-8.3, 4.8, 0.0], [[0.0, 0.55], [0.35, 1.05], [0.85, 0.85], [1.0, 0.1]], self::shade($mane, 1.0, 1.25), 9);
        } else {
            $m->limb([-6.3, 9.9, 0.0], [-7.8, 4.4, 0.0], [[0.0, 0.45], [0.2, 0.92], [0.7, 0.82], [1.0, 0.0]], self::shade($mane, 1.05, 0.85), 9, 0.55);
        }

        // the legs: a strong upper part, a thin lower one, hooves (paws)
        foreach ([0 => [1, 'f'], 1 => [-1, 'f'], 2 => [1, 'b'], 3 => [-1, 'b']] as $leg => [$s, $fb]) {
            $up = [$p["{$fb}up"][0], $p["{$fb}up"][1], $s * $p["{$fb}up"][2]];
            $mid = [$p["{$fb}mid"][0], $p["{$fb}mid"][1], $s * $p["{$fb}mid"][2]];
            $foot = [$p["{$fb}foot"][0], $p["{$fb}foot"][1], $s * $p["{$fb}foot"][2]];
            $thick = $fb === 'b' ? ($wolf ? 1.6 : 2.0) : ($wolf ? 1.3 : 1.5);
            $m->bone(self::LEG + 2 * $leg);
            $m->limb($up, $mid, [[0.0, $thick], [0.35, $thick * 0.82], [0.8, 0.62], [1.0, 0.56]], self::shade($body, 1.0, 0.84), 8, 0.85);
            $m->bone(self::LEG + 2 * $leg + 1);
            $m->ball($mid, [0.6, 0.62, 0.56], Mesh::times($body, 0.86), 6, 4);
            $m->limb($mid, $foot, [[0.0, 0.56], [0.5, 0.45], [1.0, 0.5]], self::shade($body, 0.86, 0.72), 8);
            if ($wolf) {
                $m->ball([$foot[0] + 0.35, 0.42, $foot[2]], [0.82, 0.42, 0.62], Mesh::times($body, 0.72), 7, 4);
            } else {
                $m->ball($foot, [0.55, 0.5, 0.52], Mesh::times($body, 0.7), 6, 4);
                $m->lathe([[0.0, 0.74, 0.7], [0.6, 0.68, 0.64], [1.0, 0.5, 0.48]], self::shade($hoof, 0.8, 1.1), 10, [$foot[0] + 0.08, 0.0, $foot[2]]);
            }
        }
        return $m;
    }

    // ---- flyers -------------------------------------------------------------------------------------------------------

    /** A flying creature: a bat's skin wings or an eagle's feathers, on bones that flap. */
    private static function wings(array $d): Mesh
    {
        $m = new Mesh();
        $body = Mesh::rgb($d['body']);
        $wing = Mesh::rgb($d['wing']);
        $beak = !empty($d['beak']);
        $eye = Mesh::rgb($d['eye'], 1.35);
        $m->bone(self::F_BODY);
        $m->lathe([[0.0, 0.0, 0.0], [0.12, 1.35, 1.15], [0.45, 1.9, 1.6], [0.8, 1.55, 1.35], [1.0, 0.0, 0.0]],
            static fn (float $t, float $a): array => Mesh::times($body, 0.82 + 0.25 * cos($a)), 12, [-2.4, 3.0, 0.0], [0, 0, 0], [2.6, 3.4, 0.0], 2);
        foreach ([-1, 1] as $s) {
            $m->limb([-0.3, 2.0, $s * 0.7], [-0.6, 0.6, $s * 0.85], [[0.0, 0.3], [1.0, 0.18]], Mesh::rgb($beak ? '#f2c040' : '#2a2026'), 5);
        }
        $m->bone(self::F_HEAD);
        if ($beak) {
            $m->lathe([[0.0, 0.0, 0.0], [0.15, 1.15, 1.05], [0.55, 1.25, 1.12], [1.0, 0.0, 0.0]], self::shade(Mesh::rgb('#f4f0e4'), 0.9, 1.05), 12,
                [2.2, 4.0, 0.0], [0, 0, 0], [4.7, 4.5, 0.0], 2);
            $m->limb([4.4, 4.5, 0.0], [5.9, 3.8, 0.0], [[0.0, 0.5], [0.6, 0.34], [1.0, 0.0]], Mesh::rgb('#f2c040'), 7);
            foreach ([-1, 1] as $s) {
                $m->ball([3.9, 4.75, $s * 0.82], [0.24, 0.24, 0.16], $eye, 5, 3);
            }
        } else {
            $m->ball([3.4, 4.0, 0.0], [1.45, 1.35, 1.35], self::shade($body, 0.85, 1.1)(0.5, 0.0), 10, 6, [0, 0, 0], Mesh::times($body, 1.12));
            $m->ball([4.6, 3.75, 0.0], [0.72, 0.55, 0.62], Mesh::times($body, 0.9), 7, 4);
            foreach ([-1, 1] as $s) {
                $m->limb([3.0, 5.0, $s * 0.85], [2.7, 7.0, $s * 1.55], [[0.0, 0.7], [0.5, 0.55], [1.0, 0.0]], Mesh::times($body, 0.95), 6, 0.5);
                $m->ball([4.35, 4.35, $s * 0.62], [0.24, 0.24, 0.2], $eye, 5, 3);
                $m->limb([4.9, 3.4, $s * 0.28], [5.0, 2.7, $s * 0.3], [[0.0, 0.12], [1.0, 0.0]], Mesh::rgb('#f2ead2'), 4);
            }
        }
        $edge = Mesh::times($wing, $beak ? 1.3 : 0.75);
        foreach ([1 => [self::WING_R, self::TIP_R], -1 => [self::WING_L, self::TIP_L]] as $s => [$inner, $outer]) {
            $eu = [0.0, 0.0, (float)$s];
            $ev = [1.0, 0.0, 0.0];
            $o = [0.0, 3.9, 0.0];
            $m->bone($inner);
            $m->limb([0.6, 3.9, $s * 1.2], [-0.4, 4.1, $s * 5.2], [[0.0, 0.36], [1.0, 0.26]], Mesh::times($beak ? $wing : $body, 0.85), 5);
            $m->plate([[1.1, 1.3], [5.3, 0.1], [5.3, -2.6], [3.4, -2.1], [1.1, -2.3]], $o, $eu, $ev, 0.14, $wing, $edge);
            $m->bone($outer);
            if ($beak) {
                // the long feathers of the tip, fanned
                foreach ([0.6, -0.3, -1.2, -2.1] as $i => $v) {
                    $m->plate([[5.0, $v + 0.55], [10.4 - $i * 0.45, $v + 0.25 - $i * 0.5], [10.6 - $i * 0.45, $v - 0.35 - $i * 0.5], [5.0, $v - 0.55]],
                        [0.0, 3.92 + $i * 0.02, 0.0], $eu, $ev, 0.12, Mesh::times($wing, 0.9 + 0.08 * $i), $edge);
                }
            } else {
                foreach ([[1.0, 8.6], [-1.2, 10.2], [-3.0, 8.9]] as [$x, $z]) {
                    $m->limb([-0.4, 4.1, $s * 5.2], [$x, 3.8, $s * $z], [[0.0, 0.22], [1.0, 0.08]], Mesh::times($body, 0.8), 4);
                }
                $m->plate([[5.2, 0.2], [8.6, 1.0], [10.2, -1.2], [8.9, -3.0], [7.2, -2.3], [5.2, -2.6]], $o, $eu, $ev, 0.12, $wing, $edge);
            }
        }
        $m->bone(self::F_TAIL);
        if ($beak) {
            $m->plate([[0.0, 0.0], [-3.4, -1.8], [-4.0, 0.0], [-3.4, 1.8]], [-2.2, 3.1, 0.0], [1.0, 0.0, 0.0], [0.0, 0.0, 1.0], 0.16, $wing, $edge);
        } else {
            $m->limb([-2.2, 3.0, 0.0], [-3.4, 2.6, 0.0], [[0.0, 0.3], [1.0, 0.0]], $body, 5);
        }
        return $m;
    }

    // ---- the ram -------------------------------------------------------------------------------------------------------

    /** The siege ram of the horde: a roofed cart on wheels that turn, a log headed with iron swinging from chains. */
    private static function ram(): Mesh
    {
        $m = new Mesh();
        $wood = Mesh::rgb('#8a6a3a');
        $dark = Mesh::rgb('#4e3420');
        $red = Mesh::rgb('#8e3a2a');
        $iron = Mesh::rgb('#7a7e88');
        $m->bone(self::CART);
        $m->slab([0, 2.0, 0], [14.0, 1.8, 8.4], $dark, [0, 0, 0], $wood);
        foreach ([-1, 1] as $side) {
            $m->slab([0, 3.8, $side * 3.9], [12.4, 6.0, 0.8], Mesh::times($red, 0.85), [0, 0, 0], $red);
            foreach ([-5.6, 0.0, 5.6] as $x) {
                $m->slab([$x, 3.8, $side * 4.3], [0.8, 6.4, 0.4], $dark);
            }
            $m->box([-0.8, 12.4, $side * 2.6], [14.0, 0.9, 6.4], $wood, [$side * 0.6, 0, 0], Mesh::times($wood, 1.2));
        }
        foreach ([[-4.8, -1], [-4.8, 1], [4.8, -1], [4.8, 1]] as $i => [$x, $side]) {
            $m->bone(self::WHEEL + $i);
            $z = $side * 4.8;
            $m->cyl([$x, 2.9, $z], 2.8, 2.8, 1.1, $dark, 14, [M_PI / 2, 0, 0], true, $wood, true);
            $m->box([$x, 2.9, $z + 1.15], [5.0, 0.45, 0.25], $wood);
            $m->box([$x, 2.9, $z + 1.15], [0.45, 5.0, 0.25], $wood);
            $m->metal(true);
            $m->cyl([$x, 2.9, $z + 1.1], 0.7, 0.7, 0.4, $iron, 8, [M_PI / 2, 0, 0]);
            $m->metal(false);
        }
        $m->bone(self::LOG, true);
        foreach ([-3.5, 3.5] as $x) {
            $m->limb([$x, 11.8, 0.0], [$x, 7.4, 0.0], [[0.0, 0.14], [1.0, 0.14]], $iron, 4);
        }
        $m->metal(false);
        $m->limb([-9.0, 6.4, 0.0], [10.4, 6.4, 0.0], [[0.0, 1.5], [0.05, 1.7], [0.95, 1.7], [1.0, 1.6]], self::shade(Mesh::rgb('#a07a46'), 0.9, 1.08), 12);
        $m->metal(true);
        $m->cyl([10.4, 6.4, 0], 2.2, 1.6, 2.6, $iron, 12, [0, 0, -M_PI / 2], true, Mesh::times($iron, 1.25));
        $m->metal(false);
        foreach ([-1, 1] as $side) {
            $m->ball([12.6, 7.2, $side * 0.9], [0.4, 0.5, 0.4], Mesh::rgb('#ff5030', 1.3), 4, 2);
        }
        return $m;
    }

    // ---- skeletons and poses -----------------------------------------------------------------------------------------

    private static function makeSkeleton(string $sprite, string $part): Skeleton
    {
        $d = self::KINDS[$sprite] ?? self::KINDS['grunt'];
        if ($part === 'rider' && isset($d['rider'])) {
            return self::personSkeleton($d['rider'], self::riderSize($d['rider']));
        }
        switch ($d['k']) {
            case 'beast':
                $p = self::beastFrame(($d['snout'] ?? 'horse') === 'wolf');
                $bones = [[-1, $p['body']], [self::BODY, $p['neck']], [self::NECK, $p['poll']], [self::BODY, $p['tail']]];
                foreach ([[1, 'f'], [-1, 'f'], [1, 'b'], [-1, 'b']] as $leg => [$s, $fb]) {
                    $bones[] = [self::BODY, [$p["{$fb}up"][0], $p["{$fb}up"][1], $s * $p["{$fb}up"][2]]];
                    $bones[] = [self::LEG + 2 * $leg, [$p["{$fb}mid"][0], $p["{$fb}mid"][1], $s * $p["{$fb}mid"][2]]];
                }
                return new Skeleton($bones);
            case 'wings':
                return new Skeleton([[-1, [0.0, 3.2, 0.0]], [self::F_BODY, [2.4, 3.9, 0.0]], [self::F_BODY, [0.6, 3.9, 1.2]], [self::WING_R, [-0.4, 4.1, 5.2]],
                    [self::F_BODY, [0.6, 3.9, -1.2]], [self::WING_L, [-0.4, 4.1, -5.2]], [self::F_BODY, [-2.2, 3.1, 0.0]]]);
            case 'ram':
                $bones = [[-1, [0.0, 2.0, 0.0]]];
                foreach ([[-4.8, -1], [-4.8, 1], [4.8, -1], [4.8, 1]] as [$x, $side]) {
                    $bones[] = [self::CART, [$x, 2.9, $side * 4.8 + 0.55]];
                }
                $bones[] = [self::CART, [0.0, 12.0, 0.0]];
                return new Skeleton($bones);
            default:
                return self::personSkeleton($d, 1.0);
        }
    }

    private static function personSkeleton(array $d, float $k): Skeleton
    {
        $f = self::frame($d);
        $sc = static fn (array $p): array => [$p[0] * $k, $p[1] * $k, $p[2] * $k];
        return new Skeleton([
            self::HIPS => [-1, $sc([0.0, $f['hip'] + 0.3, 0.0])],
            self::CHEST => [self::HIPS, $sc([0.0, $f['waist'], 0.0])],
            self::HEAD => [self::CHEST, $sc($f['neckPivot'])],
            self::ARM_R => [self::CHEST, $sc($f['sh_r'])],
            self::FORE_R => [self::ARM_R, $sc($f['el_r'])],
            self::HAND_R => [self::FORE_R, $sc($f['wr_r'])],
            self::ARM_L => [self::CHEST, $sc($f['sh_l'])],
            self::FORE_L => [self::ARM_L, $sc($f['el_l'])],
            self::HAND_L => [self::FORE_L, $sc($f['wr_l'])],
            self::THIGH_R => [self::HIPS, $sc($f['hip_r'])],
            self::SHIN_R => [self::THIGH_R, $sc($f['knee_r'])],
            self::THIGH_L => [self::HIPS, $sc($f['hip_l'])],
            self::SHIN_L => [self::THIGH_L, $sc($f['knee_l'])],
            self::CAPE => [self::CHEST, $sc([-0.9 * $f['b'], $f['shoulder'] + 0.2, 0.0])],
            self::FOOT_R => [self::SHIN_R, $sc($f['ankle_r'])],
            self::FOOT_L => [self::SHIN_L, $sc($f['ankle_l'])],
        ]);
    }

    /** @return list<float> */
    private static function makePose(string $sprite, string $part, string $anim, float $t): array
    {
        $d = self::KINDS[$sprite] ?? self::KINDS['grunt'];
        $skeleton = self::skeleton($sprite, $part);
        if ($part === 'rider' && isset($d['rider'])) {
            $r = $d['rider'];
            $k = self::riderSize($r);
            [$turns, $move, $stretch] = self::personPose($r, $anim === 'fly' ? 'walk' : $anim, $t, true);
            $f = self::frame($r);
            $seat = self::beastFrame(($d['snout'] ?? 'horse') === 'wolf')['seat'];
            // on the saddle, carried by the mount's bob
            [, $bob] = self::beastPose($anim, $t);
            return $skeleton->matrices($turns, [$seat[0] + $move[0] * $k, $seat[1] - $f['hip'] * $k + $bob[1] + 0.1, 0.0], $stretch);
        }
        switch ($d['k']) {
            case 'beast':
                [$turns, $move] = self::beastPose($anim, $t);
                return $skeleton->matrices($turns, $move);
            case 'wings':
                return $skeleton->matrices(...self::flyerPose($anim, $t));
            case 'ram':
                $turns = [];
                for ($i = 0; $i < 4; $i++) {
                    $turns[self::WHEEL + $i] = [0.0, 0.0, $anim === 'walk' ? -2 * M_PI * $t : 0.0];
                }
                $turns[self::LOG] = [0.0, 0.0, $anim === 'fight' ? 0.2 * sin(2 * M_PI * $t) : 0.0];
                return $skeleton->matrices($turns);
            default:
                [$turns, $move, $stretch] = self::personPose($d, $anim, $t, false);
                return $skeleton->matrices($turns, $move, $stretch);
        }
    }

    /** A value along keys [[time, value], ...] (times 0..1, in order), eased between them. */
    private static function keyed(array $keys, float $t): float
    {
        $n = count($keys);
        for ($i = 1; $i < $n; $i++) {
            if ($t <= $keys[$i][0]) {
                [$t0, $v0] = $keys[$i - 1];
                [$t1, $v1] = $keys[$i];
                $u = $t1 > $t0 ? ($t - $t0) / ($t1 - $t0) : 1.0;
                $u = $u * $u * (3 - 2 * $u);
                return $v0 + ($v1 - $v0) * $u;
            }
        }
        return (float)$keys[$n - 1][1];
    }

    /**
     * A person's pose: its bones' turns, its root's move, its stretches. $seated: on a mount (the legs astride).
     * @return array{0: array<int, array{0: float, 1: float, 2: float}>, 1: array{0: float, 1: float, 2: float}, 2: array<int, float>}
     */
    private static function personPose(array $d, string $anim, float $t, bool $seated): array
    {
        $f = self::frame($d);
        $weapon = (string)($d['weapon'][0] ?? '');
        $shield = isset($d['shield']);
        $robe = !empty($d['robe']);
        $twin = $weapon === 'twin';
        $bow = $weapon === 'bow';
        $holds = $weapon !== '' && !$bow;
        $turns = [];
        $move = [0.0, 0.0, 0.0];
        $stretch = [];
        $add = static function (int $bone, float $x, float $y, float $z) use (&$turns): void {
            $o = $turns[$bone] ?? [0.0, 0.0, 0.0];
            $turns[$bone] = [$o[0] + $x, $o[1] + $y, $o[2] + $z];
        };
        // how the arms hold things when nothing else moves them, a little away from the body
        $add(self::ARM_R, -0.08, 0.0, $holds ? 0.15 : 0.0);
        $add(self::FORE_R, 0.0, 0.0, $holds ? 0.45 : 0.2);
        $add(self::ARM_L, 0.08, 0.0, 0.0);
        if ($bow) {
            $add(self::ARM_L, 0.0, 0.0, 0.35);
            $add(self::FORE_L, 0.0, 0.0, 0.5);
        } elseif ($shield) {
            $add(self::ARM_L, 0.15, 0.0, 0.35);
            $add(self::FORE_L, 0.0, 0.0, 0.9);
        } elseif ($twin) {
            $add(self::ARM_L, 0.0, 0.0, 0.15);
            $add(self::FORE_L, 0.0, 0.0, 0.45);
        } else {
            $add(self::FORE_L, 0.0, 0.0, 0.2);
        }

        switch ($anim) {
            case 'walk':
                $th = 2 * M_PI * $t;
                $s = sin($th);
                $c = cos($th);
                if (!$seated) {
                    $amp = $robe ? 0.32 : 0.55;
                    $add(self::THIGH_R, 0.0, 0.0, $amp * $s);
                    $add(self::THIGH_L, 0.0, 0.0, -$amp * $s);
                    // a knee bends as its leg swings forwards
                    $add(self::SHIN_R, 0.0, 0.0, -(0.1 + 1.6 * $amp * max(0.0, $c)));
                    $add(self::SHIN_L, 0.0, 0.0, -(0.1 + 1.6 * $amp * max(0.0, -$c)));
                    // the hips lower as the legs part (the feet stay on the ground)
                    $move[1] = -($f['hip'] - 1.05) * (1 - cos($amp * $s));
                    $add(self::HIPS, 0.0, -0.12 * $s, 0.0);
                    $add(self::CHEST, 0.0, 0.22 * $s, -0.06 - 0.05 * $f['hunch']);
                    $add(self::HEAD, 0.0, -0.1 * $s, 0.04);
                }
                $add(self::ARM_R, 0.0, 0.0, -($holds ? 0.25 : 0.45) * $s);
                $add(self::FORE_R, 0.0, 0.0, 0.2 * max(0.0, -$s));
                if ($bow || $shield) {
                    $add(self::ARM_L, 0.0, 0.0, 0.12 * $s);
                } else {
                    $add(self::ARM_L, 0.0, 0.0, 0.45 * $s);
                    $add(self::FORE_L, 0.0, 0.0, 0.2 * max(0.0, $s));
                }
                $add(self::CAPE, 0.0, 0.0, -0.12 - 0.08 * abs($s));
                break;
            case 'fight':
                // 1 raised, -1 struck, 0 on guard: the blow lands as the body lunges (PlayScene::lunge(), its first quarter)
                $keys = [[0.0, 1.0], [0.06, 1.0], [0.2, -1.0], [0.48, 0.0], [0.86, 1.0], [1.0, 1.0]];
                $p = $t - floor($t);
                $v = self::keyed($keys, $p);
                $raise = max(0.0, $v);
                $strike = max(0.0, -$v);
                if ($bow) {
                    // an archer shoots from close: the bow raised, the string drawn to the cheek, let go
                    $draw = self::keyed([[0.0, 1.0], [0.08, 1.0], [0.12, 0.0], [0.5, 0.2], [0.85, 1.0], [1.0, 1.0]], $p);
                    $turns[self::ARM_L] = [0.25, 0.0, 1.45];
                    $turns[self::FORE_L] = [0.0, 0.0, 0.1];
                    $turns[self::HAND_L] = [0.0, 0.0, -1.35];
                    $turns[self::ARM_R] = [-0.15, 0.0, 1.2 + 0.25 * $draw];
                    $turns[self::FORE_R] = [0.0, 0.0, 0.6 + 1.6 * $draw];
                    $add(self::CHEST, 0.0, -0.3, 0.0);
                    $add(self::HEAD, 0.0, 0.3, 0.0);
                } else {
                    // an overhead blow: the weapon from behind the head down to the foe in front; [arm, forearm, wrist] at
                    // guard, raised, struck (a raised forearm tilts what it holds back: they add up)
                    [$g, $r, $s] = [[0.35, 0.45, 0.0], [2.4, 0.6, 0.25], [0.85, 0.1, -1.75]];
                    $pick = static fn (int $i): float => $v >= 0 ? $g[$i] + ($r[$i] - $g[$i]) * $v : $g[$i] + ($s[$i] - $g[$i]) * -$v;
                    $turns[self::ARM_R] = [-0.08 - 0.2 * $raise, 0.0, $pick(0)];
                    $turns[self::FORE_R] = [0.0, 0.0, $pick(1)];
                    $turns[self::HAND_R] = [0.0, 0.0, $pick(2)];
                    $add(self::CHEST, 0.0, -0.35 * $raise + 0.4 * $strike, 0.12 * $raise - 0.3 * $strike);
                    $add(self::HEAD, 0.0, 0.2 * $raise - 0.25 * $strike, -0.08 * $strike);
                    $add(self::HIPS, 0.0, -0.12 * $raise + 0.18 * $strike, 0.0);
                    if ($twin) {
                        // the other blade half a blow later
                        $v2 = self::keyed($keys, fmod($p + 0.5, 1.0));
                        $pick2 = static fn (int $i): float => $v2 >= 0 ? $g[$i] + ($r[$i] - $g[$i]) * $v2 : $g[$i] + ($s[$i] - $g[$i]) * -$v2;
                        $turns[self::ARM_L] = [0.08 + 0.2 * max(0.0, $v2), 0.0, $pick2(0)];
                        $turns[self::FORE_L] = [0.0, 0.0, $pick2(1)];
                        $turns[self::HAND_L] = [0.0, 0.0, $pick2(2)];
                    } elseif ($shield) {
                        $add(self::ARM_L, 0.1, 0.0, 0.25 + 0.2 * $strike);
                        $add(self::FORE_L, 0.0, 0.0, 0.2);
                    } else {
                        $add(self::ARM_L, 0.0, 0.0, 0.3 - 0.25 * $strike);
                    }
                }
                if (!$seated) {
                    $add(self::THIGH_R, 0.0, 0.0, -0.3);
                    $add(self::THIGH_L, 0.0, 0.0, 0.35);
                    $add(self::SHIN_R, 0.0, 0.0, -0.3);
                    $add(self::SHIN_L, 0.0, 0.0, -0.8);
                    $add(self::HIPS, 0.0, 0.2, 0.0);
                    $move[1] = -0.45;
                }
                $add(self::CAPE, 0.0, 0.0, -0.1 - 0.15 * $strike);
                break;
            case 'stun':
                $w = sin(2 * M_PI * $t * 0.9);
                $add(self::HEAD, 0.25 * $w, 0.0, -0.5);
                $add(self::CHEST, 0.08 * $w, 0.0, -0.28);
                $add(self::ARM_R, 0.0, 0.0, -0.25);
                $add(self::ARM_L, 0.0, 0.0, -0.15);
                $add(self::FORE_R, 0.0, 0.0, -0.2);
                if (!$seated) {
                    $add(self::THIGH_R, 0.0, 0.0, 0.3);
                    $add(self::THIGH_L, 0.0, 0.0, 0.3);
                    $add(self::SHIN_R, 0.0, 0.0, -0.6);
                    $add(self::SHIN_L, 0.0, 0.0, -0.6);
                    $add(self::HIPS, 0.06 * $w, 0.0, 0.0);
                    $move[1] = -0.35;
                }
                break;
            default:
                // idle: it breathes, looks about, shifts its weight
                $br = sin(2 * M_PI * $t / 3.2);
                $add(self::CHEST, 0.0, 0.0, -0.03 + 0.015 * $br);
                $stretch[self::CHEST] = 1.0 + 0.012 * $br;
                $add(self::HEAD, 0.0, 0.32 * sin(2 * M_PI * $t / 7.3), 0.03 * $br);
                $add(self::ARM_R, 0.0, 0.0, 0.04 * $br);
                $add(self::ARM_L, 0.0, 0.0, -0.04 * $br);
                if (!$seated) {
                    $add(self::HIPS, 0.03 * sin(2 * M_PI * $t / 5.1), 0.0, 0.0);
                }
                $add(self::CAPE, 0.0, 0.0, -0.04 - 0.03 * $br);
        }
        if ($seated) {
            // astride: the thighs forwards and apart, the shins down; the reins in the left hand, a lance lowered
            $turns[self::THIGH_R] = [-0.42, 0.0, 1.3];
            $turns[self::THIGH_L] = [0.42, 0.0, 1.3];
            $turns[self::SHIN_R] = [0.0, 0.0, -1.45];
            $turns[self::SHIN_L] = [0.0, 0.0, -1.45];
            $add(self::CHEST, 0.0, 0.0, -0.1);
            if ($anim !== 'fight') {
                $add(self::ARM_L, 0.0, 0.0, $bow ? 0.25 : 0.55);
                $add(self::FORE_L, 0.0, 0.0, 0.5);
                if ($weapon === 'spear') {
                    // the lance lowered forwards
                    $add(self::ARM_R, 0.0, 0.0, 0.25);
                    $add(self::HAND_R, 0.0, 0.0, -1.6);
                }
            }
        }
        // the feet flat on the ground (or level astride), the toes of a swinging foot a little down
        foreach ([1 => [self::THIGH_R, self::SHIN_R, self::FOOT_R], -1 => [self::THIGH_L, self::SHIN_L, self::FOOT_L]] as $side => [$th, $sh, $ft]) {
            $x = ($turns[self::HIPS][0] ?? 0.0) + ($turns[$th][0] ?? 0.0);
            $z = ($turns[self::HIPS][2] ?? 0.0) + ($turns[$th][2] ?? 0.0) + ($turns[$sh][2] ?? 0.0);
            $toe = $anim === 'walk' && !$seated ? -0.3 * max(0.0, $side * cos(2 * M_PI * $t)) : 0.0;
            $turns[$ft] = [-$x, 0.0, -$z + $toe];
        }
        return [$turns, $move, $stretch];
    }

    /**
     * A mount's pose: its bones' turns and its body's bob.
     * @return array{0: array<int, array{0: float, 1: float, 2: float}>, 1: array{0: float, 1: float, 2: float}}
     */
    private static function beastPose(string $anim, float $t): array
    {
        $turns = [];
        $move = [0.0, 0.0, 0.0];
        switch ($anim) {
            case 'walk':
            case 'fly':
                // a trot: the diagonal legs together
                $th = 2 * M_PI * $t;
                foreach ([0 => 0.0, 1 => M_PI, 2 => M_PI, 3 => 0.0] as $leg => $off) {
                    $ph = $th + $off;
                    $bend = 0.15 + 0.95 * max(0.0, cos($ph));
                    $turns[self::LEG + 2 * $leg] = [0.0, 0.0, 0.5 * sin($ph)];
                    $turns[self::LEG + 2 * $leg + 1] = [0.0, 0.0, $leg < 2 ? -$bend : 0.8 * $bend];
                }
                $move = [0.0, 0.35 * cos(2 * $th) - 0.15, 0.0];
                $turns[self::BODY] = [0.0, 0.0, 0.035 * sin(2 * $th)];
                $turns[self::NECK] = [0.0, 0.0, 0.1 * sin(2 * $th + 0.6)];
                $turns[self::SKULL] = [0.0, 0.0, -0.06 * sin(2 * $th)];
                $turns[self::TAIL] = [0.2 * sin($th), 0.0, 0.15];
                break;
            case 'fight':
                // it rears a little and strikes with its fore legs, bites
                $th = 2 * M_PI * $t;
                $turns[self::BODY] = [0.0, 0.0, 0.12 + 0.06 * sin($th)];
                $move = [0.0, 0.3, 0.0];
                $turns[self::LEG] = [0.0, 0.0, 0.75 + 0.4 * sin(2 * $th)];
                $turns[self::LEG + 1] = [0.0, 0.0, -1.0];
                $turns[self::LEG + 2] = [0.0, 0.0, 0.55 + 0.4 * sin(2 * $th + 1.6)];
                $turns[self::LEG + 3] = [0.0, 0.0, -0.9];
                $turns[self::LEG + 4] = [0.0, 0.0, -0.2];
                $turns[self::LEG + 6] = [0.0, 0.0, -0.2];
                $turns[self::NECK] = [0.0, 0.0, -0.25 * max(0.0, sin($th))];
                $turns[self::TAIL] = [0.3 * sin(2 * $th), 0.0, 0.3];
                break;
            case 'stun':
                $w = sin(2 * M_PI * $t * 0.8);
                $turns[self::NECK] = [0.0, 0.0, -0.45];
                $turns[self::SKULL] = [0.15 * $w, 0.0, -0.2];
                $turns[self::BODY] = [0.06 * $w, 0.0, 0.0];
                $move = [0.0, -0.3, 0.0];
                break;
            default:
                $turns[self::NECK] = [0.0, 0.0, -0.18 + 0.08 * sin(2 * M_PI * $t / 6.0)];
                $turns[self::SKULL] = [0.0, 0.25 * sin(2 * M_PI * $t / 8.0), 0.05];
                $turns[self::TAIL] = [0.35 * sin(2 * M_PI * $t / 2.4), 0.0, 0.1];
                $move = [0.0, 0.05 * sin(2 * M_PI * $t / 3.0), 0.0];
        }
        return [$turns, $move];
    }

    /**
     * A flyer's pose: the wings flap (the tips a little later), the body rises as they beat down.
     * @return array{0: array<int, array{0: float, 1: float, 2: float}>, 1: array{0: float, 1: float, 2: float}}
     */
    private static function flyerPose(string $anim, float $t): array
    {
        $th = 2 * M_PI * $t;
        if ($anim === 'stun') {
            return [[self::WING_R => [0.45, 0.0, 0.0], self::WING_L => [-0.45, 0.0, 0.0], self::TIP_R => [0.3, 0.0, 0.0], self::TIP_L => [-0.3, 0.0, 0.0],
                self::F_BODY => [0.15 * sin($th), 0.0, -0.2]], [0.0, -0.5, 0.0]];
        }
        if ($anim === 'idle') {
            $th *= 0.3;
            return [[self::WING_R => [-0.12 - 0.1 * sin($th), 0.0, 0.0], self::WING_L => [0.12 + 0.1 * sin($th), 0.0, 0.0],
                self::TIP_R => [-0.1, 0.0, 0.0], self::TIP_L => [0.1, 0.0, 0.0]], [0.0, 0.0, 0.0]];
        }
        $amp = $anim === 'fight' ? 1.0 : 0.85;
        $flap = $amp * sin($th);
        $tip = 0.6 * sin($th - 0.8);
        return [[
            self::WING_R => [-$flap, 0.0, 0.0], self::TIP_R => [-$tip, 0.0, 0.0],
            self::WING_L => [$flap, 0.0, 0.0], self::TIP_L => [$tip, 0.0, 0.0],
            self::F_HEAD => [0.0, 0.0, 0.06 * sin($th)], self::F_TAIL => [0.0, 0.0, 0.1 * sin($th)],
            self::F_BODY => [0.0, 0.0, $anim === 'fight' ? -0.25 : 0.0],
        ], [0.0, -0.45 * sin($th), 0.0]];
    }
}
