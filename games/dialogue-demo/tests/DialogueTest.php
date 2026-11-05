<?php

namespace Tests;

use JumPHP\Dialogue\Condition;
use JumPHP\Dialogue\Dialogue;
use JumPHP\Dialogue\DialogueBox;
use JumPHP\Dialogue\DialogueException;
use JumPHP\Dialogue\DialogueLoader;
use JumPHP\Dialogue\DialogueRunner;
use JumPHP\Dialogue\QuestLog;
use JumPHP\Dialogue\Variables;
use PHPUnit\Framework\TestCase;

/** The extension's logic, with no window. */
final class DialogueTest extends TestCase
{
    private Variables $vars;
    private QuestLog $quests;
    private DialogueRunner $runner;

    protected function setUp(): void
    {
        $this->vars = new Variables();
        $this->quests = new QuestLog($this->vars);
        $this->runner = new DialogueRunner($this->vars, $this->quests);
        $file = (new DialogueLoader())->load(__DIR__ . '/../assets/dialogue/village.dialogue.json');
        $this->runner->load($file);
    }

    public function testConditionsReadVariablesAndNeverRunCode(): void
    {
        $v = new Variables();
        $v->apply(['gold' => 7, 'name' => 'Ann', 'helped' => true]);
        foreach ([['gold >= 5', true], ['gold > 7', false], ['!helped', false], ["name == 'Ann'", true], ['gold < 10 && helped', true], ['missing || gold == 7', true],
            ['(gold == 1 || gold == 7) && !(helped == false)', true], ['missing', false], ['missing == 0', true], ['missing < 3', true], ['', true]] as [$expr, $expected]) {
            self::assertSame($expected, Condition::test($expr, $v), $expr);
        }
        $this->expectException(DialogueException::class);
        Condition::check('system("rm -rf /")');
    }

    public function testABrokenConditionSaysWhat(): void
    {
        $this->expectExceptionMessage("a ')' is missing");
        Condition::check('(a == 1');
    }

    public function testCountersAddAndTheSaveKeepsTypes(): void
    {
        $v = new Variables();
        $v->apply(['gold' => 5]);
        $v->apply(['gold' => '+3', 'tag' => '+x']);
        self::assertSame(8, $v->get('gold'));
        self::assertSame('+x', $v->get('tag'));
        $copy = new Variables();
        $copy->restore($v->toArray());
        self::assertSame($v->toArray(), $copy->toArray());
    }

    public function testAFileIsCheckedWhenItIsMadeAndSaysWhere(): void
    {
        foreach ([
            [['nodes' => []], 'no "nodes"'],
            [['start' => 'x', 'nodes' => ['a' => ['text' => 'hi']]], "start node 'x'"],
            [['nodes' => ['a' => ['text' => 'hi', 'next' => 'zzz']]], "node 'a'"],
            [['nodes' => ['a' => ['choices' => [['text' => 'ok', 'if' => 'a &&']]]]], 'choice 1'],
            [['nodes' => ['a' => []]], 'says nothing'],
            [['nodes' => ['a' => ['branch' => [['next' => 'nope']]]]], 'branch 1'],
        ] as [$data, $message]) {
            try {
                Dialogue::fromArray('t', $data);
                self::fail("accepted: $message");
            } catch (DialogueException $e) {
                self::assertStringContainsString($message, $e->getMessage());
                self::assertStringContainsString("Dialogue 't'", $e->getMessage());
            }
        }
    }

    public function testTheElderDialogueRunsFromHelloToAnAcceptedQuest(): void
    {
        $this->runner->start('elder');
        self::assertSame('hello', $this->runner->nodeName(), 'the router chose the first meeting');
        $line = $this->runner->line();
        self::assertSame(['Elder', 'Welcome, traveller.', []], [$line['speaker'], $line['text'], $line['choices']]);
        $this->runner->advance();                                    // the second part of the text
        self::assertSame('I lost my key in the grass.', $this->runner->line()['text']);
        $this->runner->advance();                                    // next: ask
        $line = $this->runner->line();
        self::assertSame(['Of course', 'I only have 5 gold. What do you pay?', 'Not now'], array_column($line['choices'], 'text'));
        $this->runner->advance();
        self::assertSame('ask', $this->runner->nodeName(), 'nothing moves while a choice is to be made');
        $this->runner->choose(0);
        self::assertSame(QuestLog::ACTIVE, $this->quests->status('rescue'));
        self::assertSame('Thank you! Look near the stones.', $this->runner->line()['text']);
        $ended = [];
        $this->runner->onEnd(function (string $id) use (&$ended): void { $ended[] = $id; });
        $this->runner->advance();
        self::assertFalse($this->runner->isActive());
        self::assertSame(['elder'], $ended);
    }

