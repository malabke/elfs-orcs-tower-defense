<?php

namespace App;

use JumPHP\Kernel\Engine;
use JumPHP\Tauri\App;
use JumPHP\Tauri\AppMain;

/**
 * The native side of Hello Hybrid (game.yaml main, run because ui: hybrid): what the HTML panel can do to the game,
 * a tray icon with a menu, a global shortcut. The game itself is App\Scene\BallsScene, as in any game.
 */
final class Desktop implements AppMain
{
    public function boot(App $app): void
    {
        $app->command('settings', fn (): array => Settings::all());
        $app->command('set', function (mixed $p): array {
            $p = is_array($p) ? $p : [];
            if (isset($p['balls'])) {
                Settings::$wanted = max(1, min(200, (int)$p['balls']));
            }
            if (isset($p['speed'])) {
                Settings::$speed = max(0.1, min(4.0, (float)$p['speed']));
            }
            if (isset($p['colour']) && preg_match('/^#[0-9a-f]{6}$/i', (string)$p['colour'])) {
                Settings::$colour = strtolower((string)$p['colour']);
            }
            if (isset($p['paused'])) {
                Settings::$paused = (bool)$p['paused'];
            }
            return Settings::all();
        });
        $app->command('shot', function (): bool {
            Settings::$shotRequested = true;
            return true;
        });

        $tray = $app->tray('main', ['tooltip' => 'Hello Hybrid', 'menu' => [
            ['id' => 'pause', 'text' => 'Pause / resume'],
            ['id' => 'shot', 'text' => 'Save a picture…'],
            '-',
            ['id' => 'quit', 'text' => 'Quit'],
        ]]);
        $tray->on('menu', function (string $item) use ($app): void {
            match ($item) {
                'pause' => $this->togglePause($app),
                'shot' => Settings::$shotRequested = true,
                'quit' => Engine::get()->quit(),
                default => null,
            };
        });

        // anywhere in the system, even when the game has not the focus
        $app->globalShortcut('CmdOrCtrl+Shift+P', fn () => $this->togglePause($app));
    }

    private function togglePause(App $app): void
    {
        Settings::$paused = !Settings::$paused;
        $app->send('settings', Settings::all());
    }
}
