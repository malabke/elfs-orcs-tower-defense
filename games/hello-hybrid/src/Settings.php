<?php

namespace App;

/**
 * What the panel (HTML) changes and the game reads: plain static state, shared by Desktop (the native side) and
 * BallsScene (the game). Reset by the scene's enter().
 */
final class Settings
{
    public static int $wanted = 12;
    public static float $speed = 1.0;
    public static string $colour = '#ffcc33';
    public static bool $paused = false;
    /** set by the panel / the tray, done by the scene at its next update */
    public static bool $shotRequested = false;

    public static function reset(): void
    {
        self::$wanted = 12;
        self::$speed = 1.0;
        self::$colour = '#ffcc33';
        self::$paused = false;
        self::$shotRequested = false;
    }

    /** @return array<string, mixed> */
    public static function all(): array
    {
        return ['balls' => self::$wanted, 'speed' => self::$speed, 'colour' => self::$colour, 'paused' => self::$paused];
    }
}
