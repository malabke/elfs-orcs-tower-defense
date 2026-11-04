<?php

namespace JumPHP\Ide\Ai;

/**
 * The chat services "Generate…" can ask, all speaking OpenAI's chat completions API (POST <base>/chat/completions):
 * online ones with the user's key (OpenAI, Mistral, Groq, OpenRouter, Gemini) and local ones that need none, run on this
 * computer (Ollama, LM Studio, llama.cpp's server, anything else with a custom address). Nothing leaves the computer
 * with a local one. The answer is read as JSON wherever it sits in the text (models fence it, or talk around it).
 */
final class ChatModels
{
    /** id => [name, base address, local, needs a key, a model to start with] */
    public const PRESETS = [
        'ollama' => ['Ollama (local)', 'http://127.0.0.1:11434/v1', true, false, 'qwen2.5-coder'],
        'lmstudio' => ['LM Studio (local)', 'http://127.0.0.1:1234/v1', true, false, ''],
        'llamacpp' => ['llama.cpp server (local)', 'http://127.0.0.1:8080/v1', true, false, ''],
        'custom' => ['Another OpenAI-compatible server', 'http://127.0.0.1:8000/v1', true, false, ''],
        'openai' => ['OpenAI (your API key)', 'https://api.openai.com/v1', false, true, 'gpt-4o-mini'],
        'mistral' => ['Mistral (your API key)', 'https://api.mistral.ai/v1', false, true, 'mistral-large-latest'],
        'groq' => ['Groq (your API key)', 'https://api.groq.com/openai/v1', false, true, 'llama-3.3-70b-versatile'],
        'openrouter' => ['OpenRouter (your API key)', 'https://openrouter.ai/api/v1', false, true, 'anthropic/claude-sonnet-4.5'],
        'gemini' => ['Google Gemini (your API key)', 'https://generativelanguage.googleapis.com/v1beta/openai', false, true, 'gemini-2.0-flash'],
    ];

    public static function has(string $id): bool
    {
        return isset(self::PRESETS[$id]);
    }

    /**
     * @return array{url: string, headers: list<string>, body: string}
     */
    public static function request(string $preset, ?string $baseUrl, string $model, string $key, string $system, string $user): array
    {
        $base = rtrim(trim((string)$baseUrl) !== '' ? trim((string)$baseUrl) : self::PRESETS[$preset][1], '/');
        if (!preg_match('#^https?://[^\s"<>]+$#', $base)) {
            throw new \InvalidArgumentException('The server address must start with http:// or https://.');
        }
        $headers = ['Content-Type: application/json'];
        if (trim($key) !== '') {
            $headers[] = 'Authorization: Bearer ' . trim($key);
        }
        $model = trim($model) !== '' ? trim($model) : self::PRESETS[$preset][4];
        if ($model === '') {
            throw new \InvalidArgumentException('Which model? Name one (the list shows the ones this server has).');
        }
        return ['url' => "$base/chat/completions", 'headers' => $headers, 'body' => (string)json_encode([
            'model' => $model, 'temperature' => 0.7, 'stream' => false,
            'response_format' => ['type' => 'json_object'],
            'messages' => [['role' => 'system', 'content' => $system], ['role' => 'user', 'content' => $user]],
        ], JSON_UNESCAPED_SLASHES)];
    }

    /** The assistant's text out of the service's answer; an exception tells what the service said. */
    public static function decode(string $response): string
    {
        $data = json_decode($response, true);
        if (!is_array($data)) {
            throw new \RuntimeException('The model server did not answer (' . substr(trim($response), 0, 200) . ')');
        }
        if (isset($data['error'])) {
            $error = $data['error'];
            throw new \RuntimeException('The model server: ' . (is_array($error) ? ($error['message'] ?? json_encode($error)) : $error));
        }
        $text = $data['choices'][0]['message']['content'] ?? null;
        if (!is_string($text) || trim($text) === '') {
            throw new \RuntimeException('The model sent no answer.');
        }
        return $text;
    }

    /** The first JSON object in a text (inside a ```json fence or not), or null. */
    public static function extractJson(string $text): ?array
    {
        $text = (string)preg_replace('/\e\[[0-9;?]*[A-Za-z]/', '', $text);     // a terminal's colours
        $direct = json_decode(trim($text), true);
        if (is_array($direct)) {
            return $direct;
        }
        $length = strlen($text);
        for ($start = strpos($text, '{'); $start !== false && $start < $length; $start = strpos($text, '{', $start + 1)) {
            $depth = 0;
            $inString = false;
            for ($i = $start; $i < $length; $i++) {
                $c = $text[$i];
                if ($inString) {
                    if ($c === '\\') {
                        $i++;
                    } elseif ($c === '"') {
                        $inString = false;
                    }
                    continue;
                }
                if ($c === '"') {
                    $inString = true;
                } elseif ($c === '{') {
                    $depth++;
                } elseif ($c === '}' && --$depth === 0) {
                    $found = json_decode(substr($text, $start, $i - $start + 1), true);
                    if (is_array($found)) {
                        return $found;
                    }
                    break;
                }
            }
        }
        return null;
    }

    /** The model names out of GET <base>/models (OpenAI's list: data[].id; Ollama's own: models[].name). */
    public static function models(string $response): array
    {
        $data = json_decode($response, true);
        $names = [];
        foreach ((array)($data['data'] ?? []) as $m) {
            $names[] = (string)($m['id'] ?? '');
        }
        foreach ((array)($data['models'] ?? []) as $m) {
            $names[] = (string)($m['name'] ?? $m['model'] ?? '');
        }
        $names = array_values(array_unique(array_filter($names)));
        sort($names);
        return $names;
    }
}
