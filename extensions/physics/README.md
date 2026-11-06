# jumphp/physics

A small 2D physics engine for JumPHP (an engine extension, docs/extensions.md), made for "satisfying" videos and
simple games: balls bouncing inside rings and arcs (turning, opening, growing, shrinking, with gaps), on pegs and walls,
under gravity. Fixed step, seeded, the same run on the desktop and in the browser (32-bit PHP).

```php
public function __construct(private World $world) {}

$this->world->reset(42);
$ring = $this->world->addRing(new Ring(135, 240, 120, gap: 0.6, spin: 1.2));
$this->world->addBall(new Ball(135, 200, vx: 140, radius: 7, color: '#ff4fa3'));
$this->world->on(fn (array $hit) => $hit['type'] === 'escape' && $this->win());

// update(): $this->world->update($dt); foreach ($this->world->hits() as $hit) { ... }
```

project.yaml `physics: {gravity: 600, restitution: 1.0, hz: 240, seed: 1}`. Example: games/shorts-demo.
