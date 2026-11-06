<?php

namespace JumPHP\Tiles;

/** A dungeon: random rooms that do not overlap, joined one to the next by L-shaped corridors. */
final class Rooms
{
    /** @var list<array{x: int, y: int, w: int, h: int}> */
    public array $rooms = [];

    public Grid $grid;

    public function __construct(int $width, int $height, int $seed = 1, int $count = 8, int $minSize = 4, int $maxSize = 9)
    {
        $rng = new Rng($seed);
        $this->grid = new Grid($width, $height, 1);
        for ($try = 0; $try < $count * 12 && count($this->rooms) < $count; $try++) {
            $w = $rng->between($minSize, $maxSize);
            $h = $rng->between($minSize, $maxSize);
            if ($width - $w - 2 < 1 || $height - $h - 2 < 1) {
                continue;
            }
            $room = ['x' => $rng->between(1, $width - $w - 1), 'y' => $rng->between(1, $height - $h - 1), 'w' => $w, 'h' => $h];
            foreach ($this->rooms as $other) {
                if ($room['x'] <= $other['x'] + $other['w'] && $room['x'] + $w >= $other['x'] && $room['y'] <= $other['y'] + $other['h'] && $room['y'] + $h >= $other['y']) {
                    continue 2;
                }
            }
            $this->rooms[] = $room;
            $this->grid->fill(0, $room['x'], $room['y'], $w, $h);
            if (count($this->rooms) > 1) {
                $a = $this->center($this->rooms[count($this->rooms) - 2]);
                $b = $this->center($room);
                $this->corridor($a[0], $a[1], $b[0], $b[1], $rng->chance(0.5));
            }
        }
    }

    /** @return array{0: int, 1: int} */
    public function center(array $room): array
    {
        return [$room['x'] + intdiv($room['w'], 2), $room['y'] + intdiv($room['h'], 2)];
    }

    private function corridor(int $x1, int $y1, int $x2, int $y2, bool $horizontalFirst): void
    {
        [$mx, $my] = $horizontalFirst ? [$x2, $y1] : [$x1, $y2];
        $this->line($x1, $y1, $mx, $my);
        $this->line($mx, $my, $x2, $y2);
    }

    private function line(int $x1, int $y1, int $x2, int $y2): void
    {
        for ($x = min($x1, $x2); $x <= max($x1, $x2); $x++) {
            $this->grid->set($x, $y1, 0);
        }
        for ($y = min($y1, $y2); $y <= max($y1, $y2); $y++) {
            $this->grid->set($x2, $y, 0);
        }
    }
}
