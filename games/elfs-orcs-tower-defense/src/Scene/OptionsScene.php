<?php

namespace App\Scene;

use App\Ui\FontProvider;
use App\Ui\GameMenuScene;
use JumPHP\Kernel\Platform;
use JumPHP\Util\Lang;

/**
 * Settings, saved in var/options.json (IndexedDB in the browser) by the engine's Options service.
 * Pushed over the title screen or the pause menu: back() pops it. Each language is named in itself.
 */
final class OptionsScene extends GameMenuScene
{
    protected string $title = 'OPTIONS';
    protected int $titlePixels = 16;
    protected float $menuTop = 0.38;

    protected function items(): array
    {
        $options = $this->engine->options;
        $sound = $this->sound();
        $items = [
            [Lang::t('SOUND'), fn () => round($sound->getSfxVolume() * 100) . '%', null, function (int $dir) use ($sound, $options): void {
                $sound->setSfxVolume(max(0.0, min(1.0, round($sound->getSfxVolume() * 10 + $dir) / 10)));
                $options->set('sfx_volume', $sound->getSfxVolume());
            }],
            [Lang::t('LANGUAGE'), fn () => FontProvider::LANGUAGES[$this->engine->lang->getLang()] ?? $this->engine->lang->getLang(), null,
                fn (int $dir) => $this->changeLanguage($dir)],
        ];
        if (Platform::isDesktop()) {
            $items[] = [Lang::t('FULLSCREEN'), fn () => $options->get('fullscreen') ? Lang::t('ON') : Lang::t('OFF'), null, fn () => $this->engine->toggleFullscreen()];
        }
        $items[] = [Lang::t('BACK'), null, fn () => $this->back()];
        return $items;
    }

    private function changeLanguage(int $dir): void
    {
        $langs = array_keys(FontProvider::LANGUAGES);
        $i = array_search($this->engine->lang->getLang(), $langs, true);
        $lang = $langs[((int)$i + $dir + count($langs)) % count($langs)];
        $this->engine->lang->setLang($lang);
        $this->engine->options->set('lang', $lang);
        $this->fonts->apply($this->engine);
        $this->refresh();
        $this->layoutMenu();
    }

    public function isOverlay(): bool
    {
        return true;
    }

    protected function back(): void
    {
        $this->scenes()->pop();
    }
}
