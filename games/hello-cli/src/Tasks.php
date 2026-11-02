<?php

namespace App;

/** The tasks, kept in a JSON file: [{text, done}, ...]. */
final class Tasks
{
    /** @var list<array{text: string, done: bool}> */
    private array $items = [];

    public function __construct(private string $file)
    {
        $data = is_file($file) ? json_decode((string)file_get_contents($file), true) : null;
        foreach (is_array($data) ? $data : [] as $item) {
            if (is_array($item) && is_string($item['text'] ?? null)) {
                $this->items[] = ['text' => $item['text'], 'done' => (bool)($item['done'] ?? false)];
            }
        }
    }

    /** @return list<array{text: string, done: bool}> */
    public function all(): array
    {
        return $this->items;
    }

    public function add(string $text): int
    {
        $text = trim($text);
        if ($text === '') {
            throw new \InvalidArgumentException('A task needs some text.');
        }
        $this->items[] = ['text' => $text, 'done' => false];
        $this->save();
        return count($this->items);
    }

    /** Marks task $n (1 = the first) done, or not done again. */
    public function toggle(int $n): void
    {
        $this->at($n);
        $this->items[$n - 1]['done'] = !$this->items[$n - 1]['done'];
        $this->save();
    }

    public function remove(int $n): void
    {
        $this->at($n);
        array_splice($this->items, $n - 1, 1);
        $this->save();
    }

    /** @return array{text: string, done: bool} */
    public function at(int $n): array
    {
        return $this->items[$n - 1] ?? throw new \OutOfRangeException("There is no task $n.");
    }

    private function save(): void
    {
        @mkdir(dirname($this->file), 0777, true);
        file_put_contents($this->file, json_encode($this->items, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE) . "\n");
    }
}
