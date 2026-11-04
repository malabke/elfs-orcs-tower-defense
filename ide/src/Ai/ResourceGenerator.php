<?php

namespace JumPHP\Ide\Ai;

use JumPHP\Ide\Async\Deferred;
use JumPHP\Ide\Async\Processes;

/**
 * "Generate…" in the resource editors: a description in, what the editor shows out (a sprite's pixels, a sound effect's
 * settings, a tune's notes, a level's tiles and objects). Claude makes it through the `claude` command of this computer
 * (the user's own login, a one-shot `claude -p` with a JSON schema), or a picture service with the user's key
 * (ImageProvider, then Pixelize). Every answer is checked before the editor gets it: an invalid one is an error.
 */
final class ResourceGenerator
{
    public const KINDS = ['sprite', 'sfx', 'music', 'level'];
    public const WAVES = ['square', 'pulse', 'triangle', 'sawtooth', 'sine', 'noise'];
    /** the sound effect editor's settings: [min, max] (ide/ui/js/res-sound.js SFX_KEYS) */
    public const SFX = ['start' => [40, 4000], 'end' => [40, 4000], 'attack' => [0, 300], 'sustain' => [0, 1000], 'decay' => [0, 2000],
        'volume' => [0, 10], 'vibrato' => [0, 100], 'speed' => [0, 40], 'repeat' => [1, 8], 'gap' => [0, 300]];
    /** the music editor's pitches (MIDI), drums: 0 kick, 1 snare, 2 hi-hat, 3 crash */
    public const LOW = 24;
    public const HIGH = 96;
    /** level legend: what each character of a row is */
    public const LEVEL = ['.' => 0, '#' => 1, '=' => 2];
    public const LEVEL_OBJECTS = ['P' => 'start', 'C' => 'coin', 'E' => 'enemy'];

    /** @var callable(list<string>): Deferred runs a command, resolves {code, stdout, stderr} */
    private $run;

    /** @param array<string, ImageProvider> $images picture services by id */
    public function __construct(private Processes $processes, private string $root, ?callable $run = null, private array $images = [])
    {
        $this->run = $run ?? fn (array $command): Deferred => $this->processes->start($command, $this->root)['done'];
        $this->images += [OpenAiImages::ID => new OpenAiImages()];
    }

    /**
     * What "Generate…" can ask: Claude (this computer's claude command), OpenCode (its `opencode run`, with whatever model it
     * is set up for: local ones too), the chat services (ChatModels: online with a key, local without), the picture services.
     *
     * @return list<array{id: string, name: string, needsKey: bool, kind: string, local: bool, baseUrl: string, model: string}>
     */
    public function providers(): array
    {
        $list = [
            ['id' => 'claude', 'name' => 'Claude (this computer\'s claude command)', 'needsKey' => false, 'kind' => 'claude', 'local' => false, 'baseUrl' => '', 'model' => ''],
            ['id' => 'opencode', 'name' => 'OpenCode (this computer\'s opencode command, any model it has)', 'needsKey' => false, 'kind' => 'opencode', 'local' => false, 'baseUrl' => '', 'model' => ''],
        ];
        foreach (ChatModels::PRESETS as $id => [$name, $base, $local, $needsKey, $model]) {
            $list[] = ['id' => $id, 'name' => $name, 'needsKey' => $needsKey, 'kind' => 'chat', 'local' => $local, 'baseUrl' => $base, 'model' => $model];
        }
        foreach ($this->images as $id => $p) {
            $list[] = ['id' => $id, 'name' => $p->name(), 'needsKey' => true, 'kind' => 'image', 'local' => false, 'baseUrl' => '', 'model' => ''];
        }
        return $list;
    }

    /**
     * The models a provider has, to choose from: a chat server's GET <base>/models (Ollama, LM Studio...: nothing leaves the
     * computer), OpenCode's `opencode models`.
     *
     * @return Deferred resolved with a list of names
     */
    public function models(string $provider, ?string $baseUrl = null, string $key = ''): Deferred
    {
        try {
            if ($provider === 'opencode') {
                return ($this->run)(['opencode', 'models'])->then(fn (array $r) => array_values(array_filter(array_map('trim', explode("\n", (string)preg_replace('/\e\[[0-9;?]*[A-Za-z]/', '', (string)($r['stdout'] ?? '')))))));
            }
            if (!ChatModels::has($provider)) {
                return Deferred::resolved([]);
            }
            $base = rtrim(trim((string)$baseUrl) !== '' ? trim((string)$baseUrl) : ChatModels::PRESETS[$provider][1], '/');
            if (!preg_match('#^https?://[^\s"<>]+$#', $base)) {
                throw new \InvalidArgumentException('The server address must start with http:// or https://.');
            }
            $req = ['url' => "$base/models", 'headers' => trim($key) !== '' ? ['Authorization: Bearer ' . trim($key)] : [], 'body' => ''];
            return $this->post($req, 'GET', 8)->then(fn (string $out) => ChatModels::models($out));
        } catch (\Throwable $e) {
            return Deferred::rejected($e);
        }
    }

