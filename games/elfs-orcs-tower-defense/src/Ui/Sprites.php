<?php

namespace App\Ui;

use App\Td\GameData;
use App\Td\Path;
use JumPHP\Renderer\Renderer;
use JumPHP\Renderer\Sdl;
use JumPHP\Util\Color;

/**
 * Every picture of the game, made in code the first time it is asked for (pixel strings and GD), each side with
 * its own palette. A PNG of the same name in assets/sprites/ replaces the generated one:
 *   tower-<sprite>-<level>.png (level 1..3, or the specialization's key), enemy-<sprite>.png (2 frames side by side),
 *   icon-<name>.png, map-<map>.png (480 x 240).
 */
final class Sprites
{
    public const TOWER_W = 28;
    public const TOWER_H = 40;

    private ?Sdl $sdl = null;
    private string $root = '';

    public function attach(Sdl $sdl, string $root): void
    {
        $this->sdl = $sdl;
        $this->root = rtrim($root, '/');
    }

    /** Draws a texture made by $make() the first time (or loaded from assets/sprites/$file). */
    private function texture(string $name, string $file, callable $make): array
    {
        $sdl = $this->sdl ?? throw new \LogicException('Sprites::attach() first');
        if (!$sdl->hasTexture($name)) {
            $png = 'assets/sprites/' . $file . '.png';
            if (is_file($this->root . '/' . $png)) {
                $sdl->loadTexture($name, $png);
            } else {
                $make($name);
            }
        }
        return $sdl->getTexture($name);
    }

    // ---- towers -----------------------------------------------------------------------------------------------

    /** Draws a tower standing with its foot at ($x, $y). $specIndex: which of its two specializations $spec is. */
    public function drawTower(Renderer $r, string $sprite, int $level, ?string $spec, float $x, float $y, float $scale = 1.0,
                              ?int $specIndex = null): void
    {
        $key = $spec ?? (string)min(3, $level);
        $tex = $this->texture("tower:$sprite:$key", "tower-$sprite-$key",
            fn (string $name) => $this->makeTower($name, $sprite, min(3, $level), $spec, $specIndex));
        $w = $tex['width'] * $scale;
        $h = $tex['height'] * $scale;
        $r->copy($tex['texture'], null, round($x - $w / 2), round($y - $h), $w, $h);
    }

