<?php

namespace App\Scene;

use JumPHP\Particles\Emitter;
use JumPHP\Particles\ParticleSystem;
use JumPHP\Physics2d\Ball;
use JumPHP\Physics2d\Peg;
use JumPHP\Physics2d\Ring;
use JumPHP\Physics2d\Wall;
use JumPHP\Physics2d\World;
use JumPHP\Renderer\Renderer;
use JumPHP\Scene\AbstractScene;
use JumPHP\Shorts\Draw;
use JumPHP\Shorts\Melody;
use JumPHP\Shorts\Recorder;

/**
 * One "satisfying" video, vertical (270 x 480): a question at the top, the physics in the middle, a timer and a caption
 * below; each bounce plays the next note of the melody (Recorder: heard, and kept for bin/jumphp video:make's mix).
 * Params: template (growing | rings | race), seed (same seed, same video), title (instead of the template's question).
 */
final class ShortScene extends AbstractScene
{
    public const TEMPLATES = ['growing', 'rings', 'race'];
    private const CX = 135;
    private const CY = 260;
    /** a public-domain tune (Beethoven's Ode to Joy) */
    private const MELODY = 'E4 E4 F4 G4 G4 F4 E4 D4 C4 C4 D4 E4 E4 D4 D4 E4 E4 F4 G4 G4 F4 E4 D4 C4 C4 D4 E4 D4 C4 C4';

    private string $template = 'rings';
    private string $title = '';
    private string $caption = '';
    private string $banner = '';
    private float $time = 0;
    private float $lastNote = -1;
    private float $roundEnd = -1;
    private int $bounces = 0;
    private int $seed = 1;
    private int $round = 0;
    private Melody $melody;
    private Emitter $sparks;
    /** @var array<string, int> race: wins per colour */
    private array $wins = [];

    public function __construct(private World $world, private ParticleSystem $particles, private Recorder $recorder)
    {
        $this->melody = Melody::fromString(self::MELODY);
        $this->sparks = new Emitter();
    }

    public function enter(array $params = []): void
    {
        $this->template = in_array($params['template'] ?? 'rings', self::TEMPLATES, true) ? (string)$params['template'] : 'rings';
        $this->seed = (int)($params['seed'] ?? 1);
        $this->time = 0;
        $this->lastNote = -1;
        $this->bounces = 0;
        $this->round = 0;
        $this->wins = [];
        $this->banner = '';
        $this->roundEnd = -1;
        $this->melody = isset($params['melody']) ? Melody::fromString((string)$params['melody']) : Melody::fromString(self::MELODY);
        $this->recorder->reset();
        $this->particles->reset($this->seed);
        $this->sparks = $this->particles->add(new Emitter([
            'rate' => 0, 'life' => [0.25, 0.6], 'speed' => [40, 140], 'angle' => 0, 'spread' => 360, 'gravity' => 120,
            'drag' => 1.5, 'colors' => ['#ffffff', '#ffffff00'], 'size' => [3, 1], 'shape' => 'circle',
        ]));
        $this->build();
        $this->title = (string)($params['title'] ?? match ($this->template) {
            'growing' => 'HOW BIG CAN IT GET?',
            'race' => 'WHICH COLOUR WINS?',
            default => 'WILL IT ESCAPE?',
        });
    }