    /**
     * @param array<string, mixed> $options sprite: width, height, frames; music: bars; level: width, height
     */
    public function generate(string $kind, string $prompt, array $options = [], string $provider = 'claude', string $key = '', ?string $model = null, ?string $baseUrl = null): Deferred
    {
        try {
            if (!in_array($kind, self::KINDS, true)) {
                throw new \InvalidArgumentException("Nothing to generate for '$kind'.");
            }
            $prompt = trim($prompt);
            if ($prompt === '') {
                throw new \InvalidArgumentException('Describe what to make first.');
            }
            $options = $this->options($kind, $options);
            if ($provider === 'opencode' || ChatModels::has($provider)) {
                return $this->text($provider, $kind, $prompt, $options, $key, $model, $baseUrl);
            }
            if ($provider !== 'claude') {
                return $this->image($provider, $kind, $prompt, $options, $key);
            }
            $command = ['claude', '-p', $this->prompt($kind, $prompt, $options), '--output-format', 'json',
                '--json-schema', json_encode($this->schema($kind, $options), JSON_UNESCAPED_SLASHES), '--tools', '',
                '--no-session-persistence', '--strict-mcp-config', '--model', $model ?: 'sonnet'];
        } catch (\Throwable $e) {
            return Deferred::rejected($e);
        }
        return ($this->run)($command)->then(function (array $r) use ($kind, $options) {
            $out = json_decode((string)($r['stdout'] ?? ''), true);
            if (!is_array($out)) {
                throw new \RuntimeException('Claude did not answer: ' . trim((string)($r['stderr'] ?? '') ?: (string)($r['stdout'] ?? '')));
            }
            if (!empty($out['is_error'])) {
                throw new \RuntimeException('Claude: ' . ($out['result'] ?? 'an error'));
            }
            $data = $out['structured_output'] ?? json_decode((string)($out['result'] ?? ''), true);
            if (!is_array($data)) {
                throw new \RuntimeException('Claude\'s answer is not the JSON asked for.');
            }
            return $this->validate($kind, $data, $options);
        });
    }

    /** @return array<string, int> */
    public function options(string $kind, array $o): array
    {
        $int = fn (string $k, int $default, int $min, int $max): int => max($min, min($max, (int)($o[$k] ?? $default)));
        return match ($kind) {
            'sprite' => ['width' => $int('width', 16, 1, 64), 'height' => $int('height', 16, 1, 64), 'frames' => $int('frames', 1, 1, 8)],
            'music' => ['bars' => $int('bars', 4, 1, 16)],
            'level' => ['width' => $int('width', 40, 4, 200), 'height' => $int('height', 12, 4, 60)],
            default => [],
        };
    }

    public function prompt(string $kind, string $what, array $o): string
    {
        $ask = match ($kind) {
            'sprite' => "Pixel art for a retro 2D game: {$o['frames']} frame(s) of {$o['width']} x {$o['height']} pixels (an animation when "
                . "there are several: the frames in order). 'palette' maps single characters (not '.') to colours #rrggbb, at most 16; "
                . "each frame is exactly {$o['height']} strings of exactly {$o['width']} characters; '.' is a transparent pixel. "
                . 'Clear silhouette, a dark outline, few colours.',
            'sfx' => 'A retro game sound effect made by a simple synthesizer: wave (' . implode(', ', self::WAVES) . '), a pitch going from '
                . "'start' Hz to 'end' Hz, an envelope attack / sustain / decay in ms, volume 0-10, vibrato depth 0-100 and speed in Hz, "
                . "'repeat' times with 'gap' ms between. Ranges: " . json_encode(self::SFX) . '.',
            'music' => "A short looping chiptune of {$o['bars']} bar(s), 16 steps a bar, for 4 voices in this order: lead (square), second "
                . '(pulse), bass (triangle), drums (noise). Each note is [step, pitch, length in steps]: step from 0 to ' . ($o['bars'] * 16 - 1)
                . ', pitch a MIDI number ' . self::LOW . '-' . self::HIGH . ' for the first three voices, and for drums 0 kick, 1 snare, '
                . '2 hi-hat, 3 crash. A voice plays one note at a time. tempo 60-200, each voice a volume 0-10.',
            'level' => "A platformer level of {$o['width']} x {$o['height']} tiles, as exactly {$o['height']} rows of exactly {$o['width']} "
                . "characters: '.' empty, '#' solid ground or wall, '=' a platform one can jump through from below, 'P' the player's start "
                . "(exactly one, standing just above ground), 'C' a coin, 'E' an enemy (standing on ground). The bottom row is ground "
                . 'except for pits; every jump must be possible (gaps of at most 3 tiles, steps of at most 3 tiles up).',
            default => throw new \InvalidArgumentException("Unknown resource kind: $kind"),
        };
        return "$ask\nWhat to make: $what\nAnswer with the JSON only.";
    }

