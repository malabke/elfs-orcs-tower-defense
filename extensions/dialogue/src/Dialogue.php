<?php

namespace JumPHP\Dialogue;

/**
 * One conversation: nodes (what is said, then what follows). Made from an array / a .dialogue.json file and CHECKED when it is made: an unknown
 * "next", no "start", a broken condition are errors that say where, not a crash in the middle of the game.
 *
 *   {"start": "hello", "nodes": {
 *     "hello": {"speaker": "Old man", "text": "Welcome, {name}!", "next": "ask"},
 *     "ask":   {"speaker": "Old man", "text": "Will you help?", "choices": [
 *         {"text": "Yes", "next": "yes", "set": {"helped": true}, "action": "quest.start:rescue"},
 *         {"text": "Show the key", "if": "has_key", "next": "key"},
 *         {"text": "No", "next": "no"}]},
 *     "yes":   {"text": "Thank you!", "end": true}}}
 *
 * A node: speaker, text (a string, or a list of strings said one after the other), next, choices, set, action (a string or a list), end, and
 * branch: [{"if": "quest.rescue == 'done'", "next": "thanks"}, {"next": "hello"}]: a node that sends the story elsewhere by the conditions (the first that
 * holds, one without "if" is the default), left at once: a router for the start of a conversation.
 * The first node is the start unless "start" names another.
 */
final class Dialogue
{
    /** @param array<string, array<string, mixed>> $nodes */
    private function __construct(public readonly string $id, public readonly string $start, public readonly array $nodes)
    {
    }

    /** @param array<string, mixed> $data */
    public static function fromArray(string $id, array $data): self
    {
        $nodes = $data['nodes'] ?? null;
        if (!is_array($nodes) || $nodes === []) {
            throw new DialogueException("Dialogue '$id': no \"nodes\"");
        }
        $start = (string)($data['start'] ?? array_key_first($nodes));
        if (!isset($nodes[$start])) {
            throw new DialogueException("Dialogue '$id': the start node '$start' does not exist");
        }
        foreach ($nodes as $name => $node) {
            $where = "Dialogue '$id', node '$name'";
            if (!is_array($node)) {
                throw new DialogueException("$where: not an object");
            }
            if (!isset($node['text']) && empty($node['choices']) && empty($node['end']) && !isset($node['next']) && empty($node['branch'])) {
                throw new DialogueException("$where: it says nothing (a \"text\", \"choices\", \"next\", \"branch\" or \"end\" is needed)");
            }
            foreach ((array)($node['branch'] ?? []) as $i => $b) {
                $w = "$where, branch " . ($i + 1);
                if (!is_array($b) || !isset($b['next']) || !isset($nodes[$b['next']])) {
                    throw new DialogueException("$w: \"next\" must name an existing node");
                }
                if (isset($b['if'])) {
                    self::checkCondition((string)$b['if'], $w);
                }
            }
            if (isset($node['next']) && !isset($nodes[$node['next']])) {
                throw new DialogueException("$where: \"next\" goes to '{$node['next']}', which does not exist");
            }
            foreach ((array)($node['choices'] ?? []) as $i => $choice) {
                $w = "$where, choice " . ($i + 1);
                if (!is_array($choice) || !isset($choice['text'])) {
                    throw new DialogueException("$w: a \"text\" is needed");
                }
                if (isset($choice['next']) && !isset($nodes[$choice['next']])) {
                    throw new DialogueException("$w: \"next\" goes to '{$choice['next']}', which does not exist");
                }
                if (isset($choice['if'])) {
                    self::checkCondition((string)$choice['if'], $w);
                }
            }
        }
        return new self($id, $start, $nodes);
    }

    /** A broken condition is reported with where it is. */
    private static function checkCondition(string $expression, string $where): void
    {
        try {
            Condition::check($expression);
        } catch (DialogueException $e) {
            throw new DialogueException("$where: {$e->getMessage()}");
        }
    }

    /** @return array<string, mixed> */
    public function node(string $name): array
    {
        return $this->nodes[$name] ?? throw new DialogueException("Dialogue '$this->id': no node '$name'");
    }
}
