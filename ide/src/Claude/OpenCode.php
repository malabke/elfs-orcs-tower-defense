<?php

namespace JumPHP\Ide\Claude;

use JumPHP\Ide\Async\Environment;
use JumPHP\Ide\Async\Processes;

/**
 * OpenCode (https://opencode.ai) as the assistant of the Claude window: one `opencode run --format json` per message, in the project's
 * folder, with any model it is set up for (a local one through Ollama / LM Studio: the code stays on this computer). Its JSON lines
 * (step_start, text, tool_use, step_finish) are told to the window as the events Claude Code's stream-json has (system init, assistant,
 * user tool_result, result), so the window shows the answer, the files read / edited and the commands run with no other code.
 * The conversation goes on through OpenCode's own session (ses_...), kept by the window like Claude's session_id.
 */
final class OpenCode
{
    /** OpenCode's tools by the names the window knows (Claude's): tool => [name, input keys renamed] */
    private const TOOLS = [
        'read' => ['Read', ['filePath' => 'file_path']],
        'write' => ['Write', ['filePath' => 'file_path']],
        'edit' => ['Edit', ['filePath' => 'file_path', 'oldString' => 'old_string', 'newString' => 'new_string']],
        'multiedit' => ['Edit', ['filePath' => 'file_path']],
        'bash' => ['Bash', []],
        'glob' => ['Glob', []],
        'grep' => ['Grep', []],
        'list' => ['LS', []],
        'webfetch' => ['WebFetch', []],
        'task' => ['Task', []],
        'todowrite' => ['TodoWrite', []],
    ];

    /** @var array<int, string> the turns running: process id => conversation */
    private array $turns = [];

    /** @param callable(string, array<string, mixed>): void $emit */
    public function __construct(private Processes $processes, private string $root, private $emit)
    {
    }

    public static function path(): ?string
    {
        return Environment::which('opencode');
    }

    /** @return array{available: bool, path: ?string} */
    public function status(): array
    {
        $path = self::path();
        return ['available' => $path !== null, 'path' => $path];
    }

    public function owns(int $id): bool
    {
        return isset($this->turns[$id]);
    }

    /** @return list<int> the processes of a conversation's running turns */
    public function turnsOf(string $conv): array
    {
        return array_keys(array_filter($this->turns, fn (string $c) => $c === $conv));
    }

    public function busy(string $conv): bool
    {
        return in_array($conv, $this->turns, true);
    }

    /** @return array{id: int} */
    public function send(string $prompt, ?string $session, ?string $model, string $conv): array
    {
        if (trim($prompt) === '') {
            throw new \InvalidArgumentException('Nothing to send.');
        }
        if (self::path() === null) {
            throw new \RuntimeException('OpenCode was not found on this computer (the `opencode` command). Install it: https://opencode.ai');
        }
        if ($this->busy($conv)) {
            throw new \RuntimeException('OpenCode answers one message at a time: wait for the end of this turn (or stop it).');
        }
        $command = ['opencode', 'run', '--format', 'json'];
        if ($session !== null && preg_match('/^ses_[A-Za-z0-9]{8,64}$/', $session)) {
            array_push($command, '--session', $session);
        }
        if ($model !== null && preg_match('#^[A-Za-z0-9._:/@-]{1,100}$#', $model)) {
            array_push($command, '--model', $model);
        }
        $command[] = $prompt;
        $state = ['started' => microtime(true), 'model' => $model];
        $buffer = '';
        $id = 0;
        $started = $this->processes->start($command, $this->root, ['JUMPHP_IDE' => '1'], function (string $chunk, string $stream) use (&$id, &$buffer, &$state, $conv): void {
            if ($stream !== 'stdout') {
                return;
            }
            $buffer .= $chunk;
            while (($end = strpos($buffer, "\n")) !== false) {
                $line = trim(substr($buffer, 0, $end));
                $buffer = substr($buffer, $end + 1);
                $event = $line !== '' ? json_decode($line, true) : null;
                foreach (is_array($event) ? self::translate($event, $state) : [] as $out) {
                    ($this->emit)('claude.event', ['id' => $id, 'conv' => $conv, 'event' => $out]);
                }
            }
        });
        $id = $started['id'];
        $this->turns[$id] = $conv;
        $started['done']->then(function (array $result) use ($id, $conv, &$state): void {
            unset($this->turns[$id]);
            $failed = $result['code'] !== 0 && ($state['text'] ?? '') === '';
            ($this->emit)('claude.event', ['id' => $id, 'conv' => $conv, 'event' => self::finish($state, $failed ? mb_substr(trim((string)$result['stderr']), -1500) : null)]);
            ($this->emit)('claude.exit', ['id' => $id, 'conv' => $conv, 'code' => $result['code'], 'error' => $failed ? mb_substr(trim((string)$result['stderr']), -2000) : null]);
        }, function (\Throwable $e) use ($id, $conv): void {
            unset($this->turns[$id]);
            ($this->emit)('claude.exit', ['id' => $id, 'conv' => $conv, 'code' => -1, 'error' => $e->getMessage()]);
        });
        return ['id' => $id];
    }

