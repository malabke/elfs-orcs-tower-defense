<?php

namespace App\Ui;

use JumPHP\Gl\WebGl;
use JumPHP\Renderer\Renderer;

/**
 * What the engine running the game can do. This project's engine draws the 2D at the window's resolution
 * (Renderer::hd(), game.yaml `hd`) and composes the 3D layer by the 2D's alpha (WebGl::setLayer(..., null)); the
 * shared engine of the playground (the official release of the repository's VERSION) may not yet: then the game
 * keeps the retro way (the pixel font, the 3D shown through a key colour, only black translucency over it).
 */
final class Hd
{
    /** Tests: behave as on an engine without these features. */
    public static bool $legacy = false;

    private static ?bool $hd = null;
    private static ?bool $alpha = null;

    /** The renderer can draw the 2D in HD. */
    public static function supported(): bool
    {
        return !self::$legacy && (self::$hd ??= (new \ReflectionClass(Renderer::class))->hasMethod('hd'));
    }

    /** How many texture pixels a view pixel has now (1 without HD). */
    public static function factor(Renderer $r): int
    {
        return self::supported() ? max(1, (int)$r->hd()) : 1;
    }

    /** WebGl can compose its layer by the 2D's alpha (no key colour). */
    public static function alphaLayers(): bool
    {
        if (self::$legacy) {
            return false;
        }
        if (self::$alpha === null) {
            $param = (new \ReflectionMethod(WebGl::class, 'setLayer'))->getParameters()[2] ?? null;
            self::$alpha = $param !== null && $param->allowsNull();
        }
        return self::$alpha;
    }
}
