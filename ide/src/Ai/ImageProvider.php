<?php

namespace JumPHP\Ide\Ai;

/**
 * A picture service the user has an API key for (ResourceGenerator: sprites made from its picture, Pixelize). The key
 * stays on the user's computer: the IDE keeps it in its own settings, never in the project, and passes it per request.
 */
interface ImageProvider
{
    public function name(): string;

    /** @return array{url: string, headers: list<string>, body: string} the HTTP POST that asks for one picture */
    public function request(string $prompt, string $key): array;

    /** The PNG (or any picture GD reads) out of the service's answer; an exception tells what the service said. */
    public function decode(string $response): string;
}
