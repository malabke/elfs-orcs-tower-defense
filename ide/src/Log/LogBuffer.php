<?php

namespace JumPHP\Ide\Log;

/**
 * The IDE's detailed logs, readable in its Logs window and kept on disk for bug reports: what the IDE itself does and fails at, the
 * programs it runs (bin/jumphp, Docker, git, Claude, OpenCode...: command, how it ended, why it failed), its plugins. The last MAX
 * entries are kept in memory (the window asks for them, then follows the new ones: onEntry), every entry also goes to a file that
 * is turned into <file>.1 when it passes $maxFileBytes (two files at most: nothing grows for ever).
 */
final class LogBuffer
{
    public const LEVELS = ['debug' => 0, 'info' => 1, 'warn' => 2, 'error' => 3];
    public const MAX = 3000;
    public const MAX_MESSAGE = 4000;

    /** @var list<array{id: int, time: float, level: string, source: string, message: string}> */
    private array $entries = [];
    private int $next = 1;
    /** @var callable(array<string, mixed>): void|null */
    private $listener = null;

    public function __construct(private ?string $file = null, private int $maxFileBytes = 2_000_000)
    {
        if ($file !== null) {
            @mkdir(dirname($file), 0775, true);
        }
    }

    public function file(): ?string
    {
        return $this->file;
    }

    /** @param callable(array<string, mixed>): void $listener told of each entry as it is added */
    public function onEntry(callable $listener): void
    {
        $this->listener = $listener;
    }

    /** @return array{id: int, time: float, level: string, source: string, message: string} */
    public function add(string $level, string $source, string $message): array
    {
        $level = isset(self::LEVELS[$level]) ? $level : 'info';
        $message = mb_strlen($message) > self::MAX_MESSAGE ? mb_substr($message, 0, self::MAX_MESSAGE) . '…' : $message;
        $entry = ['id' => $this->next++, 'time' => round(microtime(true), 3), 'level' => $level, 'source' => $source, 'message' => rtrim($message)];
        $this->entries[] = $entry;
        if (count($this->entries) > self::MAX) {
            array_splice($this->entries, 0, count($this->entries) - self::MAX);
        }
        $this->write($entry);
        if ($this->listener !== null) {
            ($this->listener)($entry);
        }
        return $entry;
    }

    /**
     * The entries after $afterId, at least $level, of $source ('' = all), whose text holds $query; the newest $limit.
     *
     * @return list<array{id: int, time: float, level: string, source: string, message: string}>
     */
    public function list(int $afterId = 0, string $level = 'debug', string $source = '', string $query = '', int $limit = 500): array
    {
        $min = self::LEVELS[$level] ?? 0;
        $out = [];
        foreach ($this->entries as $e) {
            if ($e['id'] <= $afterId || self::LEVELS[$e['level']] < $min || ($source !== '' && $e['source'] !== $source)
                || ($query !== '' && stripos($e['source'] . ' ' . $e['message'], $query) === false)) {
                continue;
            }
            $out[] = $e;
        }
        return array_slice($out, -max(1, $limit));
    }

    /** @return list<string> the sources seen so far */
    public function sources(): array
    {
        $sources = array_values(array_unique(array_column($this->entries, 'source')));
        sort($sources);
        return $sources;
    }

    public function clear(): void
    {
        $this->entries = [];
    }

    /** @param array{id: int, time: float, level: string, source: string, message: string} $e */
    private function write(array $e): void
    {
        if ($this->file === null) {
            return;
        }
        $line = sprintf("%s %-5s %s: %s\n", gmdate('Y-m-d\TH:i:s', (int)$e['time']) . sprintf('.%03dZ', (int)(($e['time'] - floor($e['time'])) * 1000)),
            strtoupper($e['level']), $e['source'], str_replace("\n", "\n    ", $e['message']));
        if (@filesize($this->file) > $this->maxFileBytes) {
            @rename($this->file, $this->file . '.1');
        }
        @file_put_contents($this->file, $line, FILE_APPEND | LOCK_EX);
    }
}
