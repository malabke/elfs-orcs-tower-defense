# Physics

`jumphp/physics` (`JumPHP\Physics2d\World`): a small 2D physics engine for videos and toys: balls inside rings and arcs
(`spin`, `gap`, `grow`, `growOnHit`, `openOnHit`), pegs, walls, balls on balls. Fixed step (`hz`, 240 by default), seeded,
32-bit safe (the same in the browser).

```php
use JumPHP\Physics2d\{Ball, Peg, Wall, World};

public function __construct(private World $world) {}            // one per scene

public function enter(array $params = []): void
{
    $this->world->reset(7);                                       // empty, seeded
    $this->world->addWall(new Wall(10, 170, 310, 170, '#5a5f88'));
    $this->world->addPeg(new Peg(160, 100, 8, '#ffcc00'));
    $this->world->addBall(new Ball(150, 20, 30, 0, 6, '#ff4fa3'));
}

public function update(float $dt): void
{
    $this->world->update($dt);                                    // sub-steps of 1 / hz
    foreach ($this->world->hits() as $hit) { /* 'ring', 'peg', 'wall', 'ball', 'escape' */ }
}
```

- `physics: {gravity: 600, restitution: 1.0, hz: 240, seed: 1}` in project.yaml.
- `World::on(callable)`: events of the hits as they happen; `hits()`: those of the last update.
- `Ball`, `Ring`, `Peg`, `Wall` are plain objects with public fields: draw them as you like.
