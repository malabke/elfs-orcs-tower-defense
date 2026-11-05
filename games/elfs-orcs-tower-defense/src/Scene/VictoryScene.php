<?php

namespace App\Scene;

use App\Td\GameData;
use App\Ui\GameMenuScene;
use JumPHP\Renderer\Renderer;
use JumPHP\Util\Lang;

/**
 * Over the won map: the stars (from the lives left) and what to do next.
 */
final class VictoryScene extends GameMenuScene
{
    protected string $title = 'VICTORY!';
    protected array $titleColor = [255, 220, 90];
    private int $stars = 0;
    private int $lives = 0;
    private array $restart = [];
    private float $time = 0.0;

    public function enter(array $params = []): void
    {
        $this->stars = (int)($params['stars'] ?? 0);
        $this->lives = (int)($params['lives'] ?? 0);
        $this->restart = $params['restart'] ?? [];
        $this->time = 0.0;
        parent::enter($params);
        $this->sound()->playSfx('victory');
    }

    public function isOverlay(): bool
    {
        return true;
    }

    protected function items(): array
    {
        $items = [];
        $maps = GameData::load($this->engine->config->path())->mapIds();
        $i = array_search($this->restart['map'] ?? '', $maps, true);
        if ($i !== false && isset($maps[$i + 1])) {
            // the next map of the campaign, opened by this victory
            $items[] = [Lang::t('NEXT MAP'), null, fn () => $this->scenes()->switchTo(PlayScene::class,
                ['map' => $maps[$i + 1], 'hard' => false] + $this->restart)];
        }
        $items[] = [Lang::t('PLAY AGAIN'), null, fn () => $this->scenes()->switchTo(PlayScene::class, $this->restart)];
        $items[] = [Lang::t('MAPS'), null, fn () => $this->scenes()->switchTo(MapSelectScene::class, $this->restart)];
        $items[] = [Lang::t('CHOOSE YOUR SIDE'), null, fn () => $this->scenes()->switchTo(FactionSelectScene::class)];
        return $items;
    }

    protected function subtitles(): array
    {
        return [[Lang::t('THE BASTION STANDS'), [200, 230, 255]], [Lang::t('LIVES LEFT {n}', ['n' => $this->lives]), [255, 160, 170]]];
    }

    public function update(float $dt): void
    {
        $this->time += $dt;
        parent::update($dt);
    }

    protected function extraHeight(): float
    {
        return 32.0;
    }

    protected function renderExtra(Renderer $r, float $cx, float $y): void
    {
        // the stars, one after the other
        for ($i = 0; $i < 3; $i++) {
            $earned = $i < $this->stars && $this->time > 0.3 + $i * 0.35;
            // each star grows in when it is won
            $grow = $earned ? min(1.0, ($this->time - 0.3 - $i * 0.35) * 6) : 1.0;
            \App\Ui\Paint::icon($r, $earned ? 'star' : 'star_off', $cx - 34 + $i * 34, $y + 15, 28 * (0.6 + 0.4 * $grow));
        }
    }
}
