<?php

namespace JumPHP\Dialogue;

/**
 * The story's variables: flags (true / false), counters, names. A dialogue sets them (`"set": {"helped": true, "gold": "+5"}`) and its
 * conditions read them (`"if": "gold >= 5 && !helped"`); the game reads and writes them too. A service shared by the whole game.
 * Names may hold dots (`quest.rescue`, `seen.intro.hello`). toArray() / restore() are the save.
 */
final class Variables
{
    /** @var array<string, bool|int|float|string|null> */
    private array $values = [];

    public function get(string $name, mixed $default = null): mixed
    {
        return $this->values[$name] ?? $default;
    }

    public function has(string $name): bool
    {
        return isset($this->values[$name]);
    }

    public function set(string $name, bool|int|float|string|null $value): void
    {
        $this->values[$name] = $value;
    }

    /** Adds to a counter (0 when it was not there). */
    public function add(string $name, int|float $by = 1): int|float
    {
        $now = $this->values[$name] ?? 0;
        $value = (is_int($now) || is_float($now) ? $now : 0) + $by;
        $this->values[$name] = $value;
        return $value;
    }

    /**
     * What a dialogue's "set" says: a value, or "+5" / "-2" to add to a counter.
     *
     * @param array<string, mixed> $set
     */
    public function apply(array $set): void
    {
        foreach ($set as $name => $value) {
            if (is_string($value) && preg_match('/^[+-]\d+(\.\d+)?$/', $value)) {
                $this->add((string)$name, str_contains($value, '.') ? (float)$value : (int)$value);
            } elseif (is_bool($value) || is_int($value) || is_float($value) || is_string($value) || $value === null) {
                $this->values[(string)$name] = $value;
            }
        }
    }

    /** @return array<string, bool|int|float|string|null> */
    public function toArray(): array
    {
        return $this->values;
    }

    /** @param array<string, mixed> $values */
    public function restore(array $values): void
    {
        $this->values = [];
        foreach ($values as $name => $value) {
            if (is_bool($value) || is_int($value) || is_float($value) || is_string($value) || $value === null) {
                $this->values[(string)$name] = $value;        // as saved: a string "+5" is a string here, not an addition
            }
        }
    }

    public function reset(): void
    {
        $this->values = [];
    }
}
