<?php

namespace App\View3d;

/**
 * The little things that fly in the battle: sparks and dust where a shot lands, a puff and a few coins when a
 * creature falls, embers over what burns, a trail behind glowing shots, dust and golden motes when a tower is built
 * or raised, green motes of a healing, a shower of light for the side's power. Pure PHP (no GL): the scene emits
 * them from the battle's events, steps them, and draws each as a small ball (World3d::ball()).
 *
 * A particle: x, y (height), z, its speed, its age and life (seconds), size, colour, glow (0: lit like the world, 1:
 * bright), gravity (units/s², negative: it rises), drag (how fast it slows, per second). At most MAX live at once: the
 * oldest go first. Random rolls come from a small seeded generator (no big products: 32-bit PHP in the browser).
 */
final class Particles
{
    public const MAX = 240;

    /** @var list<array{x: float, y: float, z: float, vx: float, vy: float, vz: float, age: float, life: float, size: float, rgb: array{0: float, 1: float, 2: float}, glow: float, gravity: float, drag: float}> */
    public array $list = [];

    private int $seed;

    public function __construct(int $seed = 7)
    {
        $this->seed = $seed & 0xffff;
    }

    public function clear(): void
    {
        $this->list = [];
    }

    public function count(): int
    {
        return count($this->list);
    }

    /** A random number in [$lo, $hi). */
    public function rand(float $lo = 0.0, float $hi = 1.0): float
    {
        $this->seed = ($this->seed * 75 + 74) % 65537;
        return $lo + ($hi - $lo) * ($this->seed / 65537);
    }

    /**
     * $count particles from ($x, $y, $z), thrown outwards at $speed (a little up: $up), living about $life seconds.
     * @param array{0: float, 1: float, 2: float} $rgb
     */
    public function burst(float $x, float $y, float $z, int $count, array $rgb, float $speed, float $up, float $life, float $size,
                          float $glow = 0.0, float $gravity = 60.0, float $drag = 2.0, float $spread = 0.0): void
    {
        for ($i = 0; $i < $count; $i++) {
            $a = $this->rand(0.0, 2 * M_PI);
            $v = $speed * $this->rand(0.4, 1.0);
            $d = $spread * $this->rand(0.0, 1.0);
            $shade = $this->rand(0.85, 1.15);
            $this->add([
                'x' => $x + cos($a) * $d, 'y' => $y, 'z' => $z + sin($a) * $d,
                'vx' => cos($a) * $v, 'vy' => $up * $this->rand(0.5, 1.2), 'vz' => sin($a) * $v,
                'age' => 0.0, 'life' => $life * $this->rand(0.7, 1.3), 'size' => $size * $this->rand(0.7, 1.3),
                'rgb' => [min(1.4, $rgb[0] * $shade), min(1.4, $rgb[1] * $shade), min(1.4, $rgb[2] * $shade)],
                'glow' => $glow, 'gravity' => $gravity, 'drag' => $drag,
            ]);
        }
    }

    /** Where a shot lands: sparks of its colour, dust on the ground; a ring of debris for a blast. */
    public function hit(string $kind, float $x, float $z, float $splash): void
    {
        [$rgb, $glow] = self::colourOf($kind);
        if ($splash > 0) {
            $this->burst($x, 2.0, $z, 14, $rgb, $splash * 2.2, 26.0, 0.7, 1.5, $glow, 70.0, 2.5, $splash * 0.3);
            $this->burst($x, 1.0, $z, 8, [0.62, 0.52, 0.40], $splash * 1.4, 10.0, 0.9, 2.4, 0.0, 20.0, 3.0, $splash * 0.4);
            return;
        }
        $this->burst($x, 9.0, $z, 5, $rgb, 26.0, 18.0, 0.35, 0.9, $glow, 80.0, 3.0);
    }

    /** A creature falls: a puff of dust, and coins jumping up when it gave gold. */
    public function kill(float $x, float $z, int $gold): void
    {
        $this->burst($x, 3.0, $z, 7, [0.78, 0.72, 0.62], 16.0, 8.0, 0.8, 2.6, 0.0, -6.0, 2.5, 2.0);
        if ($gold > 0) {
            $this->burst($x, 8.0, $z, min(6, 2 + intdiv($gold, 5)), [1.0, 0.82, 0.28], 10.0, 40.0, 0.75, 1.1, 0.8, 90.0, 0.5);
        }
    }

