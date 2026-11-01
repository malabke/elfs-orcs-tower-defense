<?php

namespace App;

use JumPHP\Save\Storage;

/**
 * The IDE's crashes, for JumPHP's developers: saved when it crashes (a dying app cannot wait for the network), sent to
 * jumphp.net at the next start (POST /api/crash), where only JumPHP's developers read them. A report holds what
 * went wrong and where in the IDE's code (class, message, files and lines, the IDE's version, the system), never the
 * arguments of the calls, the user's files or who they are: the home folder becomes "~".
 */
final class CrashReports
{
    public const DIR = 'crashes';

    public function __construct(private Storage $storage, private string $version)
    {
    }

    /** @return array<string, mixed> */
    public function report(\Throwable $e): array
    {
        $frames = [];
        foreach (array_slice($e->getTrace(), 0, 40) as $frame) {
            $frames[] = ($frame['class'] ?? '') . ($frame['type'] ?? '') . ($frame['function'] ?? '?') . '()'
                . (isset($frame['file']) ? ' ' . self::clean($frame['file']) . ':' . ($frame['line'] ?? 0) : '');
        }
        return [
            'app' => 'ide',
            'version' => $this->version,
            'time' => gmdate('c'),
            'os' => PHP_OS_FAMILY . ' ' . php_uname('m'),
            'php' => PHP_VERSION,
            'class' => get_class($e),
            'message' => mb_substr(self::clean($e->getMessage()), 0, 2000),
            'at' => self::clean($e->getFile()) . ':' . $e->getLine(),
            'trace' => $frames,
        ];
    }

    public function save(\Throwable $e): void
    {
        $this->storage->writeJson(self::DIR . '/' . gmdate('Ymd-His') . '-' . bin2hex(random_bytes(3)) . '.json', $this->report($e));
    }

    /** @return array<string, array<string, mixed>> the reports not sent yet: name => report */
    public function pending(): array
    {
        $out = [];
        foreach (array_slice(glob($this->storage->path(self::DIR) . '/*.json') ?: [], -20) as $file) {
            $report = json_decode((string)file_get_contents($file), true);
            if (is_array($report)) {
                $out[self::DIR . '/' . basename($file)] = $report;
            }
        }
        return $out;
    }

    /** Sends what is pending; a report sent is deleted, one that could not be sent waits for the next start. */
    public function send(Playground $playground): void
    {
        foreach ($this->pending() as $name => $report) {
            $playground->post('/api/crash', $report)
                ->then(fn () => $this->storage->delete($name), fn () => null);
        }
    }

    /** Paths without the user's home folder (their name is often in it), nor the IDE's unpacked cache. */
    private static function clean(string $text): string
    {
        $home = (string)(getenv('HOME') ?: getenv('USERPROFILE') ?: '');
        if ($home !== '' && strlen($home) > 1) {
            $text = str_replace([$home, str_replace('\\', '/', $home)], '~', $text);
        }
        return (string)preg_replace('#~/\.cache/jumphp/ide-[0-9a-f]+/#', 'ide/', str_replace('\\', '/', $text));
    }
}
