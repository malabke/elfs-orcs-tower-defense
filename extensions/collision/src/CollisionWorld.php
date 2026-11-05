<?php

namespace JumPHP\Collision;

/**
 * Shapes with ids, and what touches what: add() them, move() the ones that move, then collisions() gives every overlapping pair (broad
 * phase by SpatialHash, then the real test). A service: a scene asks for it in its constructor; reset() in enter().
 */
final class CollisionWorld
{
    /** @var array<string|int, Shape> */
    private array $shapes = [];
    private SpatialHash $hash;

    public function __construct()
    {
        $this->hash = new SpatialHash(64.0);
    }

    /** Forgets everything; $cell: the size of the broad phase's cells (about the commonest shape). */
    public function reset(float $cell = 64.0): void
    {
        $this->shapes = [];
        $this->hash = new SpatialHash($cell);
    }

    public function add(string|int $id, Shape $shape): Shape
    {
        $this->shapes[$id] = $shape;
        $this->hash->insert($id, $shape);
        return $shape;
    }

    public function remove(string|int $id): void
    {
        unset($this->shapes[$id]);
        $this->hash->remove($id);
    }

    /** A shape was moved by its own fields (x, y...): tell the world. */
    public function update(string|int $id): void
    {
        if (isset($this->shapes[$id])) {
            $this->hash->insert($id, $this->shapes[$id]);
        }
    }

    public function get(string|int $id): ?Shape
    {
        return $this->shapes[$id] ?? null;
    }

    /** @return array<string|int, Shape> */
    public function all(): array
    {
        return $this->shapes;
    }

    /** @return list<array{0: string|int, 1: string|int, 2: Hit}> the pairs that overlap (the Hit: from the first to the second) */
    public function collisions(): array
    {
        $out = [];
        foreach ($this->hash->pairs() as [$a, $b]) {
            $hit = Collision::test($this->shapes[$a], $this->shapes[$b]);
            if ($hit !== null) {
                $out[] = [$a, $b, $hit];
            }
        }
        return $out;
    }

    /** @return list<string|int> the ids of the shapes that hold a point */
    public function at(float $x, float $y): array
    {
        $found = [];
        foreach ($this->hash->query([$x, $y, $x, $y]) as $id) {
            if ($this->shapes[$id]->contains($x, $y)) {
                $found[] = $id;
            }
        }
        return $found;
    }

    /** @return list<string|int> the ids of the shapes that overlap a shape (not itself) */
    public function overlapping(Shape $shape, string|int|null $except = null): array
    {
        $found = [];
        foreach ($this->hash->query($shape->bounds()) as $id) {
            if ($id !== $except && Collision::overlaps($shape, $this->shapes[$id])) {
                $found[] = $id;
            }
        }
        return $found;
    }

    /** The first shape a ray meets (RayHit::id is its id); $except: a shape the ray does not see, usually the one it starts from. */
    public function raycast(float $ox, float $oy, float $dx, float $dy, float $max = INF, string|int|null $except = null): ?RayHit
    {
        $shapes = $except === null ? $this->shapes : array_filter($this->shapes, fn ($id) => $id !== $except, ARRAY_FILTER_USE_KEY);
        return Collision::raycast($ox, $oy, $dx, $dy, $shapes, $max);
    }
}
