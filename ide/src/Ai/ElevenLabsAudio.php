<?php

namespace JumPHP\Ide\Ai;

/**
 * ElevenLabs' sound effects (POST /v1/sound-generation: text, duration_seconds 0.5-30) and music (POST /v1/music: prompt,
 * music_length_ms 10 s - 5 min), mp3 back. https://elevenlabs.io/docs/api-reference
 */
final class ElevenLabsAudio implements AudioProvider
{
    public const ID = 'elevenlabs';
    public const URL = 'https://api.elevenlabs.io/v1';

    public function name(): string
    {
        return 'ElevenLabs sounds and music (your API key)';
    }

    public function kinds(): array
    {
        return ['sfx', 'music'];
    }

    public function request(string $kind, string $prompt, float $seconds, string $key): array
    {
        $headers = ['Content-Type: application/json', 'Accept: audio/mpeg', 'xi-api-key: ' . trim($key)];
        if ($kind === 'music') {
            $ms = (int)round(max(10.0, min(300.0, $seconds)) * 1000);
            return ['url' => self::URL . '/music?output_format=mp3_44100_128', 'headers' => $headers,
                'body' => (string)json_encode(['prompt' => $prompt, 'music_length_ms' => $ms], JSON_UNESCAPED_SLASHES)];
        }
        return ['url' => self::URL . '/sound-generation?output_format=mp3_44100_128', 'headers' => $headers,
            'body' => (string)json_encode(['text' => $prompt, 'duration_seconds' => round(max(0.5, min(30.0, $seconds)), 1), 'prompt_influence' => 0.4], JSON_UNESCAPED_SLASHES)];
    }

    public function decode(string $response): string
    {
        if ($response === '') {
            throw new \RuntimeException('ElevenLabs did not answer.');
        }
        if ($response[0] === '{') {
            $data = json_decode($response, true);
            $detail = is_array($data) ? ($data['detail'] ?? $data['error'] ?? $data) : $response;
            throw new \RuntimeException('ElevenLabs: ' . (is_array($detail) ? ($detail['message'] ?? $detail['status'] ?? json_encode($detail)) : (string)$detail));
        }
        // an mp3 starts with an ID3 tag or a frame header (0xFF 0xE0+), a wav with RIFF, an ogg with OggS
        if (!preg_match('/^(ID3|RIFF|OggS|\xFF[\xE0-\xFF])/s', $response)) {
            throw new \RuntimeException('ElevenLabs sent no audio (' . substr(trim(preg_replace('/[^\x20-\x7e]+/', ' ', substr($response, 0, 120)) ?? ''), 0, 100) . ')');
        }
        return $response;
    }
}
