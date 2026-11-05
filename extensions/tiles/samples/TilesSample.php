<?php

namespace App\Extensions\Tiles;

use JumPHP\Renderer\Renderer;
use JumPHP\Scene\AbstractScene;
use JumPHP\Tiles\Grid;
use JumPHP\Tiles\Level;
use JumPHP\Tiles\Path;

/**
 * Sample of jumphp/tiles: a cave or a dungeon made from a seed (Space: another seed, Tab: cave / dungeon) and the shortest way between its two ends.
 * JUMPHP_SCENE='App\Extensions\Tiles\TilesSample' bin/jumphp run <game>
 */
final class TilesSample extends AbstractScene
{
    public const LABEL = 'A level from a seed: start, exit, items and the shortest path';

    private const CELL = 5;

    private Grid $grid;

    private ?Level $level = null;

    /** @var list<array{0: int, 1: int}> */
    private array $path = [];

    private int $seed = 1;

    private bool $dungeon = false;

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
            $r->fillRect($x * self::CELL + 1, $y * self::CELL + 1, self::CELL - 2, self::CELL - 2, '#7a6a20');
        }
        foreach ($this->level->items ?? [] as [$x, $y]) {
            $r->fillRect($x * self::CELL + 1, $y * self::CELL + 1, self::CELL - 2, self::CELL - 2, '#ffd040');
        }
        if ($this->level) {
            $r->fillRect($this->level->start[0] * self::CELL, $this->level->start[1] * self::CELL, self::CELL, self::CELL, '#40d060');
            $r->fillRect($this->level->exit[0] * self::CELL, $this->level->exit[1] * self::CELL, self::CELL, self::CELL, '#ff5050');
        }
        $this->font()->draw($r, ($this->dungeon ? 'DUNGEON' : 'CAVE') . ' SEED ' . $this->seed . '  PATH ' . count($this->path), 4, $this->height() - 10, 1, '#ffffff');
    }

    private function build(): void
    {
        $w = intdiv($this->width(), self::CELL);
        $h = intdiv($this->height() - 14, self::CELL);
        $this->level = $this->dungeon ? Level::dungeon($w, $h, $this->seed) : Level::cave($w, $h, $this->seed);
        $this->grid = $this->level->grid;
        [$sx, $sy] = $this->level->start;
        [$ex, $ey] = $this->level->exit;
        $this->path = Path::find($this->grid, $sx, $sy, $ex, $ey) ?? [];
    }
}
