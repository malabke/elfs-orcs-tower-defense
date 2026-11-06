<?php

namespace App\Extensions\Dialogue;

use JumPHP\Dialogue\QuestLog;
use JumPHP\Renderer\Renderer;
use JumPHP\Scene\AbstractScene;

/**
 * Sample of jumphp/dialogue: a quest log. Enter (confirm) picks up a gem, Up talks to the elder, Back starts over; when every objective is done the
 * quest is completed. The QuestLog keeps its state as plain values (toArray / restore): that is what a save file holds.
 * JUMPHP_SCENE='App\Extensions\Dialogue\QuestSample' bin/jumphp run <game>
 */
final class QuestSample extends AbstractScene
{
    public const LABEL = 'A quest log: objectives with counters, completed when all are done';

    private string $message = '';

    public function __construct(private QuestLog $quests)
    {
    }

    public function enter(array $params = []): void
    {
        $this->quests->restore(['status' => [], 'have' => []]);
        $this->quests->define('gems', ['title' => 'The lost gems', 'text' => 'The elder lost three gems in the cave.', 'objectives' => [
            ['id' => 'gems', 'text' => 'Collect the gems', 'count' => 3],
            ['id' => 'elder', 'text' => 'Tell the elder'],
        ]]);
        $this->quests->start('gems');
        $this->message = 'ENTER: PICK UP A GEM   UP: TALK TO THE ELDER   BACK: START OVER';
    }

    public function update(float $dt): void
    {
        if ($this->actions()->isPressed('back')) {
            $this->enter();
            return;
        }
        if ($this->quests->status('gems') !== 'active') {
            return;
        }
        if ($this->actions()->isPressed('confirm')) {
            $this->quests->progress('gems', 'gems');
        }
        if ($this->actions()->isPressed('up')) {
            $this->quests->progress('gems', 'elder');
        }
        $q = $this->quests->describe('gems');
        if (!array_filter($q['objectives'], fn ($o) => !$o['done'])) {
            $this->quests->complete('gems');
            $this->message = 'QUEST COMPLETED';
        }
    }

    public function render(Renderer $r): void
    {
        $r->clear([22, 20, 34]);
        $q = $this->quests->describe('gems');
        $font = $this->font();
        $font->draw($r, strtoupper($q['title']) . '  [' . strtoupper($q['status']) . ']', 12, 14, 2, '#ffd040');
        $font->draw($r, strtoupper($q['text']), 12, 36, 1, [200, 200, 220]);
        $y = 60;
        foreach ($q['objectives'] as $o) {
            $font->draw($r, ($o['done'] ? '[X] ' : '[ ] ') . strtoupper($o['text']) . ($o['count'] > 1 ? '  ' . $o['have'] . '/' . $o['count'] : ''), 20, $y, 1, $o['done'] ? '#40d060' : '#ffffff');
            $y += 14;
        }
        $font->draw($r, $this->message, 12, $this->height() - 14, 1, [160, 165, 200]);
    }
}
