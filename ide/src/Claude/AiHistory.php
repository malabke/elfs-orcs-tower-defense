<?php

namespace JumPHP\Ide\Claude;

/**
 * What each AI did, kept in the project: IA/HISTORY/<CLAUDE|OPEN CODE>/<model>/<dd-mm-YYYY HH-MM-SS>/<what it was asked>.md
 * (one file per turn: the request(s), what it answered, the tools it used, the result). Watches the events the Claude
 * window gets (claude.event); written when a turn ends. The folder is NEVER sent to git (IA/ is added to .gitignore):
 * it lives on this computer only, and is lost with the folder.
 */
final class AiHistory
{
    /** @var array<string, array{agent: string, model: ?string, started: float, prompts: list<string>, texts: list<string>, tools: list<string>}> conversation => the turn in progress */
    private array $turns = [];

    public function __construct(private string $root)
    {
    }

    /** A message sent to an assistant: it starts the turn, or joins the one in progress. */
    public function sent(string $conv, string $agent, ?string $model, string $prompt): void
    {
        if (!isset($this->turns[$conv])) {
            $this->turns[$conv] = ['agent' => $agent, 'model' => $model, 'started' => microtime(true), 'prompts' => [], 'texts' => [], 'tools' => []];
        }
        $this->turns[$conv]['prompts'][] = $prompt;
    }

    /** @param array<string, mixed> $event a stream-json event (Claude Code's, or OpenCode's told in the same words) */
    public function observe(string $conv, array $event): ?string
    {
        $turn = &$this->turns[$conv];
        if (!isset($turn)) {
            unset($this->turns[$conv]);
            return null;
        }
        switch ($event['type'] ?? '') {
            case 'system':
                if (is_string($event['model'] ?? null) && $event['model'] !== '' && $event['model'] !== 'OpenCode') {
                    $turn['model'] = $event['model'];
                }
                break;
            case 'assistant':
                foreach ((array)($event['message']['content'] ?? []) as $part) {
                    if (($part['type'] ?? '') === 'text' && trim((string)$part['text']) !== '') {
                        $turn['texts'][] = trim((string)$part['text']);
                    } elseif (($part['type'] ?? '') === 'tool_use') {
                        $input = (array)($part['input'] ?? []);
                        $what = (string)($input['file_path'] ?? $input['path'] ?? $input['command'] ?? $input['pattern'] ?? $input['url'] ?? '');
                        $turn['tools'][] = trim($part['name'] . ' ' . mb_substr(preg_replace('/\s+/', ' ', $what) ?? '', 0, 160));
                    }
                }
                break;
            case 'result':
                $done = $turn;
                unset($this->turns[$conv]);
                return $this->write($done, $event);
        }
        return null;
    }

    /** @param array<string, mixed> $turn @param array<string, mixed> $result @return string|null the file written (path from the project root) */
    private function write(array $turn, array $result): ?string
    {
        $agent = $turn['agent'] === 'opencode' ? 'OPEN CODE' : 'CLAUDE';
        $model = preg_replace('/[^A-Za-z0-9._\[\]-]+/', '-', (string)($turn['model'] ?? '')) ?: 'default';
        $date = date('d-m-Y H-i-s', (int)$turn['started']);     // no ":" (not allowed in Windows file names)
        $first = (string)($turn['prompts'][0] ?? 'turn');
        $slug = trim((string)preg_replace('/[^\p{L}\p{N}]+/u', '-', mb_strtolower(mb_substr(trim($first), 0, 50))), '-') ?: 'turn';
        $dir = "$this->root/IA/HISTORY/$agent/$model/$date";
        if (!@is_dir($dir) && !@mkdir($dir, 0775, true)) {
            return null;
        }
        $file = "$dir/$slug.md";
        for ($n = 2; is_file($file); $n++) {
            $file = "$dir/$slug-$n.md";
        }
        $md = "# $slug\n\n- Assistant: $agent\n- Model: " . ($turn['model'] ?? 'default') . "\n- Started: " . date('Y-m-d H:i:s', (int)$turn['started'])
            . "\n- Duration: " . round((float)($result['duration_ms'] ?? 0) / 1000, 1) . " s\n- Result: " . (($result['is_error'] ?? false) ? 'error' : 'ok') . "\n\n## Asked\n\n"
            . implode("\n\n---\n\n", $turn['prompts']) . "\n\n## Tools used\n\n" . ($turn['tools'] ? '- ' . implode("\n- ", $turn['tools']) : '(none)')
            . "\n\n## Answer\n\n" . ($turn['texts'] ? implode("\n\n", $turn['texts']) : (string)($result['result'] ?? '')) . "\n";
        file_put_contents($file, $md);
        $this->ignore();
        return substr($file, strlen($this->root) + 1);
    }

    /** IA/ stays out of git: this history is private to the computer. */
    private function ignore(): void
    {
        $file = "$this->root/.gitignore";
        $text = is_file($file) ? (string)file_get_contents($file) : '';
        if (!preg_match('#^/?IA/?\s*$#m', $text)) {
            file_put_contents($file, rtrim($text, "\n") . ($text === '' ? '' : "\n") . "# what the AI assistants did (the IDE's Claude / OpenCode): private to this computer, never in git\n/IA/\n");
        }
    }
}