    private function makeTower(string $name, string $sprite, int $level, ?string $spec, ?int $specIndex): void
    {
        $w = self::TOWER_W;
        $h = self::TOWER_H;
        $img = imagecreatetruecolor($w, $h);
        imagealphablending($img, false);
        imagesavealpha($img, true);
        $clear = imagecolorallocatealpha($img, 0, 0, 0, 127);
        imagefill($img, 0, 0, $clear);
        if (in_array($sprite, TowerArt::SPRITES, true)) {
            // the other sides' towers
            TowerArt::paint($img, $sprite, $level, $spec !== null ? (int)$specIndex : null);
            $this->sdl?->createTextureFromGd($name, $img);
            return;
        }
        $c = static function (string $hex) use ($img): int {
            $col = Color::of($hex);
            return imagecolorallocatealpha($img, $col->r, $col->g, $col->b, 127 - intdiv($col->a, 2));
        };
        $rect = static fn (int $x, int $y, int $rw, int $rh, int $col) => imagefilledrectangle($img, $x, $y, $x + $rw - 1, $y + $rh - 1, $col);
        $poly = static fn (array $points, int $col) => imagefilledpolygon($img, $points, $col);
        $oval = static fn (int $cx, int $cy, int $ow, int $oh, int $col) => imagefilledellipse($img, $cx, $cy, $ow, $oh, $col);
        $ink = $c('#1a1424');
        $gold = $c('#e8c040');
        $bottom = $h - 1;
        $top = $h - 20 - 4 * $level;   // taller with each level

        switch ($sprite) {
            case 'archer':
                $stone = $c('#d8d4c4');
                $shade = $c('#a09c8c');
                $roof = $c($spec === 'keen_eye' ? '#3a8ad0' : '#3fa860');
                $roofDark = $c($spec === 'keen_eye' ? '#1d4a80' : '#1d5434');
                if ($spec === 'storm_of_shafts') {
                    foreach ([2, 20] as $sx) {
                        $rect($sx - 1, $bottom - 15, 8, 16, $ink);
                        $rect($sx, $bottom - 14, 6, 14, $stone);
                        $poly([$sx - 1, $bottom - 15, $sx + 6, $bottom - 15, $sx + 3, $bottom - 21], $roof);
                    }
                }
                $rect(8, $top + 9, 12, $bottom - $top - 8, $ink);
                $rect(9, $top + 10, 10, $bottom - $top - 10, $stone);
                $rect(16, $top + 10, 3, $bottom - $top - 10, $shade);
                for ($y = $top + 13; $y < $bottom - 1; $y += 4) {
                    $rect(9, $y, 10, 1, $shade);
                }
                $rect(12, $top + 13, 3, 4, $ink);
                $rect(12, $bottom - 5, 4, 5, $c('#5a3a20'));
                if ($level >= 2) {
                    $rect(6, $top + 8, 16, 3, $ink);
                    $rect(7, $top + 8, 14, 2, $level >= 3 ? $gold : $shade);
                }
                $poly([6, $top + 9, 22, $top + 9, 14, $top], $ink);
                $poly([7, $top + 8, 21, $top + 8, 14, $top + 1], $roof);
                $poly([14, $top + 1, 21, $top + 8, 14, $top + 8], $roofDark);
                if ($level >= 3) {
                    $rect(14, $top - 6, 1, 7, $gold);
                    $rect(15, $top - 6, 5, 3, $roof);
                }
                if ($spec === 'keen_eye') {
                    $oval(14, $top + 5, 3, 3, $c('#c0f0ff'));
                }
                break;

            case 'sanctuary':
                $white = $c('#f0f0e8');
                $shade = $c('#b0b8c8');
                $glow = $c($spec === 'frost_moon' ? '#a0f0ff' : ($spec === 'starfall' ? '#ffe070' : '#9ab8ff'));
                // the dome: an oval whose lower half is cut away
                $oval(14, $top + 12, 22, 16, $ink);
                $oval(14, $top + 12, 20, 14, $white);
                $oval(16, $top + 13, 14, 10, $shade);
                $rect(2, $top + 12, 24, 9, $clear);
                $rect(4, $top + 11, 20, 2, $ink);
                foreach ([6, 19] as $px) {
                    $rect($px - 1, $top + 13, 5, $bottom - $top - 15, $ink);
                    $rect($px, $top + 13, 3, $bottom - $top - 16, $white);
                    $rect($px + 2, $top + 13, 1, $bottom - $top - 16, $shade);
                }
                $rect(3, $bottom - 3, 22, 4, $ink);
                $rect(4, $bottom - 2, 20, 3, $white);
                $rect(4, $bottom - 1, 20, 1, $shade);
                $orb = 4 + $level;
                $oval(14, $bottom - 9, $orb + 2, $orb + 2, $ink);
                $oval(14, $bottom - 9, $orb, $orb, $glow);
                // the crescent: a disc, and a transparent one over it
                $oval(14, $top + 1, 8, 8, $c('#f4f0b0'));
                $oval(16, $top, 7, 7, $clear);
                if ($level >= 3) {
                    $rect(5, $top + 9, 1, 1, $gold);
                    $rect(22, $top + 9, 1, 1, $gold);
                    $rect(8, $top + 4, 1, 1, $glow);
                    $rect(20, $top + 6, 1, 1, $glow);
                }
                if ($spec === 'starfall') {
                    $poly([14, $top - 6, 16, $top - 2, 20, $top - 2, 17, $top, 18, $top + 4, 14, $top + 2, 10, $top + 4, 11, $top, 8, $top - 2, 12, $top - 2], $gold);
                }
                break;

            case 'sentinel':
                $bark = $c($spec === 'ancient_oak' ? '#5a3a1c' : '#7a5230');
                $barkDark = $c('#4a3018');
                $leaf = $c($spec === 'thorn_grove' ? '#6a3a8a' : ($spec === 'ancient_oak' ? '#2e6a2a' : '#4caf50'));
                $leafDark = $c($spec === 'thorn_grove' ? '#3a1a5a' : '#2e7d32');
                $poly([7, $bottom, 11, $bottom - 6, 17, $bottom - 6, 21, $bottom, 17, $bottom - 2, 14, $bottom, 11, $bottom - 2], $bark);
                $rect(10, $top + 12, 9, $bottom - $top - 13, $ink);
                $rect(11, $top + 12, 7, $bottom - $top - 13, $bark);
                $rect(16, $top + 12, 2, $bottom - $top - 13, $barkDark);
                $rect(12, $bottom - 12, 2, 2, $c('#ffe060'));
                $rect(15, $bottom - 12, 2, 2, $c('#ffe060'));
                $rect(12, $bottom - 8, 5, 1, $barkDark);
                $crown = 14 + 2 * $level;
                $oval(14, $top + 8, $crown + 2, $crown - 2, $ink);
                $oval(8, $top + 10, 10, 8, $leafDark);
                $oval(20, $top + 10, 10, 8, $leafDark);
                $oval(14, $top + 8, $crown, $crown - 4, $leaf);
                $oval(11, $top + 5, 6, 4, $c($spec === 'thorn_grove' ? '#a070c0' : '#8ad07a'));
                if ($level >= 2) {
                    // branch arms
                    $rect(5, $bottom - 14, 6, 2, $bark);
                    $rect(18, $bottom - 16, 6, 2, $bark);
                    $oval(5, $bottom - 16, 5, 4, $leaf);
                    $oval(24, $bottom - 18, 5, 4, $leaf);
                }
                if ($level >= 3) {
                    $rect(9, $top + 4, 1, 1, $gold);
                    $rect(19, $top + 7, 1, 1, $gold);
                    $rect(15, $top + 2, 1, 1, $gold);
                }
                if ($spec === 'thorn_grove') {
                    foreach ([[6, 8], [22, 6], [10, 13], [19, 12]] as [$tx, $ty]) {
                        $rect($tx, $top + $ty, 1, 2, $c('#e0d0f0'));
                    }
                }
                break;

            default: // songmage
                $robe = $c($spec === 'dirge_of_dread' ? '#5a2a3a' : '#7a4ab8');
                $robeDark = $c($spec === 'dirge_of_dread' ? '#2a1018' : '#4a2a78');
                $gem = $c($spec === 'stormsinger' ? '#fff070' : ($spec === 'dirge_of_dread' ? '#ff4040' : '#90f0ff'));
                $poly([7, $bottom, 21, $bottom, 17, $top + 10, 11, $top + 10], $ink);
                $poly([8, $bottom - 1, 20, $bottom - 1, 16, $top + 11, 12, $top + 11], $robe);
                $poly([14, $top + 11, 16, $top + 11, 20, $bottom - 1, 14, $bottom - 1], $robeDark);
                $rect(12, $bottom - 10, 4, 3, $gold);
                $rect(13, $bottom - 4, 2, 4, $ink);
                $rect(11, $top + 8, 7, 3, $ink);
                $rect(12, $top + 9, 5, 1, $gold);
                $poly([14, $top - 1, 19, $top + 4, 14, $top + 9, 9, $top + 4], $ink);
                $poly([14, $top, 18, $top + 4, 14, $top + 8, 10, $top + 4], $gem);
                $rect(12, $top + 3, 2, 2, $c('#ffffff'));
                if ($level >= 2) {
                    // floating notes
                    $rect(3, $top + 12, 2, 2, $gold);
                    $rect(4, $top + 7, 1, 6, $gold);
                    $rect(5, $top + 7, 2, 1, $gold);
                }
                if ($level >= 3) {
                    $rect(23, $top + 16, 2, 2, $gold);
                    $rect(24, $top + 11, 1, 6, $gold);
                }
                if ($spec === 'stormsinger') {
                    $poly([22, $top, 25, $top, 23, $top + 4, 26, $top + 4, 21, $top + 10, 22, $top + 5, 20, $top + 5], $gem);
                }
                break;
        }
        $this->sdl?->createTextureFromGd($name, $img);
    }

