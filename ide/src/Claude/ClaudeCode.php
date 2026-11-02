<?php

namespace JumPHP\Ide\Claude;

use JumPHP\Ide\Async\Environment;
use JumPHP\Ide\Agent\AgentTools;
use JumPHP\Ide\Agent\IdeAgent;
use JumPHP\Ide\Async\Processes;

/**
 * The Claude Code of this computer (the `claude` command), driven from the IDE's Claude tool window: each message
 * runs `claude -p` in the project's folder, its stream-json lines go to the interface as they come (claude.event
 * {id, event}), the end as claude.exit {id, code, error}. The conversation goes on with --resume <session_id> (the
 * id the first answer gave). Nothing is sent anywhere by the IDE itself: claude does it, with the user's own login.
 *
 * A conversation ($conversation, the Claude tool window): one `claude -p --input-format stream-json` for the whole
 * conversation, its stdin left open. A message sent while Claude works is written to it at once: Claude Code takes it
 * into the turn in progress (more information, "not that way"), as in its own terminal. A turn ends with its `result`
 * event; the process stays for the next message (claude.exit only when it ends: stopped, another conversation, other
 * settings, or idle for IDLE seconds).
 *
 * With an IdeAgent, claude also gets the IDE's own tools (an MCP server on 127.0.0.1, AgentTools): it opens files,
 * points at lines, runs the configurations in the Run window, asks the user... where the user watches.
 */
final class ClaudeCode
{
    /** what claude may do without asking (claude -p cannot ask): --permission-mode */
    public const MODES = ['default', 'acceptEdits', 'plan', 'bypassPermissions'];

    /** how hard it thinks: --effort */
    public const EFFORTS = ['low', 'medium', 'high', 'xhigh', 'max'];

    /** a model: an alias (fable, opus, sonnet, haiku) or a full name (claude-opus-5-5, claude-sonnet-5-5[1m]...) */
    public const MODEL = '/^[A-Za-z0-9._\[\]-]{1,60}$/';

    /** an idle conversation's process ends after this many seconds (the next message resumes it: --resume) */
    public const IDLE = 900;

    /** @var array<int, string> run id => the part of a line not ended yet */
    private array $buffers = [];

    /** @var array{id: int, session: ?string, settings: string, busy: bool, last: float}|null the open conversation */
    private ?array $live = null;

    /** @param callable(string $event, array $payload): void $emit */
    public function __construct(private Processes $processes, private string $root, private $emit, private ?IdeAgent $agent = null)
    {
    }

    /** @return array{available: bool, path: ?string, version: ?string} */
    public function status(): array
    {
        $path = Environment::which('claude');
        $version = null;
        if ($path !== null) {
            $out = [];
            @exec(escapeshellarg($path) . ' --version 2>/dev/null', $out);
            $version = trim(implode(' ', $out)) ?: null;
        }
        return ['available' => $path !== null, 'path' => $path, 'version' => $version];
    }

