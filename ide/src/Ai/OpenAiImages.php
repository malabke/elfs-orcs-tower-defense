<?php

namespace JumPHP\Ide\Ai;

/** OpenAI's Images API (https://platform.openai.com/docs/api-reference/images/create): one 1024 x 1024 picture, base64. */
final class OpenAiImages implements ImageProvider
{
    public const ID = 'openai-images';
    public const URL = 'https://api.openai.com/v1/images/generations';

    public function __construct(private string $model = 'gpt-image-1')
    {
    }

    public function name(): string
    {
        return 'OpenAI Images (your API key)';
    }

    public function request(string $prompt, string $key): array
    {
        return [
            'url' => self::URL,
            'headers' => ['Authorization: Bearer ' . $key, 'Content-Type: application/json'],
            'body' => (string)json_encode(['model' => $this->model, 'prompt' => $prompt, 'n' => 1, 'size' => '1024x1024',
                'background' => 'transparent', 'output_format' => 'png'], JSON_UNESCAPED_SLASHES),
        ];
    }

    public function decode(string $response): string
    {
        $data = json_decode($response, true);
        if (!is_array($data)) {
            throw new \RuntimeException('OpenAI Images did not answer (' . substr(trim($response), 0, 200) . ')');
        }
        if (isset($data['error'])) {
            throw new \RuntimeException('OpenAI Images: ' . ($data['error']['message'] ?? json_encode($data['error'])));
        }
        $b64 = $data['data'][0]['b64_json'] ?? null;
        $png = is_string($b64) ? base64_decode($b64, true) : false;
        if ($png === false || $png === '') {
            throw new \RuntimeException('OpenAI Images sent no picture.');
        }
        return $png;
    }
}