    /** @return array<string, mixed> the exact sizes in it too: Claude's structured output keeps to them */
    public function schema(string $kind, array $o = []): array
    {
        $int = ['type' => 'integer'];
        $rows = fn (int $w, int $h) => ['type' => 'array', 'minItems' => $h, 'maxItems' => $h,
            'items' => ['type' => 'string', 'minLength' => $w, 'maxLength' => $w]];
        return match ($kind) {
            'sprite' => ['type' => 'object', 'required' => ['palette', 'frames'], 'properties' => [
                'palette' => ['type' => 'object', 'additionalProperties' => ['type' => 'string', 'pattern' => '^#[0-9a-fA-F]{6}$']],
                'frames' => ['type' => 'array', 'minItems' => $o['frames'] ?? 1, 'maxItems' => $o['frames'] ?? 8,
                    'items' => $rows($o['width'] ?? 16, $o['height'] ?? 16)]]],
            'sfx' => ['type' => 'object', 'required' => ['wave', ...array_keys(self::SFX)],
                'properties' => ['wave' => ['type' => 'string', 'enum' => self::WAVES]] + array_map(fn () => $int, self::SFX)],
            'music' => ['type' => 'object', 'required' => ['tempo', 'voices'], 'properties' => [
                'tempo' => $int,
                'voices' => ['type' => 'array', 'minItems' => 4, 'maxItems' => 4, 'items' => ['type' => 'object', 'required' => ['notes'], 'properties' => [
                    'volume' => $int, 'notes' => ['type' => 'array', 'items' => ['type' => 'array', 'items' => $int]]]]]]],
            'level' => ['type' => 'object', 'required' => ['rows'], 'properties' => ['rows' => $rows($o['width'] ?? 40, $o['height'] ?? 12)]],
            default => throw new \InvalidArgumentException("Unknown resource kind: $kind"),
        };
    }

