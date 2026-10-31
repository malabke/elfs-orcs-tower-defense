<?php

namespace Tests;

use JumPHP\Testing\AppTestCase;

/** The app played without a window: the test is the page (AppTestCase, docs/tauri.md). */
final class MainTest extends AppTestCase
{
    public function testThePageIsGreetedByPhp(): void
    {
        $answer = $this->invoke('greet', ['name' => 'Ada']);
        self::assertStringStartsWith('Hello Ada from PHP', $answer['message']);
        self::assertSame(1, $answer['clicks']);
    }

    public function testTheClockReachesThePageEverySecond(): void
    {
        $this->wait(1.2);
        self::assertNotNull($this->lastSent('webview.emit'));
        self::assertSame('clock', $this->lastSent('webview.emit')['name']);
    }

    public function testClosingAfterUseAsksFirst(): void
    {
        $this->invoke('greet', ['name' => 'Ada']);
        $this->requestClose();
        self::assertSame([], $this->sent('window.close'));      // still open: a dialog asks
        $this->answerDialog(true);
        self::assertNotNull($this->lastSent('app.exit'));
    }
}
