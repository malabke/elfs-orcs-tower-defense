<?php

namespace App\Scene;

use App\Ui\GameMenuScene;
use JumPHP\Util\Lang;

/**
 * Pause menu, pushed over the game (also by the engine when the tab is hidden: game.yaml pause_scene).
 * isOverlay() keeps the frozen game visible under it.
 */
final class PauseScene extends GameMenuScene
{
    protected string $title = 'PAUSE';
    protected int $titlePixels = 16;
    /** the PlayScene's parameters, to restart the same map */
    private array $restart = [];
    /** pushed over a battle (else over a screen of choice: only RESUME) */
    private bool $inBattle = false;

    public function enter(array $params = []): void
    {
        // the scene under this one (already on the stack): a battle, or a screen of choice (the engine pushes the pause
        // menu over any scene when the window loses the focus: game.yaml pause_scene)
        $stack = $this->scenes()->stack();
        $below = $stack[count($stack) - 2] ?? null;
        $this->inBattle = $below instanceof PlayScene;
        $this->restart = $params['restart'] ?? ($this->inBattle ? $this->restart : []);
        parent::enter($params);
    }

    public function isOverlay(): bool
    {
        return true;
    }

    protected function items(): array
    {
        if (!$this->inBattle) {
            // over a screen of choice: nothing to restart
            return [[Lang::t('RESUME'), null, fn () => $this->scenes()->pop()]];
        }
        return [
            [Lang::t('RESUME'), null, fn () => $this->scenes()->pop()],
            [Lang::t('RESTART'), null, fn () => $this->scenes()->switchTo(PlayScene::class, $this->restart)],
            [Lang::t('OPTIONS'), null, fn () => $this->scenes()->push(OptionsScene::class)],
            [Lang::t('MAPS'), null, fn () => $this->scenes()->switchTo(MapSelectScene::class, $this->restart)],
            [Lang::t('CHOOSE YOUR SIDE'), null, fn () => $this->scenes()->switchTo(FactionSelectScene::class)],
        ];
    }

    public function resume(mixed $result = null): void
    {
        $this->fonts->apply($this->engine);
        $this->refresh();
        $this->layoutMenu();
    }

    protected function back(): void
    {
        $this->scenes()->pop();
    }

    public function update(float $dt): void
    {
        if ($this->actions()->isPressed('pause') && !$this->actions()->isPressed('back')) {
            $this->scenes()->pop();
            return;
        }
        parent::update($dt);
    }
}
