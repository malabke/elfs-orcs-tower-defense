<?php

namespace App\Scene;

use JumPHP\Dialogue\DialogueBox;
use JumPHP\Dialogue\DialogueRunner;
use JumPHP\Dialogue\QuestLog;
use JumPHP\Renderer\Renderer;
use JumPHP\Scene\AbstractScene;

/**
 * The extension at work: walk (arrows) to the elder and press Enter to talk (assets/dialogue/village.dialogue.json); take his quest, find the
 * key and the three coins, and talk to him again. The demo (smoke test, the playground's title screen) plays it by itself.
 */
final class DemoScene extends AbstractScene
{
    public float $px = 40;
    public float $py = 140;
    /** @var array<string, array{0: float, 1: float}> */
    private array $items = [];
    public int $rewards = 0;
    private float $time = 0;
    private bool $demo = false;

    public function __construct(private DialogueRunner $runner, private DialogueBox $box, private QuestLog $quests)
    {
    }

    public function enter(array $params = []): void
    {
        $this->demo = (bool)($params['demo'] ?? false);
        $this->time = 0;
        $this->rewards = 0;
        [$this->px, $this->py] = [150.0, 110.0];
        $this->runner->end();
        $this->runner->clearListeners();            // the runner lives as long as the game: a scene that listens starts from none
        $this->runner->variables()->reset();
        $this->runner->load($this->engine->extensions()->load('assets/dialogue/village.dialogue.json'));
        $this->runner->onAction(function (string $action): void {
            if ($action === 'give:reward') {
                $this->rewards++;
            }
        });
        $this->items = ['key' => [60.0, 150.0], 'coin1' => [250.0, 40.0], 'coin2' => [270.0, 150.0], 'coin3' => [30.0, 30.0]];
    }

    public function nearElder(): bool
    {
        return hypot($this->px - 160, $this->py - 90) < 30;
    }

    public function talk(): void
    {
        if (!$this->runner->isActive() && $this->nearElder()) {
            $this->runner->start('elder');
        }
    }

    public function update(float $dt): void
    {
        $this->time += $dt;
        $talking = $this->runner->isActive();       // the Enter that ends a conversation must not start the next one in the same frame
        $this->box->update($dt, $talking ? $this->actions() : null);
        if ($this->runner->isActive()) {
            if ($this->demo && $this->time > 1 && fmod($this->time, 0.8) < $dt) {
                $this->box->press('confirm');            // the demo reads on by itself
            }
            return;
        }
        if ($this->demo) {
            $this->px = 160 + cos($this->time * 0.8) * 50;
            $this->py = 90 + sin($this->time * 0.8) * 50;
            if (!$talking && $this->time > 0.5 && $this->nearElder()) {
                $this->talk();
            }
        } else {
            $this->px = max(8, min($this->width() - 8, $this->px + $this->actions()->axis('left', 'right') * 80 * $dt));
            $this->py = max(8, min($this->height() - 8, $this->py + $this->actions()->axis('up', 'down') * 80 * $dt));
            if (!$talking && $this->actions()->isPressed('confirm')) {
                $this->talk();
            }
        }
        foreach ($this->items as $name => [$x, $y]) {
            if (hypot($this->px - $x, $this->py - $y) < 10) {
                unset($this->items[$name]);
                $this->quests->progress('rescue', $name === 'key' ? 'find-key' : 'coins');
            }
        }
    }

    public function render(Renderer $r): void
    {
        $r->clear([30, 70, 40]);
        $r->fillRect(152, 82, 16, 16, '#e0b060');                                   // the elder
        foreach ($this->items as $name => [$x, $y]) {
            $r->fillCircle((int)$x, (int)$y, $name === 'key' ? 5 : 3, $name === 'key' ? '#ffffff' : '#ffd23f');
        }
        $r->fillRect((int)$this->px - 5, (int)$this->py - 5, 10, 10, '#5fd0ff');    // the player
        if ($this->nearElder() && !$this->runner->isActive()) {
            $this->font()->draw($r, 'ENTER: TALK', 160, 70, 1, [255, 255, 255], 'center', [0, 0, 0]);
        }
        $y = 4;
        foreach ($this->quests->active() as $id) {
            $q = $this->quests->describe($id);
            $this->font()->draw($r, strtoupper($q['title']), 4, $y, 1, [255, 214, 90], 'left', [0, 0, 0]);
            foreach ($q['objectives'] as $o) {
                $y += 9;
                $this->font()->draw($r, ($o['done'] ? '[X] ' : '[ ] ') . strtoupper($o['text']) . ($o['count'] > 1 ? " {$o['have']}/{$o['count']}" : ''), 8, $y, 1, [255, 255, 255], 'left', [0, 0, 0]);
            }
            $y += 12;
        }
        $this->box->render($r, $this->font());
    }
}
