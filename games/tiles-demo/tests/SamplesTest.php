<?php

namespace Tests;

use JumPHP\Testing\GameTestCase;

/** The samples the extension brings into a game (extensions/tiles/doc/samples/*.php) run: a scene each, some frames, no error. */
final class SamplesTest extends GameTestCase
{
    public function testEverySampleRuns(): void
    {
        $files = glob(dirname(__DIR__, 3) . '/extensions/tiles/doc/samples/*.php') ?: [];
        self::assertNotEmpty($files, 'the extension has at least one sample');
        foreach ($files as $file) {
            require_once $file;
            $class = 'App\\Extensions\\Tiles\\' . basename($file, '.php');
            self::assertTrue(defined($class . '::LABEL'), "$class says what it shows (LABEL)");
            $this->game->start($class);
            $this->game->run(90);
            self::assertInstanceOf($class, $this->game->scene(), $class);
        }
    }
}
