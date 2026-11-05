<?php

namespace JumPHP\Collision;

/**
 * A broad phase: a grid of cells, each holding the ids of the shapes that touch it, so that only shapes in the same cell are tested against
 * each other (instead of all against all). cell: about the size of the commonest shape.
 */
final class SpatialHash
{
    /** @var array<string, array<string|int, true>> */
    private array $cells = [];
    /** @var array<string|int, list<string>> the cells of each id */
    private array $where = [];

    public function __construct(private float $cell = 64.0)
    {
        if ($cell <= 0) {
            throw new \InvalidArgumentException('The cell size must be above 0.');
        }
    }

    public function insert(string|int $id, Shape $shape): void
    {
        $this->remove($id);
        $keys = $this->keys($shape->bounds());
        foreach ($keys as $key) {
            $this->cells[$key][$id] = true;
        }
        $this->where[$id] = $keys;
    }

    public function remove(string|int $id): void
    {
        foreach ($this->where[$id] ?? [] as $key) {
            unset($this->cells[$key][$id]);
            if (!$this->cells[$key]) {
                unset($this->cells[$key]);
            }
        }
        unset($this->where[$id]);
    }

    /** @param array{0: float, 1: float, 2: float, 3: float} $bounds @return list<string|int> the ids in the cells a box touches (candidates, to test for real) */
    public function query(array $bounds): array
    {
        $found = [];
        foreach ($this->keys($bounds) as $key) {
            foreach ($this->cells[$key] ?? [] as $id => $_) {
                $found[$id] = true;
            }
        }
        return array_keys($found);
    }

    /** @return list<array{0: string|int, 1: string|int}> every pair that shares a cell, once */
    public function pairs(): array
    {
        $seen = [];
        $out = [];
        foreach ($this->cells as $ids) {
            $list = array_keys($ids);
            for ($i = 0, $n = count($list); $i < $n; $i++) {
                for ($j = $i + 1; $j < $n; $j++) {
                    [$a, $b] = [$list[$i], $list[$j]];
                    $key = $a . "\0" . $b;
                    if (!isset($seen[$key]) && !isset($seen[$b . "\0" . $a])) {
                        $seen[$key] = true;
                        $out[] = [$a, $b];
                    }
                }
            }
        }
        return $out;
    }

    public function clear(): void
    {
        $this->cells = [];
        $this->where = [];
    }

    /** @param array{0: float, 1: float, 2: float, 3: float} $b @return list<string> */
    private function keys(array $b): array
    {
        $keys = [];
        for ($cy = (int)floor($b[1] / $this->cell); $cy <= (int)floor($b[3] / $this->cell); $cy++) {
            for ($cx = (int)floor($b[0] / $this->cell); $cx <= (int)floor($b[2] / $this->cell); $cx++) {
                $keys[] = "$cx,$cy";
            }
        }
        return $keys;
    }
}
