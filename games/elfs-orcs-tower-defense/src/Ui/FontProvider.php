<?php

namespace App\Ui;

use JumPHP\Kernel\Engine;
use JumPHP\Renderer\TextDrawer;
use JumPHP\Renderer\TtfFont;

/**
 * The font of the current language: a soft rounded font for Latin and Cyrillic (Nunito, smooth: with the
 * renderer's HD it is drawn at the window's resolution), a fallback one for Japanese, Chinese and Korean
 * (assets/fonts/, OFL licences). Without the files, the engine's built-in font (upper-case ASCII).
 * apply() makes it the engine's font: menus and HUD all take it from there.
 */
final class FontProvider
{
    /** Each language, written in itself (the language menu). */
    public const LANGUAGES = [
        'en' => 'English', 'fr' => 'Français', 'es' => 'Español', 'de' => 'Deutsch', 'it' => 'Italiano',
        'pt' => 'Português', 'nl' => 'Nederlands', 'pl' => 'Polski', 'ru' => 'Русский', 'uk' => 'Українська',
        'tr' => 'Türkçe', 'ja' => '日本語', 'zh' => '中文', 'ko' => '한국어',
    ];
    public const CJK = ['ja', 'zh', 'ko'];
    /**
     * [file, size in view pixels, smooth, SDL_ttf style, thicken, outline (view pixels)]. Nunito's variable file
     * holds its thinnest weight: made bold, thickened, and its shadow drawn as an outline (readable over the 3D).
     */
    public const MAIN_FONT = ['assets/fonts/Nunito.ttf', 10, true, TtfFont::BOLD, 0.3, 0.55];
    public const CJK_FONT = ['assets/fonts/unifont.otf', 16, false, 0, 0.0, 0.0];

    /** @var array<string, TextDrawer> */
    private array $fonts = [];
    private ?string $applied = null;

    public function apply(Engine $engine): TextDrawer
    {
        $lang = $engine->lang->getLang();
        if ($this->applied !== $lang) {
            $engine->setFont($this->forLang($engine, $lang));
            $this->applied = $lang;
        }
        return $engine->font();
    }

    public function forLang(Engine $engine, string $lang): TextDrawer
    {
        $cjk = $this->load($engine, 'cjk', self::CJK_FONT);
        $main = $this->load($engine, 'main', self::MAIN_FONT);
        if (in_array($lang, self::CJK, true) && $cjk !== null) {
            return $cjk;
        }
        $main ??= $this->builtIn($engine);
        return $cjk !== null ? ($this->fonts['mixed'] ??= new MixedFont($main, $cjk)) : $main;
    }

    private function load(Engine $engine, string $key, array $font): ?TextDrawer
    {
        if (!array_key_exists($key, $this->fonts)) {
            [$file, $size, $smooth, $style, $thicken, $outline] = $font;
            $this->fonts[$key] = is_file($engine->config->path($file))
                ? new TtfFont($engine->sdl, $file, $size, $smooth, $style, $thicken, $outline) : null;
        }
        return $this->fonts[$key];
    }

    private function builtIn(Engine $engine): TextDrawer
    {
        return $this->fonts['builtin'] ??= new \JumPHP\Renderer\BitmapFont($engine->sdl);
    }
}
