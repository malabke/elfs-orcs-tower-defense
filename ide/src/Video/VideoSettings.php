<?php

namespace JumPHP\Ide\Video;

use JumPHP\Ide\Workspace\Workspace;
use Symfony\Component\Yaml\Yaml;

/**
 * The video settings of the project's games (config/project.yaml `video:`, docs/video-maker.md): which games make videos, with
 * which scene, parameters, length and templates. The IDE's Video Preview lists them and starts the scene live.
 */
final class VideoSettings
{
    public function __construct(private Workspace $workspace)
    {
    }

    /**
     * @param list<array{id: string, dir: string}> $modules
     * @return list<array<string, mixed>> the games with a `video:` block
     */
    public function all(array $modules): array
    {
        $out = [];
        foreach ($modules as $m) {
            $settings = $this->of((string)$m['id'], (string)$m['dir']);
            if ($settings !== null) {
                $out[] = $settings;
            }
        }
        return $out;
    }

    /** @return array<string, mixed>|null */
    public function of(string $id, string $dir): ?array
    {
        $prefix = $dir === '' ? '' : "$dir/";
        foreach (['config/project.yaml', 'config/game.yaml'] as $file) {
            if (!$this->workspace->exists($prefix . $file)) {
                continue;
            }
            try {
                $yaml = Yaml::parse($this->workspace->read($prefix . $file));
            } catch (\Throwable) {
                return null;
            }
            $root = is_array($yaml['project'] ?? null) ? $yaml['project'] : (is_array($yaml['game'] ?? null) ? $yaml['game'] : (array)$yaml);
            $v = $root['video'] ?? null;
            if (!is_array($v)) {
                continue;
            }
            $params = is_array($v['params'] ?? null) ? $v['params'] : [];
            return [
                'id' => $id,
                'title' => (string)($root['title'] ?? $id),
                'scene' => (string)($v['scene'] ?? $root['start_scene'] ?? ''),
                'params' => $params,
                'templates' => array_values(array_map('strval', (array)($v['templates'] ?? (isset($params['template']) ? [$params['template']] : [])))),
                'seconds' => (float)($v['seconds'] ?? 30),
                'scale' => (int)($v['scale'] ?? 4),
                'width' => (int)($root['width'] ?? 320),
                'height' => (int)($root['height'] ?? 180),
                'captions' => array_values((array)($v['captions'] ?? [])),
            ];
        }
        return null;
    }
}
