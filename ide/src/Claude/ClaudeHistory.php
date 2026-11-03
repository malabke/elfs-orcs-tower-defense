<?php

namespace JumPHP\Ide\Claude;

use JumPHP\Ide\Workspace\Workspace;

/**
 * Every conversation with Claude in the IDE, kept in the project: .jumphp/history/claude/<id>.json (never in git: the
 * folder has its own .gitignore). One JSON file per conversation: its title, Claude Code's session (to go on with it:
 * --resume), and what the Claude window shows (messages, tools, notes), as the window saved it last.
 * The Claude window's History lists them, reads one again in a tab, and reopens it.
 */
final class ClaudeHistory
{
    public const DIR = '.jumphp/history/claude';

    public function __construct(private readonly Workspace $workspace)
    {
    }

    /**
     * Saves a conversation (the whole of it: the window sends it after each turn).
     * @param array<string, mixed> $conversation {id, title, session, items, created}
     * @return array<string, mixed> its summary (as list() gives it)
     */
    public function save(array $conversation): array
    {
        $id = self::id((string)($conversation['id'] ?? ''));
        $items = array_values(array_filter((array)($conversation['items'] ?? []), 'is_array'));
        $path = self::DIR . "/$id.json";
        $old = $this->workspace->exists($path) ? (json_decode($this->workspace->read($path), true) ?: []) : [];
        $data = [
            'id' => $id,
            'title' => self::title((string)($conversation['title'] ?? ''), $items),
            'session' => is_string($conversation['session'] ?? null) && preg_match('/^[\w-]{8,64}$/', $conversation['session']) ? $conversation['session'] : null,
            'created' => (int)($old['created'] ?? $conversation['created'] ?? time()),
            'updated' => microtime(true),
            'items' => $items,
        ];
        $this->ignore();
        $this->workspace->write($path, (string)json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE));
        return self::summary($data);
    }

    /** @return list<array{id: string, title: string, session: ?string, created: int, updated: float, messages: int}> newest first */
    public function list(): array
    {
        $out = [];
        $root = $this->workspace->localPath();
        $paths = $root !== null
            ? array_map(fn (string $f) => self::DIR . '/' . basename($f), glob("$root/" . self::DIR . '/*.json') ?: [])
            : array_keys($this->workspace->files(true));     // the whole listing: vendor/ too, only where it is small
        foreach ($paths as $path) {
            if (str_starts_with($path, self::DIR . '/') && str_ends_with($path, '.json') && substr_count($path, '/') === 3) {
                $data = json_decode($this->workspace->read($path), true);
                if (is_array($data) && isset($data['id'])) {
                    $out[] = self::summary($data);
                }
            }
        }
        usort($out, fn (array $a, array $b) => $b['updated'] <=> $a['updated']);
        return $out;
    }

    /** @return array<string, mixed> the whole conversation */
    public function read(string $id): array
    {
        $path = self::DIR . '/' . self::id($id) . '.json';
        $data = $this->workspace->exists($path) ? json_decode($this->workspace->read($path), true) : null;
        if (!is_array($data)) {
            throw new \RuntimeException("no conversation $id");
        }
        return $data;
    }

    public function delete(string $id): bool
    {
        $path = self::DIR . '/' . self::id($id) . '.json';
        if (!$this->workspace->exists($path)) {
            return false;
        }
        $this->workspace->delete($path);
        return true;
    }

    /** The conversation as Markdown (to read it elsewhere, or paste it). */
    public function markdown(string $id): string
    {
        $data = $this->read($id);
        $out = '# ' . $data['title'] . "\n\n_" . date('Y-m-d H:i', (int)$data['created']) . "_\n";
        foreach ($data['items'] as $item) {
            $text = (string)($item['text'] ?? '');
            $out .= "\n" . match ($item['role'] ?? '') {
                'user' => "**You:** $text\n",
                'assistant' => "$text\n",
                'tool' => '> ' . (string)($item['name'] ?? '') . ' ' . (string)json_encode($item['input'] ?? [], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) . "\n",
                'done' => "_{$text}_\n",
                default => "> $text\n",
            };
        }
        return $out;
    }

    /** The folder never goes into git (a .gitignore of its own, so the project's needs nothing). */
    private function ignore(): void
    {
        if (!$this->workspace->exists('.jumphp/history/.gitignore')) {
            $this->workspace->write('.jumphp/history/.gitignore', "# the IDE's history of this computer (Claude's conversations, files' local history): never in git\n*\n");
        }
    }

    private static function id(string $id): string
    {
        if (!preg_match('/^[A-Za-z0-9_-]{4,64}$/', $id)) {
            throw new \InvalidArgumentException("not a conversation id: $id");
        }
        return $id;
    }

    /** @param list<array<string, mixed>> $items */
    private static function title(string $title, array $items): string
    {
        if (trim($title) === '') {
            foreach ($items as $item) {
                if (($item['role'] ?? '') === 'user') {
                    $title = (string)($item['text'] ?? '');
                    break;
                }
            }
        }
        $title = trim((string)preg_replace('/\s+/', ' ', $title));
        return $title === '' ? 'New conversation' : mb_strimwidth($title, 0, 80, '…');
    }

    /** @param array<string, mixed> $data */
    private static function summary(array $data): array
    {
        $items = (array)($data['items'] ?? []);
        return ['id' => (string)$data['id'], 'title' => (string)($data['title'] ?? ''), 'session' => $data['session'] ?? null,
            'created' => (int)($data['created'] ?? 0), 'updated' => (float)($data['updated'] ?? 0),
            'messages' => count(array_filter($items, fn ($i) => is_array($i) && in_array($i['role'] ?? '', ['user', 'assistant'], true)))];
    }
}
