<?php

namespace JumPHP\Effects;

use JumPHP\Renderer\Renderer;
use JumPHP\Util\Color;

/**
 * Screen transitions: the screen is covered in a colour (a fade, a wipe from one side, an iris closing on a point, blinds, a dissolve of
 * squares), the game changes under it, then the screen is revealed the same way. One service for the whole game (it keeps its state
 * from a scene to the next):
 *
 *   $this->transition->cover('iris', 0.6, '#000000', fn () => $this->scenes()->switchTo(PlayScene::class, [], false));
 *   // in the new scene's enter(): $this->transition->reveal('iris', 0.6);
 *   // in update($dt): $this->transition->update($dt);   in render($r), last: $this->transition->render($r);
 *
 * cover() ends fully covering and stays so until reveal(); reveal() ends with nothing on the screen. The callback of either runs once, when it ends.
 * shapes() is what is drawn (rectangles with an alpha), kept apart from SDL so that it is easy to test.
 */
final class Transition
{
    public const TYPES = ['fade', 'wipe-left', 'wipe-right', 'wipe-up', 'wipe-down', 'iris', 'blinds', 'dissolve'];

    private string $type = 'fade';
    private float $seconds = 0.5;
    private float $time = 0;
    private bool $covering = true;
    private bool $active = false;
    private bool $finished = true;
    private Color $color;
    private string $colorSpec = '#000000';
    /** @var (callable(): void)|null */
    private $then = null;
    private float $focusX = 0.5;
    private float $focusY = 0.5;
    private int $seed = 1;
    /** @var array<string, list<float>> dissolve thresholds by grid size */
    private array $thresholds = [];

    public function __construct()
    {
        $this->color = Color::of('#000000');
    }

    /** Covers the screen. @param (callable(): void)|null $then runs once, when it is covered */
    public function cover(string $type = 'fade', float $seconds = 0.5, string $color = '#000000', ?callable $then = null): void
    {
        $this->begin($type, $seconds, true, $color, $then);
    }

    /** Reveals the screen again. @param (callable(): void)|null $then runs once, when it is revealed */
    public function reveal(string $type = 'fade', float $seconds = 0.5, string $color = '#000000', ?callable $then = null): void
    {
        $this->begin($type, $seconds, false, $color, $then);
    }

    /** The iris closes on (and opens from) this point, 0..1 of the screen (the player, a button). */
    public function focus(float $x, float $y): void
    {
        $this->focusX = max(0.0, min(1.0, $x));
        $this->focusY = max(0.0, min(1.0, $y));
    }

    /** The dissolve's pattern: the same seed, the same squares in the same order, on every platform. */
    public function seed(int $seed): void
    {
        $this->seed = max(1, $seed);
        $this->thresholds = [];
    }

    private function begin(string $type, float $seconds, bool $covering, string $color, ?callable $then): void
    {
        if (!in_array($type, self::TYPES, true)) {
            throw new \InvalidArgumentException("Unknown transition '$type' (" . implode(', ', self::TYPES) . ')');
        }
        if ($color !== $this->colorSpec) {
            $this->color = Color::of($color);
            $this->colorSpec = $color;
        }
        $this->type = $type;
        $this->seconds = max(0.01, $seconds);
        $this->covering = $covering;
        $this->time = 0;
        $this->active = true;
        $this->finished = false;
        $this->then = $then;
    }

    public function update(float $dt): void
    {
        if (!$this->active || $this->finished) {
            return;
        }
        $this->time = min($this->seconds, $this->time + $dt);
        if ($this->time >= $this->seconds) {
            $this->finished = true;
            $this->active = $this->covering;           // covered: it stays on the screen; revealed: gone
            $then = $this->then;
            $this->then = null;
            if ($then !== null) {
                $then();
            }
        }
    }

    /** Something is on the screen: moving, or covering it. */
    public function isActive(): bool
    {
        return $this->active;
    }

    /** The screen is fully covered (the moment to change what is under it). */
    public function isCovered(): bool
    {
        return $this->active && $this->covering && $this->finished;
    }

    /** Moving now (not yet at its end). */
    public function isRunning(): bool
    {
        return $this->active && !$this->finished;
    }

    /** 0..1 of the time of the movement gone. */
    public function progress(): float
    {
        return $this->active || $this->finished ? $this->time / $this->seconds : 0.0;
    }

    /** How much of the screen is covered, 0..1 (eased). */
    public function amount(): float
    {
        if (!$this->active) {
            return 0.0;
        }
        $t = Ease::inOutQuad($this->time / $this->seconds);
        return $this->covering ? $t : 1 - $t;
    }

