<?php

namespace JumPHP\Ide\Extension;

use JumPHP\Ide\Workspace\Workspace;

/**
 * The engine extensions added to the project's games (`bin/jumphp extension:add`): each left games/<id>/extensions/<name>/extension.json
 * (tools/extension-adopt.php) with its documentation (doc/index.md) and its samples (src/Extensions/<Name>/). The IDE lists them and
 * shows the documentation with a Play button for each sample.
 */
final class InstalledExtensions
{
    public function __construct(private Workspace $workspace)
    {
    }

    /** @return list<array<string, mixed>> */
    public function all(): array
    {
        $out = [];
        foreach ($this->workspace->files() as $path => $meta) {
            if (!preg_match('#^((?:[^/]+/)*?)extensions/([a-z0-9][a-z0-9_.-]*)/extension\.json$#', (string)$path, $m)) {
                continue;
            }
            $data = json_decode($this->workspace->read((string)$path), true);
            if (!is_array($data) || !is_string($data['package'] ?? null)) {
                continue;
            }
            $game = rtrim($m[1], '/');
            $out[] = [
                'package' => $data['package'], 'name' => (string)($data['name'] ?? $m[2]), 'version' => (string)($data['version'] ?? ''),
                'description' => (string)($data['description'] ?? ''), 'game' => $game, 'gameId' => basename($game) ?: basename((string)$this->workspace->name()),
                'folder' => "{$m[1]}extensions/{$m[2]}", 'hasDoc' => is_string($data['doc'] ?? null),
                'samples' => array_values(array_map(fn ($s) => ['class' => (string)($s['class'] ?? ''), 'label' => (string)($s['label'] ?? ''), 'file' => (string)($s['file'] ?? '')], (array)($data['samples'] ?? []))),
            ];
        }
        usort($out, fn ($a, $b) => [$a['package'], $a['game']] <=> [$b['package'], $b['game']]);
        return $out;
    }

    /**
     * The documentation of an installed extension: its markdown, and what to play.
     *
     * @return array<string, mixed>|null
     */
    public function docs(string $package, ?string $game = null): ?array
    {
        foreach ($this->all() as $e) {
            if ($e['package'] !== $package || ($game !== null && $e['game'] !== $game)) {
                continue;
            }
            $file = $e['folder'] . '/doc/index.md';
            $markdown = $e['hasDoc'] && $this->workspace->exists($file) ? $this->workspace->read($file) : '';
            return $e + ['markdown' => $markdown, 'docFile' => $file];
        }
        return null;
    }
}
