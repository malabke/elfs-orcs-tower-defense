<?php

namespace App\Scene;

use JumPHP\Renderer\Renderer;
use JumPHP\Scene\AbstractScene;
use JumPHP\Tiles\Caves;
use JumPHP\Tiles\Grid;
use JumPHP\Tiles\Path;
use JumPHP\Tiles\Rooms;

/**
 * Sample of jumphp/tiles: a cave or a dungeon made from a seed (Space: another seed, Tab: cave / dungeon) and the shortest way between its two ends.
 */
final class DemoScene extends AbstractScene
{
    public const LABEL = 'A cave or a dungeon from a seed, with the shortest path between its ends';

    private const CELL = 5;

    public Grid $grid;

    /** @var list<array{0: int, 1: int}> */
    public array $path = [];

    public int $seed = 1;

    public bool $dungeon = false;

    public function enter(array $params = []): void
    {
        $this->seed = (int)($params['seed'] ?? 1);
        $this->dungeon = (bool)($params['dungeon'] ?? false);
        $this->build();
    }

    public function update(float $dt): void
    {
        if ($this->actions()->isPressed('regenerate')) {
            $this->seed++;
            $this->build();
        }
        if ($this->actions()->isPressed('mode')) {
            $this->dungeon = !$this->dungeon;
            $this->build();
        }
    }

    public function render(Renderer $r): void
    {
        $r->clear([12, 12, 28]);
        for ($y = 0; $y < $this->grid->height; $y++) {
            for ($x = 0; $x < $this->grid->width; $x++) {
                $r->fillRect($x * self::CELL, $y * self::CELL, self::CELL, self::CELL, $this->grid->get($x, $y) === 1 ? '#3a3a5c' : '#14142a');
            }
        }
        foreach ($this->path as [$x, $y]) {
            $r->fillRect($x * self::CELL + 1, $y * self::CELL + 1, self::CELL - 2, self::CELL - 2, '#ffd040');
        }
        $this->font()->draw($r, ($this->dungeon ? 'DUNGEON' : 'CAVE') . ' SEED ' . $this->seed . '  PATH ' . count($this->path), 4, $this->height() - 10, 1, '#ffffff');
    }

    private function build(): void
    {
        $w = intdiv($this->width(), self::CELL);
        $h = intdiv($this->height() - 14, self::CELL);
        $this->grid = $this->dungeon ? (new Rooms($w, $h, $this->seed))->grid : Caves::generate($w, $h, $this->seed);
        $open = [];
        for ($y = 0; $y < $h; $y++) {
            for ($x = 0; $x < $w; $x++) {
                if ($this->grid->get($x, $y) === 0) {
                    $open[] = [$x, $y];
                }
            }
        }
        $this->path = $open ? (Path::find($this->grid, $open[0][0], $open[0][1], $open[count($open) - 1][0], $open[count($open) - 1][1]) ?? []) : [];
    }
}
