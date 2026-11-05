<?php

namespace JumPHP\Effects;

use JumPHP\Extension\Extension;
use JumPHP\Kernel\Engine;

/**
 * The extension's boot class (composer.json extra.jumphp.boot): made by the container, boot() runs once the engine
 * is ready and before the first scene. Here: the flash gets its project.yaml settings.
 */
final class Effects implements Extension
{
    public function __construct(private Flash $flash)
    {
    }

    public function boot(Engine $engine): void
    {
        $this->flash->configure((array)$engine->config->get('effects', []));
    }
}
