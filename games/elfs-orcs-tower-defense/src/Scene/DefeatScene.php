<?php

namespace App\Scene;

use App\Ui\GameMenuScene;
use JumPHP\Util\Lang;

/**
 * Over the lost map: try again or leave.
 */
final class DefeatScene extends GameMenuScene
{
    protected string $title = 'DEFEAT';
    protected array $titleColor = [240, 90, 80];
    protected float $menuTop = 0.55;
    private int $wave = 0;
    private array $restart = [];

    public function enter(array $params = []): void
    {
        $this->wave = (int)($params['wave'] ?? 0);
        $this->restart = $params['restart'] ?? [];
        parent::enter($params);
        $this->sound()->playSfx('defeat');
    }

    public function isOverlay(): bool
    {
        return true;
    }

    protected function items(): array
    {
        return [
            [Lang::t('RETRY'), null, fn () => $this->scenes()->switchTo(PlayScene::class, $this->restart)],
            [Lang::t('MAPS'), null, fn () => $this->scenes()->switchTo(MapSelectScene::class, $this->restart)],
            [Lang::t('TITLE SCREEN'), null, fn () => $this->scenes()->switchTo(TitleScene::class)],
        ];
    }

    protected function subtitles(): array
    {
        return [[Lang::t('THE BASTION HAS FALLEN'), [230, 200, 190]], [Lang::t('WAVE {n}', ['n' => $this->wave]), [200, 200, 200]]];
    }
}