    public function stop(int $id): bool
    {
        return $this->processes->stop($id);
    }

    /**
     * One OpenCode event as the events of Claude Code's stream-json (none, one or two).
     *
     * @param array<string, mixed> $e
     * @param array<string, mixed> $state kept between events: the session, the last text, the cost, the tokens
     * @return list<array<string, mixed>>
     */
    public static function translate(array $e, array &$state): array
    {
        $session = (string)($e['sessionID'] ?? $state['session'] ?? '');
        $state['session'] = $session;
        $part = (array)($e['part'] ?? []);
        $out = [];
        if (empty($state['init'])) {
            $state['init'] = true;
            $out[] = ['type' => 'system', 'subtype' => 'init', 'session_id' => $session, 'model' => $state['model'] ?? 'OpenCode', 'tools' => []];
        }
        switch ($e['type'] ?? '') {
            case 'text':
                $text = (string)($part['text'] ?? '');
                if (trim($text) !== '' && $text !== ($state['text'] ?? null)) {         // OpenCode says its last answer twice
                    $state['text'] = $text;
                    $out[] = ['type' => 'assistant', 'session_id' => $session, 'message' => ['content' => [['type' => 'text', 'text' => $text]]]];
                }
                break;
            case 'tool_use':
                $tool = (string)($part['tool'] ?? '');
                [$name, $rename] = self::TOOLS[$tool] ?? [ucfirst($tool), []];
                $call = (string)($part['callID'] ?? $part['id'] ?? $tool);
                $input = [];
                foreach ((array)($part['state']['input'] ?? []) as $k => $v) {
                    $input[$rename[$k] ?? $k] = $v;
                }
                $out[] = ['type' => 'assistant', 'session_id' => $session, 'message' => ['content' => [['type' => 'tool_use', 'id' => $call, 'name' => $name, 'input' => $input]]]];
                $failed = ($part['state']['status'] ?? '') === 'error';
                $result = (string)($part['state']['output'] ?? $part['state']['error'] ?? '');
                $out[] = ['type' => 'user', 'session_id' => $session, 'message' => ['content' => [['type' => 'tool_result', 'tool_use_id' => $call, 'content' => $result, 'is_error' => $failed]]]];
                $state['tools'] = ($state['tools'] ?? 0) + 1;
                break;
            case 'step_finish':
                $state['cost'] = ($state['cost'] ?? 0.0) + (float)($part['cost'] ?? 0);
                $state['tokens'] = ($state['tokens'] ?? 0) + (int)($part['tokens']['output'] ?? 0);
                $state['context'] = (int)($part['tokens']['total'] ?? $part['tokens']['input'] ?? 0);
                break;
            case 'error':
                $error = $e['error'] ?? [];
                $state['error'] = is_array($error) ? (string)($error['data']['message'] ?? $error['message'] ?? $error['name'] ?? json_encode($error)) : (string)$error;
                break;
        }
        return $out;
    }

    /** The result event that ends a turn. */
    public static function finish(array $state, ?string $failure = null): array
    {
        $error = $state['error'] ?? $failure;
        return ['type' => 'result', 'subtype' => $error !== null ? 'error' : 'success', 'is_error' => $error !== null, 'session_id' => $state['session'] ?? '',
            'result' => $error ?? (string)($state['text'] ?? ''), 'duration_ms' => (int)round((microtime(true) - ($state['started'] ?? microtime(true))) * 1000),
            'total_cost_usd' => $state['cost'] ?? 0.0, 'num_turns' => 1];
    }
}
