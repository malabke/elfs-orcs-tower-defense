<?php

namespace Tests;

use App\Scene\FactionSelectScene;
use App\Scene\OptionsScene;
use JumPHP\Testing\GameTestCase;

/**
 * The settings' painted panel, played with the pointer and with the keys.
 */
final class OptionsTest extends GameTestCase
{
    private function tap(float $x, float $y): void
    {
        $mouse = $this->game->engine->input->getMouse();
        $mouse->move($x, $y);
        $mouse->down(1);
        $this->game->run(1);
        $mouse->up(1);
        $this->game->run(1);
    }

    /** A point of the panel (a private method of the scene: the geometry the pointer uses). */
    private function at(string $method, int $i): array
    {
        $scene = $this->game->scene();
        return (new \ReflectionMethod($scene, $method))->invoke($scene, $i);
    }

    private function openSettings(): void
    {
        $this->game->start(FactionSelectScene::class, ['faction' => 'elves']);
        $this->game->press('back', 2);
        self::assertInstanceOf(OptionsScene::class, $this->game->scene());
    }

    public function testThePlusAndMinusChangeTheSoundAndItIsSaved(): void
    {
        $this->openSettings();
        $sound = $this->game->engine->sound;
        $sound->setSfxVolume(0.5);
        [$minus, $plus] = $this->at('steppers', 0);
        $this->tap($plus[0], $plus[1]);
        $this->tap($plus[0], $plus[1]);
        self::assertEqualsWithDelta(0.7, $sound->getSfxVolume(), 0.001);
        $this->tap($minus[0], $minus[1]);
        self::assertEqualsWithDelta(0.6, $sound->getSfxVolume(), 0.001);
        self::assertEqualsWithDelta(0.6, (float)$this->game->engine->options->get('sfx_volume'), 0.001);
        $this->game->shot('settings-panel');
    }

    public function testTheArrowsTurnTheLanguageAndTheBackButtonCloses(): void
    {
        $this->openSettings();
        $lang = $this->game->engine->lang;
        $lang->setLang('en');
        [, $next] = $this->at('steppers', 1);
        $this->tap($next[0], $next[1]);
        self::assertNotSame('en', $lang->getLang());
        $this->game->shot('settings-panel-language');
        // the keys too: the language row is the one picked, left turns it back
        $this->game->press('left', 2);
        self::assertSame('en', $lang->getLang());

        [$x, $y, $w, $h] = $this->at('button', 0);
        $this->tap($x + $w / 2, $y + $h / 2);
        $this->game->run(2);
        self::assertInstanceOf(FactionSelectScene::class, $this->game->scene());
    }
}
