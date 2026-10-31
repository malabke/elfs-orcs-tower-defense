<?php

namespace Tests;

use App\Settings;
use JumPHP\Testing\AppTestCase;

/** The native side (App\Desktop) without a window: the HTML panel's commands, the tray menu. */
final class DesktopTest extends AppTestCase
{
    protected function setUp(): void
    {
        Settings::reset();
        parent::setUp();
    }

    public function testThePanelOpensBesideTheGame(): void
    {
        $window = $this->runtime->sentOf('window.create')[0];
        self::assertSame('index.html', $window['url']);
        self::assertSame(340, $window['width']);
    }

    public function testThePanelChangesTheGame(): void
    {
        $answer = $this->invoke('set', ['balls' => 40, 'speed' => 2.5, 'colour' => '#33CCFF']);
        self::assertSame(['balls' => 40, 'speed' => 2.5, 'colour' => '#33ccff', 'paused' => false], $answer);
        self::assertSame(1, $this->invoke('set', ['balls' => -5])['balls']);
        self::assertSame('#33ccff', $this->invoke('set', ['colour' => 'red'])['colour']);
    }

    public function testTheTrayMenuPausesAndAsksForAPicture(): void
    {
        $tray = $this->lastSent('tray.create');
        self::assertSame(['pause', 'shot', null, 'quit'], array_map(static fn (array $i) => $i['id'] ?? null, $tray['menu']));
        $this->runtime->push(['type' => 'tray', 'tray' => 'main', 'event' => 'menu', 'item' => 'pause']);
        $this->frames(1);
        self::assertTrue(Settings::$paused);
        $this->runtime->push(['type' => 'tray', 'tray' => 'main', 'event' => 'menu', 'item' => 'shot']);
        $this->frames(1);
        self::assertTrue(Settings::$shotRequested);
    }

    public function testTheGlobalShortcutTogglesThePause(): void
    {
        self::assertSame('CmdOrCtrl+Shift+P', $this->lastSent('shortcut.register')['accelerator']);
        $this->runtime->push(['type' => 'shortcut', 'accelerator' => 'CmdOrCtrl+Shift+P', 'state' => 'pressed']);
        $this->frames(1);
        self::assertTrue(Settings::$paused);
        self::assertSame('settings', $this->lastSent('webview.emit')['name']);
    }
}