    private function build(): void
    {
        $this->world->reset($this->seed + $this->round * 7919);
        $this->world->ballsCollide = $this->template === 'race';
        $this->world->restitution = $this->template === 'race' ? 0.55 : 1.0;     // falling through pegs: some loss
        $rand = $this->world->random;
        if ($this->template === 'growing') {
            $this->world->addRing(new Ring(self::CX, self::CY, 118, thickness: 4, color: '#ffffff'));
            $ball = $this->world->addBall(new Ball(self::CX + $rand->between(-30, 30), self::CY - 40, $rand->between(-160, 160), 0, 6, '#ff4fa3', 28));
            $ball->data['grow'] = 0.9;
        } elseif ($this->template === 'race') {
            $this->world->addWall(new Wall(14, 70, 14, 470));
            $this->world->addWall(new Wall(256, 70, 256, 470));
            for ($row = 0; $row < 11; $row++) {
                $y = 130 + $row * 26;
                $offset = $row % 2 ? 0 : 13;
                for ($x = 27 + $offset; $x < 250; $x += 26) {
                    $this->world->addPeg(new Peg($x, $y, 3, '#8a8fb8'));
                }
            }
            $colors = ['RED' => '#ff4d5e', 'BLUE' => '#3d8bff', 'GREEN' => '#3ddc84', 'YELLOW' => '#ffd23d'];
            $i = 0;
            foreach ($colors as $name => $color) {
                $ball = $this->world->addBall(new Ball(75 + $i * 40 + $rand->between(-4, 4), 92, $rand->between(-20, 20), 0, 6, $color, 12));
                $ball->data['name'] = $name;
                $i++;
            }
        } else {
            $count = 7;
            for ($i = 0; $i < $count; $i++) {
                [$cr, $cg, $cb] = self::hue($i / $count);
                $this->world->addRing(new Ring(self::CX, self::CY, 30 + $i * 15, gap: 0.62, angle: $rand->between(0, 2 * M_PI),
                    spin: ($i % 2 ? -1 : 1) * (0.7 + 0.18 * $i), thickness: 3, color: sprintf('#%02x%02x%02x', $cr, $cg, $cb)));
            }
            $a = $rand->between(0, 2 * M_PI);
            $this->world->addBall(new Ball(self::CX, self::CY, cos($a) * 170, sin($a) * 170, 4, '#ffffff', 22));
        }
    }

    public function update(float $dt): void
    {
        if ($this->actions()->isPressed('back')) {
            $this->scenes()->switchTo(TitleScene::class);
            return;
        }
        if ($this->actions()->isPressed('restart')) {
            $this->round++;
            $this->build();
        }
        $this->time += $dt;
        $this->world->update($dt);
        foreach ($this->world->hits() as $hit) {
            $this->onHit($hit);
        }
        if ($this->template === 'race') {
            $this->race();
        } elseif ($this->roundEnd >= 0 && $this->time >= $this->roundEnd) {
            $this->roundEnd = -1;                       // the next round: the video goes on
            $this->banner = '';
            $this->round++;
            $this->build();
        }
        $this->particles->update($dt);
        $this->caption = match ($this->template) {
            'growing' => 'SIZE ' . (int)round($this->world->balls()[0]->radius * 2) . ' PX',
            'race' => $this->raceCaption(),
            default => 'RINGS LEFT ' . count(array_filter($this->world->rings(), fn (Ring $r) => $r->solid)),
        };
    }

    /** @param array<string, mixed> $hit */
    private function onHit(array $hit): void
    {
        /** @var Ball $ball */
        $ball = $hit['ball'];
        if ($hit['type'] === 'escape') {
            /** @var Ring $ring */
            $ring = $hit['body'];
            $ring->solid = false;
            $this->burst($ring->x, $ring->y, $ring->color, 0, $ring->radius);
            $this->recorder->note($this->time, $this->melody->next() * 2, 0.6, 'pluck', 0.4);
            if (!array_filter($this->world->rings(), fn (Ring $r) => $r->solid)) {
                $this->banner = 'IT ESCAPED!';
                $this->roundEnd = $this->time + 2.5;
            }
            return;
        }
        $this->bounces++;
        if ($this->template === 'growing' && $hit['type'] === 'ring') {
            /** @var Ring $ring */
            $ring = $hit['body'];
            $ball->radius = min($ring->radius - 2, $ball->radius + (float)($ball->data['grow'] ?? 0));
            if ($ball->radius >= $ring->radius - 3 && $this->banner === '') {
                $this->banner = 'IT FILLED THE CIRCLE!';
                $this->roundEnd = $this->time + 2.5;
            }
        }
        $this->burst((float)$hit['x'], (float)$hit['y'], $ball->color, min(18, 4 + (int)($hit['speed'] / 60)));
        if ($this->time - $this->lastNote >= 0.06) {            // a run of tiny bounces: one note, not a buzz
            $this->lastNote = $this->time;
            $this->recorder->note($this->time, $this->melody->next(), 0.4, 'pluck', 0.32);
        }
    }

