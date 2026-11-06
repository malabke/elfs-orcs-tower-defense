<?php

namespace JumPHP\Dialogue;

use JumPHP\Extension\AssetLoader;

/**
 * Reads a .dialogue.json file: {"dialogues": {"intro": {...}}, "quests": {"rescue": {...}}, "variables": {"gold": 10}}, the three parts optional,
 * checked. `$engine->extensions()->load('assets/dialogue/village.dialogue.json')` gives ['dialogues' => [id => Dialogue], 'quests' => ..., 'variables' => ...];
 * DialogueRunner::load() takes it.
 */
final class DialogueLoader implements AssetLoader
{
    /** @return array{dialogues: array<string, Dialogue>, quests: array<string, array<string, mixed>>, variables: array<string, mixed>} */
    public function load(string $file): array
    {
        $data = json_decode((string)@file_get_contents($file), true);
        if (!is_array($data)) {
            throw new DialogueException("$file: not a JSON object");
        }
        $dialogues = [];
        foreach ((array)($data['dialogues'] ?? []) as $id => $d) {
            $dialogues[(string)$id] = Dialogue::fromArray((string)$id, (array)$d);
        }
        return ['dialogues' => $dialogues, 'quests' => (array)($data['quests'] ?? []), 'variables' => (array)($data['variables'] ?? [])];
    }
}
