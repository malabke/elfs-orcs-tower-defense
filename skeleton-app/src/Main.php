<?php

namespace App;

use JumPHP\Native\Dialog;
use JumPHP\Tauri\App;
use JumPHP\Tauri\AppMain;
use JumPHP\Tauri\NativeEvent;

/**
 * The app's PHP side: what its pages can call (jumphp.invoke), what PHP sends them (jumphp.on), and what
 * happens when a window wants to close.
 */
final class Main implements AppMain
{
    private int $clicks = 0;

    public function boot(App $app): void
    {
        // page -> PHP -> page: const r = await jumphp.invoke('greet', {name: 'Ada'})
        $app->command('greet', function (mixed $payload): array {
            $name = trim((string)(is_array($payload) ? ($payload['name'] ?? '') : ''));
            $this->clicks++;
            return ['message' => 'Hello ' . ($name === '' ? 'you' : $name) . ' from PHP ' . PHP_VERSION, 'clicks' => $this->clicks];
        });

        // PHP -> page, every second: jumphp.on('clock', ...)
        $app->gameLoop->addPeriodicTimer(1.0, fn () => $app->send('clock', ['time' => date('H:i:s')]));

        // closing the window asks first (PHP decides)
        $app->window()->on('close-requested', function (NativeEvent $e) use ($app): void {
            if ($this->clicks === 0) {
                return;                         // nothing to lose: it closes
            }
            $e->preventDefault();
            Dialog::confirm('Quit the app?', $app->config->title(), 'Quit', 'Stay')
                ->then(fn (mixed $yes) => $yes ? $app->quit() : null);
        });
    }
}
