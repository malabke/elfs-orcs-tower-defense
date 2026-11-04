<?php

namespace App\Scene;

use JumPHP\Kernel\Platform;
use JumPHP\Ui\MenuScene;

/** The templates of the videos; bin/jumphp video:make renders ShortScene directly (project.yaml video:). */
final class TitleScene extends MenuScene
{
    protected string $title = 'SHORTS';

    protected function items(): array
    {
        $items = [
            ['GROWING CIRCLE', null, fn () => $this->play('growing')],
            ['ESCAPE THE RINGS', null, fn () => $this->play('rings')],
            ['BALL RACE', null, fn () => $this->play('race')],
        ];
        if (Platform::isDesktop()) {
            $items[] = ['QUIT', null, fn () => $this->engine->quit()];
        }
        return $items;
    }

    private function play(string $template): void
    {
        $this->scenes()->switchTo(ShortScene::class, ['template' => $template, 'seed' => random_int(1, 99999)]);
    }

    protected function back(): void
    {
    }
}
