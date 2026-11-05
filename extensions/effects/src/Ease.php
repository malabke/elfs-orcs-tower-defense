<?php

namespace JumPHP\Effects;

/** Easing curves: each takes t in 0..1 (the share of the time gone) and gives the share of the way done (it may leave 0..1 for back / elastic). */
final class Ease
{
    public const NAMES = ['linear', 'inQuad', 'outQuad', 'inOutQuad', 'inCubic', 'outCubic', 'inOutCubic', 'outBack', 'outBounce', 'outElastic'];

    public static function linear(float $t): float
    {
        return self::clamp($t);
    }

    public static function inQuad(float $t): float
    {
        $t = self::clamp($t);
        return $t * $t;
    }

    public static function outQuad(float $t): float
    {
        $t = self::clamp($t);
        return 1 - (1 - $t) * (1 - $t);
    }

    public static function inOutQuad(float $t): float
    {
        $t = self::clamp($t);
        return $t < 0.5 ? 2 * $t * $t : 1 - ((-2 * $t + 2) ** 2) / 2;
    }

    public static function inCubic(float $t): float
    {
        $t = self::clamp($t);
        return $t * $t * $t;
    }

    public static function outCubic(float $t): float
    {
        $t = self::clamp($t);
        return 1 - (1 - $t) ** 3;
    }

    public static function inOutCubic(float $t): float
    {
        $t = self::clamp($t);
        return $t < 0.5 ? 4 * $t * $t * $t : 1 - ((-2 * $t + 2) ** 3) / 2;
    }

    /** goes a little past 1, then settles */
    public static function outBack(float $t): float
    {
        $t = self::clamp($t);
        $c1 = 1.70158;
        return 1 + ($c1 + 1) * (($t - 1) ** 3) + $c1 * (($t - 1) ** 2);
    }

    public static function outBounce(float $t): float
    {
        $t = self::clamp($t);
        $n = 7.5625;
        $d = 2.75;
        if ($t < 1 / $d) {
            return $n * $t * $t;
        }
        if ($t < 2 / $d) {
            $t -= 1.5 / $d;
            return $n * $t * $t + 0.75;
        }
        if ($t < 2.5 / $d) {
            $t -= 2.25 / $d;
            return $n * $t * $t + 0.9375;
        }
        $t -= 2.625 / $d;
        return $n * $t * $t + 0.984375;
    }

    public static function outElastic(float $t): float
    {
        $t = self::clamp($t);
        if ($t <= 0 || $t >= 1) {
            return $t;
        }
        return (2 ** (-10 * $t)) * sin(($t * 10 - 0.75) * (2 * M_PI / 3)) + 1;
    }

    /** @return callable(float): float the curve called $name (one of NAMES) */
    public static function get(string $name): callable
    {
        if (!in_array($name, self::NAMES, true)) {
            throw new \InvalidArgumentException("Unknown easing '$name' (" . implode(', ', self::NAMES) . ')');
        }
        return [self::class, $name];
    }

    public static function lerp(float $from, float $to, float $t): float
    {
        return $from + ($to - $from) * $t;
    }

    private static function clamp(float $t): float
    {
        return max(0.0, min(1.0, $t));
    }
}
