<?php

namespace JumPHP\Particles;

use JumPHP\Extension\AssetLoader;

/**
 * Reads a .particles.json file: {"fire": {...emitter settings...}, "smoke": {...}} -> ['fire' => Emitter, ...]
 * ($engine->extensions()->load('assets/fx/torch.particles.json')).
 */
final class EmitterLoader implements AssetLoader
{
    /** @return array<string, Emitter> */
    public function load(string $file): array
    {
        $data = json_decode((string)@file_get_contents($file), true);
        if (!is_array($data)) {
            throw new \RuntimeException("$file: not a JSON object of emitters");
        }
        $emitters = [];
        foreach ($data as $name => $settings) {
            $emitters[(string)$name] = Emitter::fromArray((array)$settings);
        }
        return $emitters;
    }
}
