<?php

namespace JumPHP\Ide\Ai;

/**
 * A Stable Diffusion server run on this computer (AUTOMATIC1111's web UI or SD.Next, started with --api: POST /sdapi/v1/txt2img):
 * pictures with no key and nothing sent out. The picture is then made pixel art like any other (Pixelize).
 */
final class LocalDiffusion implements ImageProvider
{
    public const ID = 'diffusion-local';
    public const URL = 'http://127.0.0.1:7860';

    public function name(): string
    {
        return 'Stable Diffusion server (local, AUTOMATIC1111 / SD.Next with --api)';
    }

    public function needsKey(): bool
    {
        return false;
    }

    public function baseUrl(): string
    {
        return self::URL;
    }

    public function request(string $prompt, string $key, ?string $baseUrl = null): array
    {
        $base = rtrim(trim((string)$baseUrl) !== '' ? trim((string)$baseUrl) : self::URL, '/');
        if (!preg_match('#^https?://[^\s"<>]+$#', $base)) {
            throw new \InvalidArgumentException('The server address must start with http:// or https://.');
        }
        return ['url' => "$base/sdapi/v1/txt2img", 'headers' => ['Content-Type: application/json'], 'body' => (string)json_encode([
            'prompt' => $prompt . ', pixel art, flat colours, clean outline',
            'negative_prompt' => 'blurry, photo, realistic, text, watermark, gradient background',
            'width' => 512, 'height' => 512, 'steps' => 20, 'cfg_scale' => 7, 'batch_size' => 1,
        ], JSON_UNESCAPED_SLASHES)];
    }

    public function decode(string $response): string
    {
        $data = json_decode($response, true);
        if (!is_array($data)) {
            throw new \RuntimeException('The Stable Diffusion server did not answer (' . substr(trim($response), 0, 200) . ')');
        }
        if (isset($data['detail']) || isset($data['error'])) {
            $why = $data['error'] ?? $data['detail'];
            throw new \RuntimeException('Stable Diffusion: ' . (is_array($why) ? json_encode($why) : (string)$why));
        }
        $b64 = $data['images'][0] ?? null;
        $png = is_string($b64) ? base64_decode(preg_replace('/^data:image\/\w+;base64,/', '', $b64) ?? '', true) : false;
        if ($png === false || $png === '') {
            throw new \RuntimeException('The Stable Diffusion server sent no picture.');
        }
        return $png;
    }
}
