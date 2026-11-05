<?php

namespace JumPHP\Dialogue;

/**
 * Plays dialogues: start('intro'), then line() is what is said now (speaker, text with {variables} filled, the choices whose condition holds),
 * advance() goes on, choose($n) takes a choice. A node's "set" and "action" run when the node is entered, a choice's when it is taken.
 * Actions: `quest.start:<id>`, `quest.progress:<id>/<objective>[/<n>]`, `quest.complete:<id>`, `quest.fail:<id>`; any other is told to
 * onAction() (the game: give an item, open a door). Every node reached is remembered as the variable `seen.<dialogue>.<node>`.
 * A service for the whole game; DialogueBox draws and drives it.
 */
final class DialogueRunner
{
    /** @var array<string, Dialogue> */
    private array $dialogues = [];
    private ?Dialogue $current = null;
    private string $node = '';
    private int $part = 0;
    /** @var list<callable(string, DialogueRunner): void> */
    private array $actionListeners = [];
    /** @var list<callable(string): void> */
    private array $endListeners = [];

    public function __construct(private Variables $vars, private QuestLog $quests)
    {
    }

    public function variables(): Variables
    {
        return $this->vars;
    }

    public function quests(): QuestLog
    {
        return $this->quests;
    }

    /** What DialogueLoader read: the dialogues, the quests, and the variables' first values (a variable already set is kept). @param array<string, mixed> $file */
    public function load(array $file): void
    {
        foreach ((array)($file['dialogues'] ?? []) as $id => $d) {
            $this->dialogues[(string)$id] = $d instanceof Dialogue ? $d : Dialogue::fromArray((string)$id, (array)$d);
        }
        $this->quests->load((array)($file['quests'] ?? []));
        foreach ((array)($file['variables'] ?? []) as $name => $value) {
            if (!$this->vars->has((string)$name)) {
                $this->vars->apply([(string)$name => $value]);
            }
        }
    }

    public function add(Dialogue $dialogue): void
    {
        $this->dialogues[$dialogue->id] = $dialogue;
    }

    public function has(string $id): bool
    {
        return isset($this->dialogues[$id]);
    }

    /** @param callable(string $action, DialogueRunner $runner): void $listener */
    public function onAction(callable $listener): void
    {
        $this->actionListeners[] = $listener;
    }

    /** @param callable(string $dialogueId): void $listener */
    public function onEnd(callable $listener): void
    {
        $this->endListeners[] = $listener;
    }

    /** Forgets the onAction() and onEnd() listeners (the runner is shared by the whole game: a scene that listens does it in enter(), after this). */
    public function clearListeners(): void
    {
        $this->actionListeners = [];
        $this->endListeners = [];
    }

    public function start(string $id, ?string $node = null): void
    {
        $this->current = $this->dialogues[$id] ?? throw new DialogueException("Unknown dialogue '$id'");
        $this->enter($node ?? $this->current->start);
    }

    public function isActive(): bool
    {
        return $this->current !== null;
    }

    public function dialogueId(): ?string
    {
        return $this->current?->id;
    }

    public function nodeName(): string
    {
        return $this->node;
    }

    /**
     * What is said now: speaker, text, and the choices that can be taken (each: text, index = its place among the visible ones).
     *
     * @return array{speaker: string, text: string, choices: list<array{text: string, index: int}>}|null
     */
    public function line(): ?array
    {
        if ($this->current === null) {
            return null;
        }
        $node = $this->current->node($this->node);
        $texts = $this->texts($node);
        $isLast = $this->part >= count($texts) - 1;
        return ['speaker' => $this->fill((string)($node['speaker'] ?? '')), 'text' => $this->fill($texts[min($this->part, max(0, count($texts) - 1))] ?? ''),
            'choices' => $isLast ? array_map(fn ($c) => ['text' => $c['text'], 'index' => $c['index']], $this->visibleChoices($node)) : []];
    }

    /** Goes on: the next part of the node's text, else its "next", else the end. Nothing happens while there are choices to make. */
    public function advance(): void
    {
        if ($this->current === null) {
            return;
        }
        $node = $this->current->node($this->node);
        if ($this->part < count($this->texts($node)) - 1) {
            $this->part++;
            return;
        }
        if ($this->visibleChoices($node) !== []) {
            return;
        }
        if (!empty($node['end']) || !isset($node['next'])) {
            $this->end();
            return;
        }
        $this->enter((string)$node['next']);
    }