    // ---- enemies ----------------------------------------------------------------------------------------------

    /** Pixel art of the enemies (facing right): two frames each, and a palette. */
    private const HUMANOID = [
        '....kkkk....',
        '...khhhhk...',
        '...kssssk.w.',
        '...ksesek.w.',
        '...kstssk.w.',
        '..kkkaakkkw.',
        '.ksAaaaaAsk.',
        '.kskaAAaksk.',
        '...kaaaak...',
    ];
    private const HUMANOID_LEGS = [
        ['...kAkkAk...', '...kSk.kSk..', '...kk...kk..'],
        ['...kAkkAk...', '....kSkSk...', '....kk.kk...'],
    ];
    private const GOBLIN = [
        'k........k',
        'kskkkkkksk',
        '.kssssssk.',
        '..ksesesk.',
        '...kssk..w',
        '..kaaaak.w',
        '.kskaakskw',
        '...kaak...',
    ];
    private const GOBLIN_LEGS = [
        ['...kSkSk..', '...kk.kk..'],
        ['..kSk.kSk.', '..kk...kk.'],
    ];
    private const WARG = [
        '......kkk.......',
        '.....ksssk......',
        '.....ksesk......',
        '....kkaaakk.....',
        '...kaaaaaaak..kk',
        'kk.kffffffffkkfk',
        'kfkfffffffffffek',
        '.kfFffffffffffk.',
        '..kfFFFFFFFfkk..',
    ];
    private const WARG_LEGS = [
        ['..kfk.kfk.kfk...', '..kk..kk..kk....'],
        ['.kfk..kfk..kfk..', '.kk...kk...kk...'],
    ];
    private const BAT = [
        [
            'k............k',
            'kk..........kk',
            'kmk..k..k..kmk',
            'kmmk.kkkk.kmmk',
            '.kmmkbbbekmmk.',
            '..kmkbbbbkmk..',
            '...kk.kk.kk...',
            '..............',
        ],
        [
            '..............',
            '.....k..k.....',
            '.....kkkk.....',
            '..kkkbbbekkk..',
            '.kmmmbbbbmmmk.',
            'kmmk.kkkk.kmmk',
            'kmk........kmk',
            'kk..........kk',
        ],
    ];
    private const RAM = [
        '...kkkkkkkkkk.....',
        '..krrrrrrrrrrk....',
        '.krrrrrrrrrrrrk...',
        '.kwwkwwkwwkwwwk...',
        '.kwwkwwkwwkwwwk...',
        'kklllllllllllllkkk',
        'kLLLLLLLLLLLLLLLiik',
        'kklllllllllllllkkk',
        '.kwwwwwwwwwwwwwk..',
    ];
    private const RAM_WHEELS = [
        ['.kkokkkkkkkkokkk..', '..kok......kok....', '...k........k.....'],
        ['.kkokkkkkkkkokkk..', '..kko......kko....', '...k........k.....'],
    ];
    private const TROLL = [
        '.......kkkkkk.......',
        '......kssssssk......',
        '.....kssessesk......',
        '.....ksssssssk......',
        '.....kssttttsk...cc.',
        '......kssssssk..kcck',
        '...kkkkssssssskkkcck',
        '..kssssSSSSSSssskck.',
        '.kssskSSSSSSSSksskk.',
        '.ksskSaaaaaaaaSksk..',
        '.ksk.kaaaaaaaak.kk..',
        '.ksk.kaAAaaAAak.....',
        '.kk..kaaaaaaaak.....',
        '.....kSSSkkSSSk.....',
    ];
    private const TROLL_LEGS = [
        ['.....kSSk..kSSk.....', '....kSSSk..kSSSk....', '....kkkkk..kkkkk....'],
        ['......kSSk.kSSk.....', '.....kSSSkkSSSk.....', '.....kkkkkkkkkk.....'],
    ];

