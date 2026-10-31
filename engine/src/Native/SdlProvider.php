<?php

namespace JumPHP\Native;

use JumPHP\Async\Task;

/**
 * Games on the desktop (SDL): SDL's own message box (modal: the game waits while it is open, like any system
 * message box). The rest needs a desktop app (Tauri).
 */
final class SdlProvider extends BaseProvider
{
    protected array $features = ['message'];

    public function name(): string
    {
        return 'sdl';
    }

    public static function isAvailable(): bool
    {
        return function_exists('SDL_ShowSimpleMessageBox');
    }

    public function message(string $message, string $title, string $kind): Task
    {
        $flags = match ($kind) {
            'error' => \SDL_MESSAGEBOX_ERROR,
            'warning' => \SDL_MESSAGEBOX_WARNING,
            default => \SDL_MESSAGEBOX_INFORMATION,
        };
        $window = null;
        try {
            $window = \JumPHP\Kernel\Engine::get()->sdl->getWindow()?->getWindow();
        } catch (\LogicException) {
        }
        $ok = \SDL_ShowSimpleMessageBox($flags, $title, $message, $window instanceof \SDL_Window ? $window : null) === 0;
        return self::done($ok);
    }
}
