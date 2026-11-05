<?php

namespace App\Extensions\Dialogue;

use JumPHP\Dialogue\Dialogue;
use JumPHP\Dialogue\DialogueBox;
use JumPHP\Dialogue\DialogueRunner;
use JumPHP\Renderer\Renderer;
use JumPHP\Scene\AbstractScene;

/**
 * Sample of jumphp/dialogue: Enter talks to a shopkeeper (a dialogue written as a PHP array: the same shape as a .dialogue.json file); up / down
 * and Enter choose, a counter of gold changes with what you buy. JUMPHP_SCENE='App\Extensions\Dialogue\DialogueSample' bin/jumphp run <game>
 */
final class DialogueSample extends AbstractScene
{
    public const LABEL = 'Talk to a shopkeeper: choices, a gold counter, text written letter by letter';

    public function __construct(private DialogueRunner $runner, private DialogueBox $box)
    {
    }

    public function enter(array $params = []): void
    {
        $this->runner->end();
        $this->runner->clearListeners();
        $this->runner->variables()->reset();
        $this->runner->variables()->apply(['gold' => 20, 'potions' => 0]);
        $this->runner->add(Dialogue::fromArray('shop', ['start' => 'hello', 'nodes' => [
            'hello' => ['speaker' => 'Shopkeeper', 'text' => ['Welcome!', 'You have {gold} gold.'], 'next' => 'menu'],
            'menu' => ['speaker' => 'Shopkeeper', 'text' => 'What will it be?', 'choices' => [
                ['text' => 'A potion (8 gold)', 'if' => 'gold >= 8', 'set' => ['gold' => '-8', 'potions' => '+1'], 'next' => 'bought'],
                ['text' => 'I cannot afford anything', 'if' => 'gold < 8', 'next' => 'poor'],
                ['text' => 'Goodbye', 'next' => 'bye'],
            ]],
            'bought' => ['speaker' => 'Shopkeeper', 'text' => 'A potion! {gold} gold left, {potions} potion(s).', 'next' => 'menu'],
            'poor' => ['speaker' => 'Shopkeeper', 'text' => 'Come back with more gold.', 'end' => true],
            'bye' => ['speaker' => 'Shopkeeper', 'text' => 'Safe travels.', 'end' => true],
        ]]));
    }

    public function update(float $dt): void
    {
        $talking = $this->runner->isActive();
        $this->box->update($dt, $talking ? $this->actions() : null);
        if (!$talking && $this->actions()->isPressed('confirm')) {
            $this->runner->start('shop');
        }
    }

    public function render(Renderer $r): void
    {
        $r->clear([30, 40, 70]);
        $this->font()->draw($r, $this->runner->isActive() ? '' : 'PRESS ENTER TO TALK', $this->width() / 2, 40, 1, [220, 225, 255], 'center');
        $this->box->render($r, $this->font());
    }
}
