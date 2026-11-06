# Effects

`jumphp/effects`: screen effects for JumPHP games, all drawn with translucent rectangles (the engine's `fillRect`): **transitions**
(fade, wipes from four sides, an iris closing on a point, blinds, a dissolve of squares), a **flash**, a **vignette** and **easing curves**.
Pure PHP, 32-bit safe: the same on the desktop and in the browser.

## Transitions: change scene under a cover

```php
use JumPHP\Effects\Transition;

public function __construct(private Transition $transition) {}       // one for the whole game

// somewhere in update(): cover the screen, change under it, reveal
$this->transition->cover('iris', 0.6, '#000000', fn () => $this->scenes()->switchTo(PlayScene::class, [], false));

// the new scene's enter():
$this->transition->reveal('iris', 0.6);

// each scene: update($dt) in update(), render($r) last in render()
$this->transition->update($dt);
$this->transition->render($r);
```

| type | what |
|---|---|
| `fade` | the colour comes in over the screen (16 shades) |
| `wipe-left`, `wipe-right`, `wipe-up`, `wipe-down` | a band from that side |
| `iris` | a circle closes on a point (`focus(0.5, 0.5)`: 0..1 of the screen) |
| `blinds` | eight bars close |
| `dissolve` | squares appear in a seeded order (`seed(7)`) |

- `cover()` ends covering and **stays** covering until `reveal()`; `reveal()` ends with nothing on the screen. The callback of each runs once, at its end.
- `isActive()` (something is on the screen), `isCovered()`, `isRunning()`, `progress()`, `amount()`.
- `shapes($w, $h)` is what is drawn: rectangles `[x, y, w, h, alpha]` (easy to test, or to draw yourself).

## Flash and vignette

```php
$this->flash->flash('#ff4040');      // the screen lights up and fades (project.yaml effects: {color, seconds})
Vignette::render($r, 0.5);           // darker edges, last in render()
```

## Easing

`Ease::inOutQuad($t)`, `outBack`, `outBounce`, `outElastic`, ... (`Ease::NAMES`), `Ease::get('outCubic')`, `Ease::lerp($from, $to, $t)`.

## Samples

`FlashSample` (a click flashes) and `TransitionSample` (Enter cycles the transitions) come into your game with `bin/jumphp extension:add`.