    /**
     * @param string|null $session the session_id of the conversation to go on with
     * @return array{id: int}
     */
    public function send(string $prompt, ?string $session = null, string $mode = 'acceptEdits', ?string $model = null, ?string $effort = null,
        bool $conversation = false): array
    {
        if (trim($prompt) === '') {
            throw new \InvalidArgumentException('Nothing to send.');
        }
        $settings = json_encode([$mode, $model, $effort]);
        $live = $this->live;
        if ($conversation && $live !== null && $this->processes->isRunning($live['id']) && $live['session'] === $session
            && ($live['busy'] || $live['settings'] === $settings)) {
            // the open conversation: at once, even in the middle of a turn
            if ($this->processes->write($live['id'], self::message($prompt))) {
                $this->live = ['busy' => true, 'last' => microtime(true)] + $live;
                return ['id' => $live['id'], 'joined' => $live['busy']];
            }
        }
        if ($live !== null) {
            $this->processes->closeInput($live['id']);     // it ends once its turn is over
            $this->live = null;
        }
        if (Environment::which('claude') === null) {
            throw new \RuntimeException('Claude Code was not found on this computer (the `claude` command). Install it: https://claude.com/claude-code');
        }
        $command = ['claude', '-p', ...($conversation ? ['--input-format', 'stream-json'] : [$prompt]), '--output-format', 'stream-json', '--verbose',
            '--permission-mode', in_array($mode, self::MODES, true) ? $mode : 'acceptEdits'];
        if ($session !== null && preg_match('/^[\w-]{8,64}$/', $session)) {
            array_push($command, '--resume', $session);
        }
        if ($model !== null && preg_match(self::MODEL, $model)) {
            array_push($command, '--model', $model);
        }
        if ($effort !== null && in_array($effort, self::EFFORTS, true)) {
            array_push($command, '--effort', $effort);
        }
        if ($this->agent !== null) {
            try {
                array_push($command, '--mcp-config', $this->agent->mcpConfigFile(), '--allowedTools', 'mcp__' . AgentTools::SERVER,
                    '--append-system-prompt', AgentTools::INSTRUCTIONS);
            } catch (\Throwable) {
                // no loopback: claude works without the IDE's tools
            }
        }
        $id = 0;
        $started = $this->processes->start($command, $this->root, ['JUMPHP_IDE' => '1'], function (string $chunk, string $stream) use (&$id): void {
            if ($stream === 'stdout') {
                $this->lines($id, $chunk);
            }
        }, $conversation ? self::message($prompt) : null, $conversation);
        $id = $started['id'];
        $this->buffers[$id] = '';
        if ($conversation) {
            $this->live = ['id' => $id, 'session' => $session, 'settings' => $settings, 'busy' => true, 'last' => microtime(true)];
        }
        $started['done']->then(function (array $result) use ($id): void {
            $this->lines($id, "\n");
            unset($this->buffers[$id]);
            if (($this->live['id'] ?? null) === $id) {
                $this->live = null;
            }
            ($this->emit)('claude.exit', ['id' => $id, 'code' => $result['code'],
                'error' => $result['code'] !== 0 ? mb_substr(trim($result['stderr']), -2000) : null]);
        }, function (\Throwable $e) use ($id): void {
            unset($this->buffers[$id]);
            if (($this->live['id'] ?? null) === $id) {
                $this->live = null;
            }
            ($this->emit)('claude.exit', ['id' => $id, 'code' => -1, 'error' => $e->getMessage()]);
        });
        return ['id' => $id];
    }

    public function stop(int $id): bool
    {
        return $this->processes->stop($id);
    }

    /** Every frame or so: an idle conversation's process ends after IDLE seconds (it resumes at the next message). */
    public function tick(): void
    {
        if ($this->live !== null && !$this->live['busy'] && microtime(true) - $this->live['last'] > self::IDLE) {
            $this->processes->closeInput($this->live['id']);
            $this->live = null;
        }
    }

    /** A user message for `--input-format stream-json`: one line of JSON. */
    private static function message(string $prompt): string
    {
        return json_encode(['type' => 'user', 'message' => ['role' => 'user', 'content' => [['type' => 'text', 'text' => $prompt]]]],
            JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE) . "\n";
    }

    /** Whole JSON lines to the interface, the rest kept for the next chunk. */
    private function lines(int $id, string $chunk): void
    {
        $buffer = ($this->buffers[$id] ?? '') . $chunk;
        while (($end = strpos($buffer, "\n")) !== false) {
            $line = trim(substr($buffer, 0, $end));
            $buffer = substr($buffer, $end + 1);
            if ($line === '') {
                continue;
            }
            $event = json_decode($line, true);
            if (is_array($event) && ($this->live['id'] ?? null) === $id) {
                if (is_string($event['session_id'] ?? null)) {
                    $this->live['session'] = $event['session_id'];
                }
                if (($event['type'] ?? null) === 'result') {
                    $this->live['busy'] = false;     // the turn is over: the process waits for the next message
                    $this->live['last'] = microtime(true);
                }
            }
            ($this->emit)('claude.event', ['id' => $id, 'event' => is_array($event) ? $event : ['type' => 'text', 'text' => $line]]);
        }
        $this->buffers[$id] = $buffer;
    }
}
