<?php

namespace JumPHP\Native;

use JumPHP\Bridge\JsBridge;

/** The browser build: links open in a new tab, text to the clipboard (the page does them through JsBridge). */
final class BrowserProvider extends BaseProvider
{
    protected array $features = ['openUrl', 'copy'];

    public function __construct(private readonly JsBridge $bridge)
    {
    }

    public function name(): string
    {
        return 'browser';
    }

    public function copy(string $text): bool
    {
        return $this->bridge->send('device', ['do' => 'copy', 'text' => $text]);
    }

    public function openUrl(string $url): bool
    {
        return preg_match('#^https?://#i', $url) === 1 && $this->bridge->send('open', ['url' => $url]);
    }
}
