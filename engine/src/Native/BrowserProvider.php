<?php

namespace JumPHP\Native;

use JumPHP\Bridge\JsBridge;

/** The browser build: links open in a new tab (the page does it, JsBridge 'open'). */
final class BrowserProvider extends BaseProvider
{
    protected array $features = ['openUrl'];

    public function __construct(private readonly JsBridge $bridge)
    {
    }

    public function name(): string
    {
        return 'browser';
    }

    public function openUrl(string $url): bool
    {
        return preg_match('#^https?://#i', $url) === 1 && $this->bridge->send('open', ['url' => $url]);
    }
}