    private const DWARF = [
        '...khhhhk...',
        '..khhhhhhk..',
        '..kssesesk.w',
        '..kbbbbbbk.w',
        '.kabbbbbbak.',
        'kaakbbbbkaak',
        '.k.kaaaak.k.',
        '...kaaaak...',
    ];
    private const DWARF_LEGS = [
        ['...kAkkAk...', '...kk..kk...'],
        ['..kAk..kAk..', '..kk....kk..'],
    ];

    /**
     * Each sprite: [rows, legs per frame, palette], or [frames, null, palette] for the winged ones.
     * @return array<string, array{0: list<mixed>, 1: list<list<string>>|null, 2: array<string, string>}>
     */
    private static function enemyArt(): array
    {
        $ink = '#141018';
        $wings = static fn (string $wing, string $body, string $eye) => [self::BAT, null, ['k' => $ink, 'm' => $wing, 'b' => $body, 'e' => $eye]];
        $human = static fn (array $p) => [self::HUMANOID, self::HUMANOID_LEGS, ['k' => $ink, 't' => $p['s']] + $p];
        $dwarf = static fn (array $p) => [self::DWARF, self::DWARF_LEGS, ['k' => $ink] + $p];
        $small = static fn (array $p) => [self::GOBLIN, self::GOBLIN_LEGS, ['k' => $ink] + $p];
        $mounted = static fn (array $p) => [self::WARG, self::WARG_LEGS, ['k' => $ink] + $p];
        return [
            // the alliance
            'elf' => $human(['h' => '#f0d870', 's' => '#f0d0b0', 'S' => '#5a8a3a', 'e' => '#2a5a2a', 'a' => '#3f8a50', 'A' => '#1d5434', 'w' => '#8a6a3a']),
            'blade' => $human(['h' => '#e8e8f0', 's' => '#f0d0b0', 'S' => '#4a3a6a', 'e' => '#3a2a6a', 'a' => '#7a5ab0', 'A' => '#3a2a6a', 'w' => '#e0e8f0']),
            'warden' => $human(['h' => '#fff0a0', 's' => '#f8e0c8', 'S' => '#c8a040', 'e' => '#2a6aa0', 'a' => '#f0f0e0', 'A' => '#c8a040', 'w' => '#a0e0ff']),
            'eagle' => $wings('#8a5a2a', '#5a3a1a', '#ffd020'),
            'dwarf' => $dwarf(['h' => '#9aa0aa', 's' => '#e8b890', 'e' => '#2a2a2a', 'b' => '#c86a2a', 'a' => '#6a7280', 'A' => '#3a4048', 'w' => '#c8c8d0']),
            'miner' => $dwarf(['h' => '#e8c040', 's' => '#e8b890', 'e' => '#2a2a2a', 'b' => '#8a5a2a', 'a' => '#6a5040', 'A' => '#3a2a20', 'w' => '#a0a0a8']),
            'runebearer' => $dwarf(['h' => '#3a5a9a', 's' => '#e8b890', 'e' => '#2a2a2a', 'b' => '#e8e8e8', 'a' => '#3a5a9a', 'A' => '#1e3050', 'w' => '#80e0ff']),
            'thane' => $dwarf(['h' => '#e8c040', 's' => '#e8b890', 'e' => '#2a2a2a', 'b' => '#b03a20', 'a' => '#a02a20', 'A' => '#5a1810', 'w' => '#e8c040']),
            'rider' => $mounted(['s' => '#f0c8a0', 'e' => '#3a2a1a', 'a' => '#c08a30', 'f' => '#a0703a', 'F' => '#6a4a26']),
            'horsearcher' => $mounted(['s' => '#f0c8a0', 'e' => '#3a2a1a', 'a' => '#3f7a40', 'f' => '#d8d0c0', 'F' => '#9a9080']),
            'horselord' => $mounted(['s' => '#f0c8a0', 'e' => '#3a2a1a', 'a' => '#e8c040', 'f' => '#f0f0f0', 'F' => '#b0b0b0']),
            'scout' => $small(['s' => '#e0b890', 'S' => '#4a4a50', 'e' => '#2a2a2a', 'a' => '#3a3a44', 'w' => '#c8c8d0']),
            'littleman' => $small(['s' => '#e8c098', 'S' => '#6a4a2a', 'e' => '#2a2a2a', 'a' => '#5a8a3a', 'w' => '#8a6a3a']),
            'captain' => $small(['s' => '#e8c098', 'S' => '#6a4a2a', 'e' => '#2a2a2a', 'a' => '#c84a3a', 'w' => '#e8c040']),
            // the horde
            'warlord' => $human(['h' => '#3a3a40', 's' => '#4a7a2a', 'S' => '#2a4a1a', 'e' => '#ff3010', 'a' => '#7a1a14', 'A' => '#3a0c08', 'w' => '#c8c8c8']),
            'grunt' => [self::HUMANOID, self::HUMANOID_LEGS, ['k' => $ink, 'h' => '#5e9e3a', 's' => '#5e9e3a', 'S' => '#3c6e26',
                'e' => '#e03030', 't' => '#f0f0d0', 'a' => '#7a4a2a', 'A' => '#4e2e18', 'w' => '#b8b8c0']],
            'iron_orc' => [self::HUMANOID, self::HUMANOID_LEGS, ['k' => $ink, 'h' => '#8a8f99', 's' => '#4f8a32', 'S' => '#2f5a1e',
                'e' => '#ff5020', 't' => '#f0f0d0', 'a' => '#7d828c', 'A' => '#4c5058', 'w' => '#5a3a20']],
            'goblin' => [self::GOBLIN, self::GOBLIN_LEGS, ['k' => $ink, 's' => '#a8b840', 'S' => '#6a7a20', 'e' => '#ffe020',
                'a' => '#6a4a8a', 'w' => '#c8c8d0']],
            'warg' => [self::WARG, self::WARG_LEGS, ['k' => $ink, 's' => '#5e9e3a', 'e' => '#ff3030', 'a' => '#7a4a2a',
                'f' => '#6b5a4a', 'F' => '#4a3c30']],
            'bat' => $wings('#5a3a6a', '#3a2a40', '#ff4040'),
            'ram' => [self::RAM, self::RAM_WHEELS, ['k' => $ink, 'r' => '#8a3a2a', 'w' => '#8a6a3a', 'l' => '#6a4a2a',
                'L' => '#4a3018', 'i' => '#a0a0aa', 'o' => '#3a3a3a']],
            'troll' => [self::TROLL, self::TROLL_LEGS, ['k' => $ink, 's' => '#7a8aa0', 'S' => '#55657a', 'e' => '#ffd030',
                't' => '#f0f0d0', 'a' => '#6a5030', 'A' => '#4a3420', 'c' => '#5a4028']],
        ];
    }

