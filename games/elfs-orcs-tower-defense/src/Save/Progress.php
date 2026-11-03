<?php

namespace App\Save;

use JumPHP\Save\Storage;

/**
 * The player's progress (var/progress.json, IndexedDB in the browser): the best stars per side and map, in normal
 * and in hard mode. A map of the campaign opens when the one before it is won (per side); hard mode opens on a map
 * once it is won.
 *   {"stars": {"elves": {"thornvale": 3}}, "hard": {"elves": {"thornvale": 1}}}
 */
final class Progress
{
    public const FILE = 'progress.json';

    private array $data = ['stars' => [], 'hard' => []];

    public function __construct(private ?Storage $storage = null)
    {
    }

    public function load(Storage $storage): self
    {
        $this->storage = $storage;
        $data = $storage->readJson(self::FILE, []);
        $this->data = [
            'stars' => is_array($data['stars'] ?? null) ? $data['stars'] : [],
            'hard' => is_array($data['hard'] ?? null) ? $data['hard'] : [],
        ];
        return $this;
    }

    public function stars(string $faction, string $map, bool $hard = false): int
    {
        return (int)($this->data[$hard ? 'hard' : 'stars'][$faction][$map] ?? 0);
    }

    /** Keeps the best result; saved at once. */
    public function record(string $faction, string $map, int $stars, bool $hard = false): void
    {
        $key = $hard ? 'hard' : 'stars';
        if ($stars > $this->stars($faction, $map, $hard)) {
            $this->data[$key][$faction][$map] = $stars;
            $this->storage?->writeJson(self::FILE, $this->data);
        }
    }

    /** @param list<string> $maps the campaign, in order */
    public function isOpen(string $faction, array $maps, string $map): bool
    {
        $i = array_search($map, $maps, true);
        return $i === 0 || ($i !== false && $this->stars($faction, $maps[$i - 1]) > 0);
    }

    public function totalStars(?string $faction = null): int
    {
        $total = 0;
        foreach (['stars', 'hard'] as $key) {
            foreach ($this->data[$key] as $side => $maps) {
                if ($faction === null || $side === $faction) {
                    $total += array_sum(array_map('intval', $maps));
                }
            }
        }
        return $total;
    }
}