    public function testAChoiceThatIsAskedOnceDisappearsAndTheNodeSetsItsVariable(): void
    {
        $this->runner->start('elder');
        $this->runner->advance();
        $this->runner->advance();
        $this->runner->choose(1);                                    // "What do you pay?": the "pay" node sets asked_pay, then goes back to ask
        self::assertSame('pay', $this->runner->nodeName());
        self::assertTrue($this->vars->get('asked_pay'));
        $this->runner->advance();
        self::assertSame('ask', $this->runner->nodeName());
        self::assertSame(['Of course', 'Not now'], array_column($this->runner->line()['choices'], 'text'));
    }

    public function testTheRouterSendsTheStoryByTheQuest(): void
    {
        $this->runner->start('elder');
        $this->runner->end();
        $this->runner->start('elder');
        self::assertSame('again', $this->runner->nodeName(), 'seen.elder.hello: the second meeting');
        $this->quests->start('rescue');
        $this->runner->start('elder');
        self::assertSame('waiting', $this->runner->nodeName());
        $actions = [];
        $this->runner->onAction(function (string $a) use (&$actions): void { $actions[] = $a; });
        $this->quests->progress('rescue', 'find-key');
        $this->quests->progress('rescue', 'coins', 3);
        self::assertSame(QuestLog::DONE, $this->quests->status('rescue'));
        $this->runner->start('elder');
        self::assertSame('thanks', $this->runner->nodeName());
        self::assertSame(['give:reward'], $actions, 'what the engine does not know is told to the game');
        self::assertSame(15, $this->vars->get('gold'), '"+10" added');
    }

    public function testQuestsCountEmitAndSave(): void
    {
        $events = [];
        $this->quests->onChange(function (string $e, string $q) use (&$events): void { $events[] = "$e:$q"; });
        $this->quests->progress('rescue', 'coins');                  // not started: nothing
        self::assertSame([], $events);
        $this->quests->start('rescue');
        $this->quests->progress('rescue', 'coins', 2);
        $this->quests->progress('rescue', 'coins', 5);               // never past the count
        $d = $this->quests->describe('rescue');
        self::assertSame([3, false], [$d['objectives'][1]['have'], $d['objectives'][0]['done']]);
        self::assertSame(['started:rescue', 'progress:rescue', 'progress:rescue'], $events);
        $saved = $this->quests->toArray();
        $copy = new QuestLog($this->vars);
        $copy->load((array)json_decode((string)file_get_contents(__DIR__ . '/../assets/dialogue/village.dialogue.json'), true)['quests']);
        $copy->restore($saved);
        self::assertSame(QuestLog::ACTIVE, $copy->status('rescue'));
        self::assertSame(3, $copy->describe('rescue')['objectives'][1]['have']);
        self::assertSame('active', $this->vars->get('quest.rescue'), 'a dialogue can read the quest');
        $this->expectExceptionMessage("no objective 'nope'");
        $this->quests->progress('rescue', 'nope');
    }

    public function testTheConversationItselfCanBeSavedAndRestored(): void
    {
        $this->runner->start('elder');
        $this->runner->advance();
        $state = $this->runner->toArray();
        $other = new DialogueRunner($this->vars, $this->quests);
        $other->load((new DialogueLoader())->load(__DIR__ . '/../assets/dialogue/village.dialogue.json'));
        $other->restore($state);
        self::assertSame('I lost my key in the grass.', $other->line()['text']);
        $other->restore(['dialogue' => 'unknown']);
        self::assertFalse($other->isActive());
    }

    public function testTheBoxWritesLetterByLetterThenGoesOn(): void
    {
        $box = new DialogueBox($this->runner);
        $this->runner->start('elder');
        $box->update(0.0);
        self::assertSame('', $box->visibleText());
        $box->update(0.1);                                           // 45 letters a second: 4 letters
        self::assertSame('Welc', $box->visibleText());
        self::assertFalse($box->isRevealed());
        $box->press('confirm');                                      // shows it all
        self::assertSame('Welcome, traveller.', $box->visibleText());
        $box->press('confirm');                                      // goes on: the second part
        $box->update(0.0);
        self::assertSame('', $box->visibleText(), 'the next line starts again');
        $box->press('confirm');
        $box->press('confirm');                                      // third part: ask, with its choices
        $box->update(0.0);
        $box->press('confirm');
        self::assertSame('ask', $this->runner->nodeName());
        $box->update(5.0);
        self::assertTrue($box->isRevealed());
        $box->press('down');
        $box->press('down');
        $box->press('down');
        self::assertSame(0, $box->cursor(), 'the cursor goes round');
        $box->press('up');
        self::assertSame(2, $box->cursor());
        $box->press('confirm');                                      // "Not now"
        self::assertSame('no', $this->runner->nodeName());
    }
}
