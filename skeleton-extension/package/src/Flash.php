<?php

namespace JumphpExtensions\SkeletonExtension;

use JumPHP\Renderer\Renderer;
use JumPHP\Util\Color;

/**
 * A service of the extension (composer.json extra.jumphp.services): a scene asks for it in its constructor.
 * flash() lights the whole screen, which fades; update($dt) in update(), render($r) last in render().
 */
final class Flash
{
    private Color $color;
    private float $seconds = 0.3;
    private float $left = 0;

    public function __construct()
    {
        $this->color = Color::white();
    }

    /** @param array<string, mixed> $settings project.yaml skeleton_extension (the extension's defaults under it) */
    public function configure(array $settings): void
    {
        $this->color = Color::of($settings['color'] ?? '#ffffff');
        $this->seconds = max(0.01, (float)($settings['seconds'] ?? 0.3));
    }

    public function flash(?string $color = null): void
    {
        if ($color !== null) {
            $this->color = Color::of($color);
        }
        $this->left = $this->seconds;
    }

    public function update(float $dt): void
    {
        $this->left = max(0, $this->left - $dt);
    }

    /** 0 (nothing) .. 1 (just flashed) */
    public function strength(): float
    {
        return $this->left / $this->seconds;
    }

    public function render(Renderer $r): void
    {
        $alpha = (int)round(200 * $this->strength() / 16) * 16;     // 13 shades: few cached textures
        if ($alpha > 0) {
            $r->fillRect(0, 0, $r->getWidth(), $r->getHeight(), $this->color->withAlpha($alpha));
        }
    }
}