    /**
     * The answer checked and put in the editors' shape; an exception says what is wrong.
     *
     * @param array<string, mixed> $d
     * @return array<string, mixed>
     */
    public function validate(string $kind, array $d, array $o): array
    {
        $fail = fn (string $why) => throw new \UnexpectedValueException("The generated $kind is not usable: $why.");
        switch ($kind) {
            case 'sprite':
                $palette = [];
                foreach ((array)($d['palette'] ?? []) as $char => $colour) {
                    $char = (string)$char;
                    if (mb_strlen($char) !== 1 || $char === '.' || !preg_match('/^#[0-9a-f]{6}$/i', (string)$colour)) {
                        $fail("palette entry '$char' => '$colour'");
                    }
                    $palette[$char] = strtolower((string)$colour);
                }
                if (!$palette || count($palette) > 16) {
                    $fail('a palette of 1 to 16 colours is needed');
                }
                $frames = array_values((array)($d['frames'] ?? []));
                if (count($frames) !== $o['frames']) {
                    $fail(count($frames) . " frame(s) instead of {$o['frames']}");
                }
                foreach ($frames as $i => $rows) {
                    $rows = array_values((array)$rows);
                    if (count($rows) !== $o['height']) {
                        $fail('frame ' . ($i + 1) . ' has ' . count($rows) . " rows instead of {$o['height']}");
                    }
                    foreach ($rows as $y => $row) {
                        $chars = mb_str_split((string)$row);
                        if (count($chars) !== $o['width']) {
                            $fail('frame ' . ($i + 1) . ', row ' . ($y + 1) . ' is ' . count($chars) . " pixels wide instead of {$o['width']}");
                        }
                        foreach ($chars as $c) {
                            if ($c !== '.' && !isset($palette[$c])) {
                                $fail("the colour '$c' is not in the palette");
                            }
                        }
                    }
                    $frames[$i] = array_map('strval', $rows);
                }
                return ['width' => $o['width'], 'height' => $o['height'], 'palette' => $palette, 'frames' => $frames];
            case 'sfx':
                if (!in_array($d['wave'] ?? null, self::WAVES, true)) {
                    $fail("the wave '" . json_encode($d['wave'] ?? null) . "'");
                }
                $out = ['wave' => $d['wave']];
                foreach (self::SFX as $k => [$min, $max]) {
                    if (!is_numeric($d[$k] ?? null) || $d[$k] < $min || $d[$k] > $max) {
                        $fail("$k must be between $min and $max");
                    }
                    $out[$k] = (int)round($d[$k]);
                }
                if ($out['attack'] + $out['sustain'] + $out['decay'] === 0) {
                    $fail('it lasts no time');
                }
                return $out;
            case 'music':
                $steps = $o['bars'] * 16;
                $tempo = $d['tempo'] ?? null;
                if (!is_numeric($tempo) || $tempo < 40 || $tempo > 300) {
                    $fail('the tempo must be between 40 and 300');
                }
                $voices = array_values((array)($d['voices'] ?? []));
                if (count($voices) !== 4) {
                    $fail(count($voices) . ' voices instead of 4');
                }
                $notes = 0;
                foreach ($voices as $v => $voice) {
                    $list = [];
                    foreach ((array)($voice['notes'] ?? []) as $n) {
                        [$step, $pitch, $length] = array_map('intval', array_pad(array_values((array)$n), 3, 1));
                        [$low, $high] = $v === 3 ? [0, 3] : [self::LOW, self::HIGH];
                        if ($step < 0 || $step >= $steps || $length < 1 || $pitch < $low || $pitch > $high) {
                            $fail('voice ' . ($v + 1) . ' has the note ' . json_encode($n) . " (steps 0-" . ($steps - 1) . ", pitches $low-$high)");
                        }
                        $list[] = [$step, $pitch, min($length, $steps - $step)];
                    }
                    usort($list, fn ($a, $b) => $a[0] <=> $b[0]);
                    $notes += count($list);
                    $voices[$v] = ['volume' => max(0, min(10, (int)($voice['volume'] ?? 7))), 'notes' => $list];
                }
                if ($notes === 0) {
                    $fail('it has no note');
                }
                return ['tempo' => (int)$tempo, 'bars' => $o['bars'], 'voices' => $voices];
            case 'level':
                $rows = array_values((array)($d['rows'] ?? []));
                if (count($rows) !== $o['height']) {
                    $fail(count($rows) . " rows instead of {$o['height']}");
                }
                $tiles = [];
                $objects = [];
                foreach ($rows as $y => $row) {
                    $chars = str_split((string)$row);
                    if (count($chars) !== $o['width']) {
                        $fail('row ' . ($y + 1) . ' is ' . count($chars) . " tiles wide instead of {$o['width']}");
                    }
                    foreach ($chars as $x => $c) {
                        if (isset(self::LEVEL_OBJECTS[$c])) {
                            $objects[] = ['type' => self::LEVEL_OBJECTS[$c], 'x' => $x, 'y' => $y];
                            $c = '.';
                        }
                        if (!isset(self::LEVEL[$c])) {
                            $fail("the character '$c' on row " . ($y + 1));
                        }
                        $tiles[] = self::LEVEL[$c];
                    }
                }
                if (count(array_filter($objects, fn ($ob) => $ob['type'] === 'start')) !== 1) {
                    $fail('it needs exactly one start (P)');
                }
                return ['width' => $o['width'], 'height' => $o['height'], 'tiles' => $tiles, 'objects' => $objects];
        }
        $fail('unknown kind');
    }

