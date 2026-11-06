<?php

namespace JumPHP\Tiles;

/**
 * A grid of whole numbers (a tile id for each cell, or just 0 = floor / 1 = wall): width x height, x to the right, y down. The other classes of
 * the extension read and make Grids; fromRows() / toRows() are how you write one by hand (and in tests).
 */
final class Grid
{
    /** @var list<int> row after row */
    private array $cells;

    public function __construct(public readonly int $width, public readonly int $height, int $fill = 0)
    {
        if ($width < 1 || $height < 1) {
            throw new \InvalidArgumentException('A grid needs a width and a height of 1 at least.');
        }
        $this->cells = array_fill(0, $width * $height, $fill);
    }

    /**
     * @param list<string> $rows e.g. ['#####', '#..>#'] (all of the same length)
     * @param array<string, int> $legend character => value (a character not in it is 0, '#' is 1 unless the legend says otherwise)
     */
    public static function fromRows(array $rows, array $legend = ['#' => 1]): self
    {
        $height = count($rows);
        $width = $height > 0 ? strlen($rows[0]) : 0;
        $grid = new self(max(1, $width), max(1, $height));
        foreach (array_values($rows) as $y => $row) {
            if (strlen($row) !== $width) {
                throw new \InvalidArgumentException('Row ' . ($y + 1) . ' is ' . strlen($row) . " wide, not $width.");
            }
            foreach (str_split($row) as $x => $c) {
                $grid->set($x, $y, $legend[$c] ?? ($c === '#' ? 1 : 0));
            }
        }
        return $grid;
    }

    /** @param array<int, string> $legend value => character @return list<string> */
    public function toRows(array $legend = [0 => '.', 1 => '#']): array
    {
        $rows = [];
        for ($y = 0; $y < $this->height; $y++) {
            $row = '';
            for ($x = 0; $x < $this->width; $x++) {
                $row .= $legend[$this->cells[$y * $this->width + $x]] ?? '?';
            }
            $rows[] = $row;
        }
        return $rows;
    }

    public function inBounds(int $x, int $y): bool
    {
        return $x >= 0 && $y >= 0 && $x < $this->width && $y < $this->height;
    }

    /** The value of a cell; outside the grid: $outside. */
    public function get(int $x, int $y, int $outside = 1): int
    {
        return $this->inBounds($x, $y) ? $this->cells[$y * $this->width + $x] : $outside;
    }

    public function set(int $x, int $y, int $value): void
    {
        if ($this->inBounds($x, $y)) {
            $this->cells[$y * $this->width + $x] = $value;
        }
    }

    public function fill(int $value, ?int $x = null, ?int $y = null, ?int $w = null, ?int $h = null): void
    {
        for ($cy = $y ?? 0; $cy < ($y ?? 0) + ($h ?? $this->height); $cy++) {
            for ($cx = $x ?? 0; $cx < ($x ?? 0) + ($w ?? $this->width); $cx++) {
                $this->set($cx, $cy, $value);
            }
        }
    }

    /** How many cells hold $value. */
    public function count(int $value): int
    {
        return count(array_filter($this->cells, fn ($c) => $c === $value));
    }

    /** @return list<int> the cells, row after row (for a TileMap layer's data) */
    public function toArray(): array
    {
        return $this->cells;
    }

    /** @return list<array{0: int, 1: int}> the cells around one: 4 (up, right, down, left) or 8 (and the corners), inside the grid */
    public function neighbors(int $x, int $y, bool $diagonal = false): array
    {
        $out = [];
        foreach ($diagonal ? [[0, -1], [1, 0], [0, 1], [-1, 0], [1, -1], [1, 1], [-1, 1], [-1, -1]] : [[0, -1], [1, 0], [0, 1], [-1, 0]] as [$dx, $dy]) {
            if ($this->inBounds($x + $dx, $y + $dy)) {
                $out[] = [$x + $dx, $y + $dy];
            }
        }
        return $out;
    }

    /**
     * The cells reachable from one through cells that are not $blocked (4 directions): a flood fill.
     *
     * @return array<string, true> "x,y" => true
     */
    public function region(int $x, int $y, int $blocked = 1): array
    {
        $seen = [];
        if (!$this->inBounds($x, $y) || $this->get($x, $y) === $blocked) {
            return $seen;
        }
        $stack = [[$x, $y]];
        $seen["$x,$y"] = true;
        while ($stack) {
            [$cx, $cy] = array_pop($stack);
            foreach ($this->neighbors($cx, $cy) as [$nx, $ny]) {
                if (!isset($seen["$nx,$ny"]) && $this->get($nx, $ny) !== $blocked) {
                    $seen["$nx,$ny"] = true;
                    $stack[] = [$nx, $ny];
                }
            }
        }
        return $seen;
    }
}