    /** A tower built or raised: dust around its foot and golden motes rising. */
    public function build(float $x, float $y, float $z, bool $upgrade): void
    {
        $this->burst($x, $y + 1.0, $z, 12, [0.80, 0.74, 0.62], 30.0, 6.0, 0.8, 2.8, 0.0, 10.0, 3.0, 10.0);
        $this->burst($x, $y + 6.0, $z, $upgrade ? 14 : 8, [1.0, 0.88, 0.45], 6.0, 24.0, 1.1, 1.0, 1.0, -8.0, 1.2, 9.0);
    }

    /** A tower sold: grey dust. */
    public function sell(float $x, float $y, float $z): void
    {
        $this->burst($x, $y + 2.0, $z, 14, [0.66, 0.64, 0.62], 26.0, 12.0, 0.9, 3.0, 0.0, 6.0, 2.5, 8.0);
    }

    /** A healing: green motes rising over the circle. */
    public function heal(float $x, float $z, float $radius): void
    {
        $this->burst($x, 3.0, $z, 10, [0.50, 1.0, 0.55], 4.0, 18.0, 0.9, 1.0, 1.0, -10.0, 1.0, $radius * 0.8);
    }

    /** The side's power: a shower of light and a ring of dust. */
    public function power(float $x, float $z, float $radius): void
    {
        $this->burst($x, 40.0, $z, 26, [1.0, 0.94, 0.70], 8.0, -30.0, 0.9, 1.4, 1.0, 50.0, 0.8, $radius * 0.9);
        $this->burst($x, 1.0, $z, 16, [0.80, 0.72, 0.58], $radius * 1.6, 8.0, 0.9, 3.0, 0.0, 10.0, 2.5, $radius * 0.3);
    }

    /** A creature reaches the castle: a red flare. */
    public function leak(float $x, float $z): void
    {
        $this->burst($x, 8.0, $z, 12, [1.0, 0.36, 0.28], 24.0, 26.0, 0.7, 1.4, 1.0, 50.0, 2.0);
    }

    /** One ember over something that burns. */
    public function ember(float $x, float $y, float $z): void
    {
        $this->burst($x, $y, $z, 1, [1.0, 0.55, 0.18], 3.0, 16.0, 0.6, 0.8, 1.0, -14.0, 1.0, 3.0);
    }

    /** A speck left behind a glowing shot. */
    public function trail(string $kind, float $x, float $y, float $z): void
    {
        [$rgb] = self::colourOf($kind);
        $this->burst($x, $y, $z, 1, $rgb, 1.5, 2.0, 0.35, 0.8, 1.0, 0.0, 1.0, 0.6);
    }

    /** Moves, slows and ages every particle; the dead ones go. */
    public function update(float $dt): void
    {
        if ($dt <= 0 || $this->list === []) {
            return;
        }
        $live = [];
        foreach ($this->list as $p) {
            $p['age'] += $dt;
            if ($p['age'] >= $p['life']) {
                continue;
            }
            $slow = max(0.0, 1.0 - $p['drag'] * $dt);
            $p['vx'] *= $slow;
            $p['vz'] *= $slow;
            $p['vy'] = $p['vy'] * $slow - $p['gravity'] * $dt;
            $p['x'] += $p['vx'] * $dt;
            $p['y'] += $p['vy'] * $dt;
            $p['z'] += $p['vz'] * $dt;
            if ($p['y'] < 0.4) {
                // on the ground: it stays there, sliding a little
                $p['y'] = 0.4;
                $p['vy'] = 0.0;
                $p['vx'] *= 0.5;
                $p['vz'] *= 0.5;
            }
            $live[] = $p;
        }
        $this->list = $live;
    }

    /** How much of a particle shows (fades in quickly, out slowly), and its size now. @return array{0: float, 1: float} */
    public static function look(array $p): array
    {
        $t = $p['age'] / max(0.001, $p['life']);
        $alpha = min(1.0, $t * 8.0) * (1.0 - $t * $t);
        return [max(0.0, $alpha), $p['size'] * (1.0 - 0.45 * $t)];
    }

    private function add(array $p): void
    {
        if (count($this->list) >= self::MAX) {
            array_shift($this->list);
        }
        $this->list[] = $p;
    }

    /** The colour of a kind of shot and how much it glows. @return array{0: array{0: float, 1: float, 2: float}, 1: float} */
    private static function colourOf(string $kind): array
    {
        return match ($kind) {
            'star' => [[1.0, 0.94, 0.55], 1.0],
            'rock' => [[0.67, 0.55, 0.40], 0.0],
            'pitch', 'spark' => [[1.0, 0.55, 0.16], 1.0],
            'orb', 'note' => [[0.78, 0.60, 1.0], 1.0],
            'pulse' => [[1.0, 1.0, 1.0], 1.0],
            'root' => [[0.40, 0.56, 0.22], 0.0],
            default => [[0.95, 0.90, 0.78], 0.3],
        };
    }
}
