<?php

namespace JumPHP\Ide\Ai;

/**
 * A sound service the user has an API key for: a description in, a real sound out (a sound effect, a piece of music), as an audio file
 * the game plays as it is (mp3: SDL_mixer reads it). The key stays on the user's computer, passed per request, never in the project.
 */
interface AudioProvider
{
    public function name(): string;

    /** @return list<string> what it makes: 'sfx', 'music' */
    public function kinds(): array;

    /** @return array{url: string, headers: list<string>, body: string} the HTTP POST that asks for the sound; $seconds: how long */
    public function request(string $kind, string $prompt, float $seconds, string $key): array;

    /** The audio bytes out of the service's answer; an exception tells what the service said. */
    public function decode(string $response): string;
}