    /** Size of an enemy's picture. @return array{0: int, 1: int} */
    public function enemySize(string $sprite): array
    {
        $tex = $this->enemyTexture($sprite, 0);
        return [$tex['width'], $tex['height']];
    }

    /** Draws an enemy centred on ($x, $y) (its feet a little below). */
    public function drawEnemy(Renderer $r, string $sprite, int $frame, float $x, float $y, bool $flip = false, float $scale = 1.0): void
    {
        $tex = $this->enemyTexture($sprite, $frame % 2);
        $w = $tex['width'] * $scale;
        $h = $tex['height'] * $scale;
        $r->copy($tex['texture'], null, round($x - $w / 2), round($y - $h / 2), $w, $h, $flip);
    }

    private function enemyTexture(string $sprite, int $frame): array
    {
        $art = self::enemyArt()[$sprite] ?? self::enemyArt()['grunt'];
        return $this->texture("enemy:$sprite:$frame", "enemy-$sprite-$frame", function (string $name) use ($art, $frame): void {
            $rows = $art[1] === null ? $art[0][$frame] : array_merge($art[0], $art[1][$frame]);
            $this->sdl?->createTextureFromPixels($name, $rows, $art[2]);
        });
    }

    // ---- icons ------------------------------------------------------------------------------------------------