    /** A picture service, then the picture made pixel art of the sprite's size (sprites only). */
    private function image(string $provider, string $kind, string $prompt, array $o, string $key): Deferred
    {
        $service = $this->images[$provider] ?? null;
        if ($service === null) {
            throw new \InvalidArgumentException("No picture service '$provider'.");
        }
        if ($kind !== 'sprite') {
            throw new \InvalidArgumentException($service->name() . ' makes pictures: sprites only.');
        }
        if (trim($key) === '') {
            throw new \InvalidArgumentException($service->name() . ' needs your API key (kept in the IDE on this computer).');
        }
        $req = $service->request("Pixel art sprite for a retro 2D game, plain transparent or single-colour background, centred: $prompt", trim($key));
        return $this->post($req)->then(function (string $out) use ($service, $o) {
            $png = $service->decode($out);
            return $this->validate('sprite', Pixelize::sprite($png, $o['width'], $o['height']), ['frames' => 1] + $o);
        });
    }

    /**
     * A text model (OpenCode, or a chat service: online with a key, local without) makes it: the prompt carries the JSON schema
     * since these have no structured output, the JSON is found in the answer, and an answer the editor cannot use is sent back
     * once with what is wrong (small local models often need that second try).
     */
    private function text(string $provider, string $kind, string $prompt, array $o, string $key, ?string $model, ?string $baseUrl): Deferred
    {
        $schema = json_encode($this->schema($kind, $o), JSON_UNESCAPED_SLASHES);
        $ask = $this->prompt($kind, $prompt, $o) . "\nThe JSON must follow this JSON schema exactly: $schema";
        $system = 'You make assets for a retro 2D game. You answer with one JSON object and nothing else.';
        if (ChatModels::has($provider) && ChatModels::PRESETS[$provider][3] && trim($key) === '') {
            throw new \InvalidArgumentException(ChatModels::PRESETS[$provider][0] . ' needs your API key (kept in the IDE on this computer).');
        }
        $send = function (string $user) use ($provider, $model, $key, $baseUrl, $system): Deferred {
            if ($provider === 'opencode') {
                $command = ['opencode', 'run', "$system\n\n$user"];
                if ($model !== null && trim($model) !== '') {
                    array_push($command, '-m', trim($model));
                }
                return ($this->run)($command)->then(function (array $r) {
                    $out = (string)($r['stdout'] ?? '');
                    if (($r['code'] ?? 0) !== 0 && trim($out) === '') {
                        throw new \RuntimeException('OpenCode: ' . trim((string)($r['stderr'] ?? 'it did not answer')));
                    }
                    return $out;
                });
            }
            return $this->post(ChatModels::request($provider, $baseUrl, (string)$model, $key, $system, $user))->then(fn (string $out) => ChatModels::decode($out));
        };
        $parse = function (string $text) use ($kind, $o): array {
            $data = ChatModels::extractJson($text);
            if ($data === null) {
                throw new \UnexpectedValueException('there is no JSON in the answer');
            }
            return $this->validate($kind, $data, $o);
        };
        return $send($ask)->then(function (string $text) use ($parse, $send, $ask) {
            try {
                return $parse($text);
            } catch (\UnexpectedValueException $e) {
                // once more, told what was wrong
                return $send("$ask\nYour previous answer was refused: " . $e->getMessage() . ' Answer again, with the JSON only.')
                    ->then($parse);
            }
        });
    }

    /**
     * A request through curl; the key goes in a file, not on the command line (other users of the computer see command lines).
     *
     * @param array{url: string, headers: list<string>, body: string} $req
     * @return Deferred resolved with the answer's body
     */
    private function post(array $req, string $method = 'POST', int $timeout = 180): Deferred
    {
        $headers = tempnam(sys_get_temp_dir(), 'jumphp-ai');
        $body = tempnam(sys_get_temp_dir(), 'jumphp-ai');
        file_put_contents($headers, implode("\n", $req['headers']) . "\n");
        file_put_contents($body, $req['body']);
        $clean = function () use ($headers, $body): void {
            @unlink($headers);
            @unlink($body);
        };
        $command = ['curl', '-sS', '--max-time', (string)$timeout, '-X', $method, '-H', "@$headers"];
        if ($method === 'POST') {
            array_push($command, '--data-binary', "@$body");
        }
        $command[] = $req['url'];
        return ($this->run)($command)->then(function (array $r) use ($clean): string {
            $clean();
            if (($r['code'] ?? 0) !== 0 && trim((string)($r['stdout'] ?? '')) === '') {
                throw new \RuntimeException('The server cannot be reached: ' . trim((string)($r['stderr'] ?? '')));
            }
            return (string)($r['stdout'] ?? '');
        }, function (\Throwable $e) use ($clean) {
            $clean();
            throw $e;
        });
    }
}
