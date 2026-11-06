# Collision

`jumphp/collision`: collisions for JumPHP games. Circles, boxes and convex polygons; the overlap test that also says **how to push the
shapes apart**; rays; a broad phase so that many shapes stay cheap. Pure PHP on floats: the same on the desktop and in the browser.

## Test two shapes

```php
use JumPHP\Collision\{Box, Circle, Collision, Polygon};

$hit = Collision::test($player, $wall);          // null when they do not touch
if ($hit) {
    [$dx, $dy] = $hit->pushOutOfFirst();         // the minimum translation that gets $player out of $wall
    $player->translate($dx, $dy);
}
```

`Hit` has `nx`, `ny` (the unit normal pointing from the first shape to the second) and `depth`; `pushOutOfFirst()` / `pushOutOfSecond()`
give the move, `flipped()` the same hit seen from the other shape. Touching is not overlapping.

| shape | |
|---|---|
| `new Circle($x, $y, $radius)` | `$x`, `$y` is the centre |
| `new Box($x, $y, $width, $height)` | `$x`, `$y` is the top-left corner (axis-aligned) |
| `new Polygon([[x, y], ...])` | convex, 3 points or more; `Polygon::regular($x, $y, $r, $sides, $angle)`, `->rotated($degrees)` |

All have `bounds()`, `contains($x, $y)`, `center()`, `moveTo($x, $y)` (the centre goes there), `translate($dx, $dy)`.
A concave shape is several convex ones.

## Rays

```php
$hit = Collision::raycast($x, $y, $dx, $dy, ['wall' => $wall, 'rock' => $rock], 200);   // at most 200 away
$hit?->id;  $hit?->distance;  $hit?->x;  $hit?->y;  $hit?->nx;  $hit?->ny;               // the normal looks back at the ray
```

## Many shapes: the world

```php
public function __construct(private CollisionWorld $world) {}      // a service

$this->world->reset(48);                         // in enter(); 48: the size of a broad phase cell (about your commonest shape)
$this->world->add('wall', new Box(150, 20, 16, 140));
$player = $this->world->add('player', new Circle(40, 140, 10));

$player->translate($dx, $dy);  $this->world->update('player');      // a shape that moved: say so
foreach ($this->world->collisions() as [$a, $b, $hit]) { /* ids of the pair, and the Hit from $a to $b */ }
$this->world->at($x, $y);  $this->world->overlapping($shape);  $this->world->raycast($x, $y, $dx, $dy, 200, 'player');   // 'player': a shape the ray does not see
```

`SpatialHash` (insert, remove, query, pairs) is the broad phase on its own.

## Sample

`CollisionSample`: a circle pushed out of a wall, a hexagon and a triangle, and a ray from it to the cursor. It comes into your game with
`bin/jumphp extension:add <game> jumphp/collision`.