    /**
     * What covers the screen: rectangles [x, y, width, height, alpha 0..255] in the transition's colour.
     *
     * @return list<array{0: int, 1: int, 2: int, 3: int, 4: int}>
     */
    public function shapes(int $w, int $h): array
    {
        $a = $this->amount();
        if ($a <= 0) {
            return [];
        }
        if ($a >= 1) {
            return [[0, 0, $w, $h, 255]];
        }
        return match ($this->type) {
            'fade' => [[0, 0, $w, $h, (int)round($a * 15) * 17]],                       // 16 shades: few cached textures
            'wipe-left' => [[0, 0, (int)round($a * $w), $h, 255]],
            'wipe-right' => [[$w - (int)round($a * $w), 0, (int)round($a * $w), $h, 255]],
            'wipe-up' => [[0, 0, $w, (int)round($a * $h), 255]],
            'wipe-down' => [[0, $h - (int)round($a * $h), $w, (int)round($a * $h), 255]],
            'iris' => $this->iris($w, $h, $a),
            'blinds' => $this->blinds($w, $h, $a),
            default => $this->dissolve($w, $h, $a),
        };
    }

    public function render(Renderer $r): void
    {
        foreach ($this->shapes($r->getWidth(), $r->getHeight()) as [$x, $y, $w, $h, $alpha]) {
            if ($w > 0 && $h > 0 && $alpha > 0) {
                $r->fillRect($x, $y, $w, $h, $alpha >= 255 ? $this->color : $this->color->withAlpha($alpha));
            }
        }
    }

    /** @return list<array{0: int, 1: int, 2: int, 3: int, 4: int}> a hole that closes: rows of 2 pixels, the width of the ring on each side */
    private function iris(int $w, int $h, float $a): array
    {
        $cx = $this->focusX * $w;
        $cy = $this->focusY * $h;
        // the hole must go from the farthest corner (nothing covered) to nothing
        $far = sqrt(max($cx, $w - $cx) ** 2 + max($cy, $h - $cy) ** 2);
        $radius = (1 - $a) * $far;
        $rows = [];
        for ($y = 0; $y < $h; $y += 2) {
            $bh = min(2, $h - $y);
            $dy = $y + $bh / 2 - $cy;
            if (abs($dy) >= $radius) {
                $rows[] = [0, $y, $w, $bh, 255];
                continue;
            }
            $half = sqrt($radius * $radius - $dy * $dy);
            $left = (int)floor($cx - $half);
            $right = (int)ceil($cx + $half);
            if ($left > 0) {
                $rows[] = [0, $y, min($w, $left), $bh, 255];
            }
            if ($right < $w) {
                $rows[] = [max(0, $right), $y, $w - max(0, $right), $bh, 255];
            }
        }
        return $rows;
    }

    /** @return list<array{0: int, 1: int, 2: int, 3: int, 4: int}> */
    private function blinds(int $w, int $h, float $a): array
    {
        $bars = 8;
        $barH = $h / $bars;
        $out = [];
        for ($i = 0; $i < $bars; $i++) {
            $y = (int)round($i * $barH);
            $out[] = [0, $y, $w, (int)ceil($barH * $a), 255];
        }
        return $out;
    }

    /** @return list<array{0: int, 1: int, 2: int, 3: int, 4: int}> squares appear in a seeded order; neighbours in a row are one rectangle */
    private function dissolve(int $w, int $h, float $a): array
    {
        $cell = max(8, (int)(ceil($w / 20 / 4) * 4));
        $cols = (int)ceil($w / $cell);
        $rows = (int)ceil($h / $cell);
        $key = "$cols x $rows";
        if (!isset($this->thresholds[$key])) {
            $s = (float)$this->seed;
            $list = [];
            for ($i = 0; $i < $cols * $rows; $i++) {
                $s = fmod($s * 16807.0, 2147483647.0);               // Lehmer's generator on floats: the same on a 32-bit PHP
                $list[] = $s / 2147483647.0;
            }
            $this->thresholds[$key] = $list;
        }
        $t = $this->thresholds[$key];
        $out = [];
        for ($row = 0; $row < $rows; $row++) {
            $start = null;
            for ($col = 0; $col <= $cols; $col++) {
                $covered = $col < $cols && $t[$row * $cols + $col] < $a;
                if ($covered && $start === null) {
                    $start = $col;
                } elseif (!$covered && $start !== null) {
                    $x = $start * $cell;
                    $out[] = [$x, $row * $cell, min($w, $col * $cell) - $x, min($cell, $h - $row * $cell), 255];
                    $start = null;
                }
            }
        }
        return $out;
    }
}