    private function race(): void
    {
        if ($this->roundEnd >= 0) {
            if ($this->time >= $this->roundEnd) {
                $this->roundEnd = -1;
                $this->banner = '';
                $this->round++;
                $this->build();
            }
            return;
        }
        foreach ($this->world->balls() as $ball) {
            if ($ball->y > 450) {
                $name = (string)$ball->data['name'];
                $this->wins[$name] = ($this->wins[$name] ?? 0) + 1;
                $this->banner = "$name WINS!";
                $this->roundEnd = $this->time + 1.8;
                $this->burst($ball->x, 440, $ball->color, 40);
                $this->recorder->note($this->time, $this->melody->next() * 2, 0.8, 'pluck', 0.4);
                return;
            }
        }
    }

    private function raceCaption(): string
    {
        if (!$this->wins) {
            return 'ROUND ' . ($this->round + 1);
        }
        arsort($this->wins);
        return implode('  ', array_map(fn ($n, $w) => "$n $w", array_keys($this->wins), $this->wins));
    }

    private function burst(float $x, float $y, string $color, int $count, float $ringRadius = 0): void
    {
        $this->sparks->colors([$color, '#ffffff', substr($color, 0, 7) . '00']);
        if ($ringRadius > 0) {
            for ($i = 0; $i < 24; $i++) {
                $a = 2 * M_PI * $i / 24;
                $this->sparks->moveTo($x + cos($a) * $ringRadius, $y + sin($a) * $ringRadius);
                $this->particles->burst($this->sparks, 3);
            }
            return;
        }
        $this->sparks->moveTo($x, $y);
        $this->particles->burst($this->sparks, $count);
    }

    public function render(Renderer $r): void
    {
        $r->clear([10, 10, 18]);
        $font = $this->font();
        foreach ($this->world->rings() as $ring) {
            if ($ring->solid) {
                Draw::ring($r, $ring->x, $ring->y, $ring->radius, $ring->angle, $ring->gap, $ring->thickness, $ring->color);
            }
        }
        foreach ($this->world->walls() as $w) {
            $r->drawLine((int)$w->x1, (int)$w->y1, (int)$w->x2, (int)$w->y2, '#5a5f88');
        }
        foreach ($this->world->pegs() as $peg) {
            $r->fillCircle((int)$peg->x, (int)$peg->y, (int)$peg->radius, $peg->color);
        }
        if ($this->template === 'race') {
            $r->fillRect(14, 450, 242, 2, '#ffffff80');
        }
        foreach ($this->world->balls() as $ball) {
            Draw::trail($r, $ball->trail, $ball->radius * 0.8, Draw::rgb($ball->color));
            $r->fillCircle((int)round($ball->x), (int)round($ball->y), (int)max(1, round($ball->radius)), $ball->color);
        }
        $this->particles->render($r);
        $font->draw($r, $this->title, $this->width() / 2, 24, 2, [255, 255, 255], 'center', [0, 0, 0]);
        $font->draw($r, $this->caption, $this->width() / 2, 52, 1, [200, 205, 235], 'center');
        $t = (int)$this->time;
        $font->draw($r, sprintf('%d:%02d', intdiv($t, 60), $t % 60), $this->width() / 2, 458, 2, [255, 255, 255], 'center', [0, 0, 0]);
        if ($this->banner !== '') {
            $r->fillRect(0, 205, 270, 40, [0, 0, 0, 170]);
            $font->draw($r, $this->banner, $this->width() / 2, 218, 2, [255, 230, 90], 'center', [0, 0, 0]);
        }
    }

    /** @return array{0: int, 1: int, 2: int} a bright colour around the colour wheel (0..1) */
    private static function hue(float $h): array
    {
        $c = fn (float $v) => (int)(70 + 185 * max(0.0, min(1.0, $v)));
        return [$c(abs($h * 6 - 3) - 1), $c(2 - abs($h * 6 - 2)), $c(2 - abs($h * 6 - 4))];
    }
}