    private const ICONS = [
        'heart' => [['.kk.kk.', 'krrkrrk', 'krrrrrk', 'krrrrrk', '.krrrk.', '..krk..', '...k...'], ['k' => '#3a0a10', 'r' => '#e83850']],
        'coin' => [['.kkkk.', 'kyyyyk', 'kyYyyk', 'kyYyyk', 'kyyyyk', '.kkkk.'], ['k' => '#6a4a10', 'y' => '#f0c840', 'Y' => '#fff0a0']],
        'flag' => [['kk.....', 'krrrrk.', 'krrrrrk', 'krrrrk.', 'kk.....', 'k......', 'k......'], ['k' => '#2a2a2a', 'r' => '#d04040']],
        'star' => [['....k....', '...kyk...', 'kkkkyykkk', '.kyyyyyk.', '..kyyyk..', '.kyykyyk.', '.kk...kk.'], ['k' => '#6a4a10', 'y' => '#ffd84a']],
        'star_off' => [['....k....', '...kgk...', 'kkkkggkkk', '.kgggggk.', '..kgggk..', '.kggkggk.', '.kk...kk.'], ['k' => '#202028', 'g' => '#505060']],
        'up' => [['...k...', '..kwk..', '.kwwwk.', 'kwwwwwk', '..kwk..', '..kwk..', '..kkk..'], ['k' => '#103010', 'w' => '#80f080']],
        'sell' => [['.kkkk.', 'kyyyyk', 'ky..yk', 'ky..yk', 'kyyyyk', '.kkkk.'], ['k' => '#6a4a10', 'y' => '#f0c840']],
        'target' => [['..kkk..', '.k...k.', 'k..r..k', 'k.rrr.k', 'k..r..k', '.k...k.', '..kkk..'], ['k' => '#e0e0e0', 'r' => '#ff5050']],
        'pause' => [['ww.ww', 'ww.ww', 'ww.ww', 'ww.ww', 'ww.ww'], ['w' => '#ffffff']],
        'play' => [['w....', 'www..', 'wwwww', 'www..', 'w....'], ['w' => '#ffffff']],
        'skull' => [['.kkkk.', 'kwwwwk', 'kwkkwk', 'kwwwwk', '.kwwk.', '.kkkk.'], ['k' => '#202020', 'w' => '#e8e8e0']],
        'lock' => [['.kkk.', 'k...k', 'kkkkk', 'kyyyk', 'kyyyk', 'kkkkk'], ['k' => '#202020', 'y' => '#a0a0a8']],
    ];

    public function drawIcon(Renderer $r, string $icon, float $x, float $y, float $scale = 1.0): void
    {
        $tex = $this->texture("icon:$icon", "icon-$icon", function (string $name) use ($icon): void {
            [$rows, $palette] = self::ICONS[$icon];
            $this->sdl?->createTextureFromPixels($name, $rows, $palette);
        });
        $r->copy($tex['texture'], null, round($x), round($y), $tex['width'] * $scale, $tex['height'] * $scale);
    }

    /** @return array{0: int, 1: int} */
    public function iconSize(string $icon): array
    {
        return [strlen(self::ICONS[$icon][0][0]), count(self::ICONS[$icon][0])];
    }

    // ---- maps -------------------------------------------------------------------------------------------------

    /** The map's ground (scenery, road, slots, the bastion in the side's colours), one texture of 480 x 240. */
    public function drawMap(Renderer $r, string $mapId, array $map, array $colors, float $x, float $y, float $scale = 1.0): void
    {
        $variant = ltrim((string)($colors['main'] ?? ''), '#');
        $tex = $this->texture("map:$mapId:$variant", "map-$mapId", fn (string $name) => $this->makeMap($name, $map, $colors));
        $r->copy($tex['texture'], null, $x, $y, $tex['width'] * $scale, $tex['height'] * $scale);
    }

    /** The colours of each map theme: ground (4), road (3, edge, pebble), trees (dark, mid, light), water (2). */
    public const THEMES = [
        'meadow' => ['grass' => ['#4f8f3a', '#58993f', '#467f33', '#5fa346'], 'road' => ['#c0a06a', '#b4945e', '#caa974', '#8a7048', '#9a7e50'],
            'tree' => ['#1f4a22', '#2f6a2c', '#4a8a3a'], 'water' => ['#3a6ea8', '#6a9ed0']],
        'marsh' => ['grass' => ['#3f7a44', '#47854a', '#36683c', '#5a9450'], 'road' => ['#8a7a54', '#7e6e4a', '#968660', '#5a4e34', '#6e6040'],
            'tree' => ['#1a3a2a', '#2a5a3a', '#3e7a4a'], 'water' => ['#2e5a6e', '#5a8a9a']],
        'quarry' => ['grass' => ['#8a7a5a', '#94845f', '#7a6c50', '#a09068'], 'road' => ['#c8bca0', '#bcb094', '#d4c8ac', '#7a6e58', '#a09478'],
            'tree' => ['#2a4a2a', '#3a5a32', '#5a7a44'], 'water' => ['#4a6a8a', '#7a9aba']],
        'autumn' => ['grass' => ['#7a7a32', '#86843a', '#6a6a2a', '#9a8a3a'], 'road' => ['#b8925a', '#ac8650', '#c49e66', '#7a5a34', '#8e6e44'],
            'tree' => ['#7a2a14', '#b04a1a', '#e08a2a'], 'water' => ['#3a5a8a', '#6a8aba']],
        'snow' => ['grass' => ['#dce4ec', '#e8eef4', '#c8d2dc', '#f4f8fc'], 'road' => ['#a0a8b4', '#949ca8', '#acb4c0', '#6a7280', '#7e8694'],
            'tree' => ['#1e3a3a', '#2e5050', '#e8f0f4'], 'water' => ['#7aa0c8', '#c8e0f4']],
        'ash' => ['grass' => ['#4a4440', '#544e48', '#3e3a36', '#625a52'], 'road' => ['#8a6a50', '#7e6048', '#967458', '#4a3428', '#6a4a38'],
            'tree' => ['#1a1614', '#2e2620', '#4a3a2e'], 'water' => ['#7a2a14', '#c8501e']],
    ];

