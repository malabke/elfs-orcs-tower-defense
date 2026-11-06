<?php

namespace JumPHP\Ide\Ai;

/**
 * A picture service the user has an API key for (ResourceGenerator: sprites made from its picture, Pixelize). The key
 * stays on the user's computer: the IDE keeps it in its own settings, never in the project, and passes it per request.
 */
interface ImageProvider
{
    public function name(): string;

    /** Whether the service wants the user's API key (a local one does not). */
    public function needsKey(): bool;

    /** The address a service run on this computer listens on by default ('' for an online one). */
    public function baseUrl(): string;

    /** @return array{url: string, headers: list<string>, body: string} the HTTP POST that asks for one picture; $baseUrl: the server's address when it can be chosen */
    public function request(string $prompt, string $key, ?string $baseUrl = null): array;

    /** The PNG (or any picture GD reads) out of the service's answer; an exception tells what the service said. */
    public function decode(string $response): string;
}
