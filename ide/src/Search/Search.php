<?php

namespace JumPHP\Ide\Search;

use JumPHP\Ide\Php\Index;
use JumPHP\Ide\Workspace\Paths;
use JumPHP\Ide\Workspace\Workspace;

/** "Go to file" (fuzzy file names) and "Find / Replace in files" (text or regular expression). */
final class Search
{
    public const MAX_FILE = 2_000_000;

    public function __construct(private Workspace $workspace)
    {
    }

    /** @return list<array{path: string, name: string, score: int}> */
    public function files(string $query, int $limit = 50): array
    {
        $out = [];
        $query = trim(str_replace('\\', '/', $query));
        foreach ($this->workspace->files() as $path => $meta) {
            if ($query === '') {
                $out[] = ['path' => $path, 'name' => basename($path), 'score' => 0];
                continue;
            }
            $name = basename($path);
            $score = str_contains($query, '/') ? Index::fuzzy($query, $path) : Index::fuzzy($query, $name);
            if ($score === null && !str_contains($query, '/')) {
                $score = Index::fuzzy($query, $path);
                $score = $score === null ? null : $score - 200;
            }
            if ($score !== null) {
                $out[] = ['path' => $path, 'name' => $name, 'score' => $score - intdiv(strlen($path), 8)];
            }
        }
        usort($out, fn ($a, $b) => $b['score'] <=> $a['score'] ?: strcmp($a['path'], $b['path']));
        return array_slice($out, 0, $limit);
    }

    /**
     * Matches of a text in the project's text files.
     *
     * @param array{regex?: bool, case?: bool, word?: bool, include?: string, exclude?: string} $options
     *        include / exclude: file masks, comma separated ("*.php, src/*")
     * @return array{results: list<array<string, mixed>>, files: int, matches: int, truncated: bool}
     */
    public function find(string $query, array $options = [], int $limit = 2000): array
    {
        $pattern = $this->pattern($query, $options);
        $results = [];
        $count = 0;
        $files = 0;
        $truncated = false;
        foreach ($this->workspace->files() as $path => $meta) {
            if ($meta['size'] > self::MAX_FILE || !Paths::isText($path) || !$this->included($path, $options)) {
                continue;
            }
            try {
                $text = $this->workspace->read($path);
            } catch (\Throwable) {
                continue;
            }
            if (!preg_match($pattern, $text)) {
                continue;
            }
            $matches = [];
            foreach (preg_split('/\n/', $text) ?: [] as $i => $line) {
                if (preg_match_all($pattern, $line, $found, PREG_OFFSET_CAPTURE) && $found[0]) {
                    foreach ($found[0] as [$match, $byte]) {
                        if ($match === '') {
                            continue;
                        }
                        $matches[] = [
                            'line' => $i + 1,
                            'col' => mb_strlen(substr($line, 0, $byte)) + 1,
                            'length' => mb_strlen($match),
                            'text' => mb_substr(rtrim($line, "\r"), 0, 400),
                        ];
                        if (++$count >= $limit) {
                            $truncated = true;
                            break 2;
                        }
                    }
                }
            }
            if ($matches) {
                $files++;
                $results[] = ['path' => $path, 'matches' => $matches];
            }
            if ($truncated) {
                break;
            }
        }
        return ['results' => $results, 'files' => $files, 'matches' => $count, 'truncated' => $truncated];
    }

    /**
     * Replaces every match (or only in $paths) and writes the files. Returns the files changed and how many
     * replacements.
     *
     * @param array{regex?: bool, case?: bool, word?: bool, include?: string, exclude?: string} $options
     * @param list<string>|null $paths
     * @return array{files: list<string>, replaced: int}
     */
    public function replace(string $query, string $replacement, array $options = [], ?array $paths = null): array
    {
        $pattern = $this->pattern($query, $options);
        $changed = [];
        $total = 0;
        if (empty($options['regex'])) {
            $replacement = str_replace(['\\', '$'], ['\\\\', '\\$'], $replacement);
        }
        foreach ($this->find($query, $options, 100000)['results'] as $result) {
            $path = $result['path'];
            if ($paths !== null && !in_array($path, $paths, true)) {
                continue;
            }
            $text = $this->workspace->read($path);
            $new = preg_replace($pattern, $replacement, $text, -1, $count);
            if ($new !== null && $count > 0 && $new !== $text) {
                $this->workspace->write($path, $new);
                $changed[] = $path;
                $total += $count;
            }
        }
        return ['files' => $changed, 'replaced' => $total];
    }

    /** @param array<string, mixed> $options */
    private function pattern(string $query, array $options): string
    {
        if ($query === '') {
            throw new \InvalidArgumentException('nothing to look for');
        }
        $body = !empty($options['regex']) ? str_replace('/', '\/', $query) : preg_quote($query, '/');
        if (!empty($options['word'])) {
            $body = '\b' . $body . '\b';
        }
        $pattern = '/' . $body . '/u' . (empty($options['case']) ? 'i' : '');
        if (@preg_match($pattern, '') === false) {
            throw new \InvalidArgumentException('bad regular expression: ' . preg_last_error_msg());
        }
        return $pattern;
    }

    /** @param array<string, mixed> $options */
    private function included(string $path, array $options): bool
    {
        $masks = fn (string $list) => array_filter(array_map('trim', explode(',', $list)));
        $include = $masks((string)($options['include'] ?? ''));
        $exclude = $masks((string)($options['exclude'] ?? ''));
        $matches = function (string $mask) use ($path): bool {
            $mask = ltrim($mask, '/');
            return fnmatch($mask, $path) || fnmatch($mask, basename($path)) || str_starts_with($path, rtrim($mask, '*/') . '/');
        };
        if ($include && !array_filter($include, $matches)) {
            return false;
        }
        return !array_filter($exclude, $matches);
    }
}