    /** The scenery rows of a map: its own, or scattered from its `scenery` (the same each time). @return list<string> */
    public static function sceneryRows(array $map, string $seed): array
    {
        if (isset($map['rows'])) {
            return $map['rows'];
        }
        $rows = [];
        for ($ty = 0; $ty < GameData::ROWS; $ty++) {
            $row = '';
            for ($tx = 0; $tx < GameData::COLS; $tx++) {
                $roll = (crc32("$seed:$tx:$ty") & 0xffff) / 65536;
                $char = '.';
                foreach ((array)($map['scenery'] ?? []) as $kind => $share) {
                    if ($roll < (float)$share) {
                        $char = (string)$kind;
                        break;
                    }
                    $roll -= (float)$share;
                }
                $row .= $char;
            }
            $rows[] = $row;
        }
        return $rows;
    }

    private function makeMap(string $name, array $map, array $colors): void
    {
        $t = GameData::TILE;
        $w = GameData::COLS * $t;
        $h = GameData::ROWS * $t;
        $img = imagecreatetruecolor($w, $h);
        imagealphablending($img, false);
        $c = static function (string $hex) use ($img): int {
            $col = Color::of($hex);
            return imagecolorallocate($img, $col->r, $col->g, $col->b);
        };
        $theme = self::THEMES[$map['theme'] ?? 'meadow'] ?? self::THEMES['meadow'];
        $grass = array_map($c, $theme['grass']);
        $road = array_map($c, array_slice($theme['road'], 0, 3));
        $roadEdge = $c($theme['road'][3]);
        $pebble = $c($theme['road'][4]);
        $paths = array_map(static fn (array $p) => Path::fromTiles($p), $map['paths']);
        $nearRoad = static function (float $px, float $py) use ($paths): float {
            $best = INF;
            foreach ($paths as $path) {
                $best = min($best, $path->distanceTo($px, $py));
            }
            return $best;
        };
        for ($py = 0; $py < $h; $py++) {
            for ($px = 0; $px < $w; $px++) {
                $noise = crc32("$px,$py") & 0xff;
                $d = $nearRoad($px + 0.5, $py + 0.5);
                if ($d <= 13) {
                    $col = $noise < 12 ? $pebble : $road[$noise % 3];
                } elseif ($d <= 15) {
                    $col = $roadEdge;
                } else {
                    // grass with darker patches every few tiles
                    $patch = (crc32(intdiv($px, 6) . ':' . intdiv($py, 6)) & 7) === 0 ? 2 : $noise % 2;
                    $col = $noise > 248 ? $grass[3] : $grass[$patch];
                }
                imagesetpixel($img, $px, $py, $col);
            }
        }
        imagealphablending($img, true);

        // scenery, but never on the road nor on a slot or the castle
        $blocked = [];
        foreach ($map['slots'] as [$sx, $sy]) {
            for ($dy = 0; $dy < 2; $dy++) {
                for ($dx = 0; $dx < 2; $dx++) {
                    $blocked[($sx + $dx) . ',' . ($sy + $dy)] = true;
                }
            }
        }
        [$bx, $by] = $map['bastion'];
        for ($dy = 0; $dy < 6; $dy++) {
            for ($dx = 0; $dx < 3; $dx++) {
                $blocked[($bx + $dx) . ',' . ($by + $dy)] = true;
            }
        }
        [$treeDark, $tree, $treeLight] = array_map($c, $theme['tree']);
        $trunk = $c('#4a3018');
        $rock = $c('#8a8a88');
        $rockLight = $c('#b0b0aa');
        $rockDark = $c('#5a5a58');
        [$water, $waterLight] = array_map($c, $theme['water']);
        $shadow = imagecolorallocatealpha($img, 0, 0, 0, 90);
        $flowers = [$c('#f0e060'), $c('#f080a0'), $c('#ffffff'), $c('#a0a0ff')];
        foreach (self::sceneryRows($map, (string)($map['name'] ?? $name)) as $ty => $row) {
            for ($tx = 0, $n = strlen($row); $tx < $n; $tx++) {
                $char = $row[$tx];
                $cx = $tx * $t + 8;
                $cy = $ty * $t + 8;
                if ($char === '.' || isset($blocked["$tx,$ty"]) || $nearRoad($cx, $cy) < 18) {
                    continue;
                }
                $seed = crc32("$tx;$ty") & 0xffff;
                if ($char === 't') {
                    $ox = $seed % 5 - 2;
                    imagefilledellipse($img, $cx + $ox + 2, $cy + 6, 14, 6, $shadow);
                    imagefilledrectangle($img, $cx + $ox - 1, $cy + 1, $cx + $ox + 1, $cy + 6, $trunk);
                    imagefilledellipse($img, $cx + $ox, $cy - 2, 15, 13, $treeDark);
                    imagefilledellipse($img, $cx + $ox - 1, $cy - 3, 12, 10, $tree);
                    imagefilledellipse($img, $cx + $ox - 3, $cy - 5, 5, 4, $treeLight);
                } elseif ($char === 'r') {
                    imagefilledellipse($img, $cx + 1, $cy + 4, 12, 5, $shadow);
                    imagefilledellipse($img, $cx, $cy + 1, 12, 9, $rockDark);
                    imagefilledellipse($img, $cx - 1, $cy, 10, 7, $rock);
                    imagefilledellipse($img, $cx - 2, $cy - 1, 4, 3, $rockLight);
                } elseif ($char === 'w') {
                    imagefilledrectangle($img, $tx * $t, $ty * $t, $tx * $t + $t - 1, $ty * $t + $t - 1, $water);
                    imageline($img, $tx * $t + 3 + $seed % 4, $cy - 2, $tx * $t + 7 + $seed % 4, $cy - 2, $waterLight);
                    imageline($img, $tx * $t + 6 + $seed % 3, $cy + 4, $tx * $t + 10 + $seed % 3, $cy + 4, $waterLight);
                } elseif ($char === 'f') {
                    for ($k = 0; $k < 5; $k++) {
                        $fx = $tx * $t + 2 + (($seed >> $k) * 7) % 12;
                        $fy = $ty * $t + 2 + (($seed >> ($k + 3)) * 5) % 12;
                        imagesetpixel($img, $fx, $fy, $flowers[($seed + $k) % 4]);
                    }
                }
            }
        }

        // building slots: round stone platforms
        $slotDark = $c('#5a5850');
        $slot = $c('#8e8a7c');
        $slotLight = $c('#b4b0a0');
        $rune = $c($colors['light'] ?? '#c8f0b0');
        foreach ($map['slots'] as [$sx, $sy]) {
            $cx = ($sx + 1) * $t;
            $cy = ($sy + 1) * $t;
            imagefilledellipse($img, $cx + 1, $cy + 3, 30, 20, $shadow);
            imagefilledellipse($img, $cx, $cy + 1, 30, 20, $slotDark);
            imagefilledellipse($img, $cx, $cy, 28, 17, $slot);
            imagefilledellipse($img, $cx - 2, $cy - 2, 18, 9, $slotLight);
            imageline($img, $cx - 3, $cy, $cx + 3, $cy, $rune);
            imageline($img, $cx, $cy - 2, $cx, $cy + 2, $rune);
        }

        $this->paintBastion($img, $c, $bx * $t, $by * $t, $colors);
        $this->sdl?->createTextureFromGd($name, $img);
    }

