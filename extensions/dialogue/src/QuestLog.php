<?php

namespace JumPHP\Dialogue;

/**
 * The quests: what each asks (objectives, with a count), where each stands (inactive, active, done, failed). A dialogue starts them
 * (`"action": "quest.start:rescue"`), the game moves them (`$quests->progress('rescue', 'find-key')`), a dialogue reads them
 * (`"if": "quest.rescue == 'done'"`: the log keeps the variable `quest.<id>` up to date). A quest with all its objectives done is done at
 * once. onChange() hears every change. toArray() / restore() are the save.
 */
final class QuestLog
{
    public const INACTIVE = 'inactive';
    public const ACTIVE = 'active';
    public const DONE = 'done';
    public const FAILED = 'failed';

    /** @var array<string, array{title: string, text: string, objectives: list<array{id: string, text: string, count: int}>}> */
    private array $quests = [];
    /** @var array<string, string> */
    private array $status = [];
    /** @var array<string, array<string, int>> quest => objective => how many */
    private array $have = [];
    /** @var list<callable(string, string): void> */
    private array $listeners = [];

    public function __construct(private Variables $vars)
    {
    }

    /** @param array<string, mixed> $quest title, text, objectives: [{id, text, count}] */
    public function define(string $id, array $quest): void
    {
        $objectives = [];
        foreach ((array)($quest['objectives'] ?? []) as $i => $o) {
            $o = is_array($o) ? $o : ['text' => (string)$o];
            $objectives[] = ['id' => (string)($o['id'] ?? ('o' . ($i + 1))), 'text' => (string)($o['text'] ?? ''), 'count' => max(1, (int)($o['count'] ?? 1))];
        }
        $this->quests[$id] = ['title' => (string)($quest['title'] ?? $id), 'text' => (string)($quest['text'] ?? ''), 'objectives' => $objectives];
        $this->status[$id] ??= self::INACTIVE;
        $this->vars->set("quest.$id", $this->status[$id]);
    }

    /** @param array<string, array<string, mixed>> $quests */
    public function load(array $quests): void
    {
        foreach ($quests as $id => $q) {
            $this->define((string)$id, (array)$q);
        }
    }

    /** @param callable(string $event, string $questId): void $listener 'started', 'progress', 'done', 'failed' */
    public function onChange(callable $listener): void
    {
        $this->listeners[] = $listener;
    }

    public function has(string $id): bool
    {
        return isset($this->quests[$id]);
    }

    public function status(string $id): string
    {
        return $this->status[$id] ?? self::INACTIVE;
    }

    public function start(string $id): void
    {
        $this->know($id);
        if ($this->status[$id] === self::INACTIVE) {
            $this->set($id, self::ACTIVE, 'started');
        }
    }

    /** One objective moves on by $by (never past its count); the quest is done when every objective is. */
    public function progress(string $id, string $objective, int $by = 1): void
    {
        $this->know($id);
        if ($this->status[$id] !== self::ACTIVE) {
            return;
        }
        foreach ($this->quests[$id]['objectives'] as $o) {
            if ($o['id'] === $objective) {
                $before = $this->have[$id][$objective] ?? 0;
                $now = max(0, min($o['count'], $before + $by));
                if ($now !== $before) {
                    $this->have[$id][$objective] = $now;
                    $this->emit('progress', $id);
                    $this->vars->set("quest.$id.$objective", $now);
                }
                if ($this->allDone($id)) {
                    $this->set($id, self::DONE, 'done');
                }
                return;
            }
        }
        throw new DialogueException("Quest '$id' has no objective '$objective'");
    }

    public function complete(string $id): void
    {
        $this->know($id);
        if ($this->status[$id] === self::ACTIVE) {
            $this->set($id, self::DONE, 'done');
        }
    }

    public function fail(string $id): void
    {
        $this->know($id);
        if ($this->status[$id] === self::ACTIVE) {
            $this->set($id, self::FAILED, 'failed');
        }
    }

    /** @return list<string> the ids of the quests being done now */
    public function active(): array
    {
        return array_keys(array_filter($this->status, fn ($s) => $s === self::ACTIVE));
    }

    /**
     * What to show of a quest: its title, its text, its status, and each objective with how far it is.
     *
     * @return array{id: string, title: string, text: string, status: string, objectives: list<array{id: string, text: string, count: int, have: int, done: bool}>}
     */
    public function describe(string $id): array
    {
        $this->know($id);
        $q = $this->quests[$id];
        return ['id' => $id, 'title' => $q['title'], 'text' => $q['text'], 'status' => $this->status[$id], 'objectives' => array_map(
            fn ($o) => $o + ['have' => $this->have[$id][$o['id']] ?? 0, 'done' => ($this->have[$id][$o['id']] ?? 0) >= $o['count']], $q['objectives'])];
    }

    /** @return array{status: array<string, string>, have: array<string, array<string, int>>} */
    public function toArray(): array
    {
        return ['status' => $this->status, 'have' => $this->have];
    }

    /** @param array<string, mixed> $state */
    public function restore(array $state): void
    {
        $this->status = array_map('strval', array_intersect_key((array)($state['status'] ?? []), $this->quests)) + array_fill_keys(array_keys($this->quests), self::INACTIVE);
        $this->have = [];
        foreach ((array)($state['have'] ?? []) as $q => $objectives) {
            foreach ((array)$objectives as $o => $n) {
                $this->have[(string)$q][(string)$o] = (int)$n;
            }
        }
        foreach ($this->status as $id => $s) {
            $this->vars->set("quest.$id", $s);
        }
    }

    private function know(string $id): void
    {
        if (!isset($this->quests[$id])) {
            throw new DialogueException("Unknown quest '$id' (define() it, or a \"quests\" part in the dialogue file)");
        }
    }

    private function allDone(string $id): bool
    {
        foreach ($this->quests[$id]['objectives'] as $o) {
            if (($this->have[$id][$o['id']] ?? 0) < $o['count']) {
                return false;
            }
        }
        return $this->quests[$id]['objectives'] !== [];
    }

    private function set(string $id, string $status, string $event): void
    {
        $this->status[$id] = $status;
        $this->vars->set("quest.$id", $status);
        $this->emit($event, $id);
    }

    private function emit(string $event, string $id): void
    {
        foreach ($this->listeners as $l) {
            $l($event, $id);
        }
    }
}