    /** Takes a choice (its index among the visible ones). */
    public function choose(int $index): void
    {
        if ($this->current === null) {
            return;
        }
        $node = $this->current->node($this->node);
        $visible = $this->visibleChoices($node);
        if (!isset($visible[$index]) || $this->part < count($this->texts($node)) - 1) {
            return;
        }
        $choice = $visible[$index]['choice'];
        $this->vars->apply((array)($choice['set'] ?? []));
        $this->run($choice['action'] ?? []);
        if (!$this->isActive()) {
            return;                                    // an action ended it
        }
        if (isset($choice['next']) && empty($choice['end'])) {
            $this->enter((string)$choice['next']);
        } else {
            $this->end();
        }
    }

    public function end(): void
    {
        $id = $this->current?->id;
        $this->current = null;
        $this->node = '';
        $this->part = 0;
        if ($id !== null) {
            foreach ($this->endListeners as $l) {
                $l($id);
            }
        }
    }

    /** @return array{dialogue: string|null, node: string, part: int} where it is, to save in the middle of a conversation */
    public function toArray(): array
    {
        return ['dialogue' => $this->current?->id, 'node' => $this->node, 'part' => $this->part];
    }

    /** @param array<string, mixed> $state */
    public function restore(array $state): void
    {
        $id = $state['dialogue'] ?? null;
        if (is_string($id) && isset($this->dialogues[$id]) && isset($this->dialogues[$id]->nodes[(string)($state['node'] ?? '')])) {
            $this->current = $this->dialogues[$id];
            $this->node = (string)$state['node'];
            $this->part = max(0, (int)($state['part'] ?? 0));
        } else {
            $this->current = null;
        }
    }

    private function enter(string $node, int $depth = 0): void
    {
        $this->node = $node;
        $this->part = 0;
        $n = $this->current?->node($node) ?? [];
        $this->vars->set("seen.{$this->current?->id}.$node", true);
        $this->vars->apply((array)($n['set'] ?? []));
        $this->run($n['action'] ?? []);
        // a router: the first branch whose condition holds (without "if": the default)
        foreach ((array)($n['branch'] ?? []) as $branch) {
            if ($this->current !== null && (!isset($branch['if']) || Condition::test((string)$branch['if'], $this->vars))) {
                if ($depth >= 20) {
                    throw new DialogueException("Dialogue '{$this->current->id}': the branches of '$node' lead round in a circle");
                }
                $this->enter((string)$branch['next'], $depth + 1);
                return;
            }
        }
    }

    /** @param mixed $actions a string or a list of them */
    private function run(mixed $actions): void
    {
        foreach (is_array($actions) ? $actions : [$actions] as $action) {
            $action = (string)$action;
            if ($action === '') {
                continue;
            }
            if (preg_match('#^quest\.(start|complete|fail):([\w.-]+)$#', $action, $m)) {
                $this->quests->{$m[1]}($m[2]);
            } elseif (preg_match('#^quest\.progress:([\w.-]+)/([\w.-]+)(?:/(-?\d+))?$#', $action, $m)) {
                $this->quests->progress($m[1], $m[2], isset($m[3]) ? (int)$m[3] : 1);
            } else {
                foreach ($this->actionListeners as $l) {
                    $l($action, $this);
                }
            }
        }
    }

    /** @param array<string, mixed> $node @return list<string> */
    private function texts(array $node): array
    {
        $t = $node['text'] ?? '';
        $list = array_values(array_map('strval', is_array($t) ? $t : [$t]));
        return $list === [] ? [''] : $list;
    }

    /** @param array<string, mixed> $node @return list<array{text: string, index: int, choice: array<string, mixed>}> */
    private function visibleChoices(array $node): array
    {
        $out = [];
        foreach ((array)($node['choices'] ?? []) as $choice) {
            if (isset($choice['if']) && !Condition::test((string)$choice['if'], $this->vars)) {
                continue;
            }
            $out[] = ['text' => $this->fill((string)$choice['text']), 'index' => count($out), 'choice' => $choice];
        }
        return $out;
    }

    /** "{gold}" becomes the variable's value. */
    private function fill(string $text): string
    {
        return (string)preg_replace_callback('/\{([A-Za-z_][A-Za-z0-9_.]*)\}/', fn ($m) => (string)($this->vars->get($m[1]) ?? ''), $text);
    }
}