    /** The castle the player defends, in the side's colours. */
    private function paintBastion(\GdImage $img, callable $c, int $x, int $y, array $colors): void
    {
        $ink = $c('#1a1424');
        $wall = $c('#d8d4c4');
        $wallDark = $c('#a09c8c');
        $banner = $c($colors['main'] ?? '#3fa860');
        $bannerDark = $c($colors['dark'] ?? '#1d5434');
        $gate = $c('#3a2614');
        $rect = static fn (int $rx, int $ry, int $rw, int $rh, int $col) => imagefilledrectangle($img, $rx, $ry, $rx + $rw - 1, $ry + $rh - 1, $col);
        // main wall with the gate on the road (row 12)
        $rect($x + 2, $y + 26, 44, 60, $ink);
        $rect($x + 3, $y + 27, 42, 58, $wall);
        $rect($x + 36, $y + 27, 9, 58, $wallDark);
        for ($bx = $x + 3; $bx < $x + 45; $bx += 6) {
            $rect($bx, $y + 22, 4, 5, $ink);
            $rect($bx + 1, $y + 23, 2, 4, $wall);
        }
        $rect($x + 1, $y + 40, 14, 18, $ink);
        $rect($x + 2, $y + 41, 12, 17, $gate);
        $rect($x + 2, $y + 49, 12, 1, $ink);
        // two towers
        foreach ([[$x + 10, 0], [$x + 32, 6]] as [$tx, $dy]) {
            $rect($tx - 7, $y + $dy + 6, 15, 30, $ink);
            $rect($tx - 6, $y + $dy + 7, 13, 29, $wall);
            $rect($tx + 2, $y + $dy + 7, 5, 29, $wallDark);
            imagefilledpolygon($img, [$tx - 8, $y + $dy + 7, $tx + 8, $y + $dy + 7, $tx, $y + $dy - 6], $banner);
            imagefilledpolygon($img, [$tx, $y + $dy - 6, $tx + 8, $y + $dy + 7, $tx, $y + $dy + 7], $bannerDark);
            $rect($tx - 1, $y + $dy + 14, 3, 5, $ink);
            $rect($tx, $y + $dy - 12, 1, 7, $ink);
            $rect($tx + 1, $y + $dy - 12, 6, 4, $banner);
        }
        $rect($x + 22, $y + 62, 10, 14, $banner);
        $rect($x + 22, $y + 62, 10, 2, $c('#e8c040'));
    }
}
