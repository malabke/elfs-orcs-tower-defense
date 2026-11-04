<?php

namespace App\Ui;

use JumPHP\Kernel\Engine;
use JumPHP\Renderer\TextDrawer;
use JumPHP\Renderer\TtfFont;

/**
 * The font of the current language: a soft rounded font for Latin and Cyrillic (Nunito, smooth, drawn at the
 * window's resolution) when the engine draws the 2D in HD, the pixel font otherwise (sharp at the view's
 * resolution: the playground's shared engine may not have HD yet, see Hd); a fallback one for Japanese, Chinese and
 * Korean (assets/fonts/, OFL licences). Without the files, the engine's built-in font (upper-case ASCII).
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
     * [file, size in view pixels, smooth, SDL_ttf style (1: bold), thicken, outline (view pixels)]. Nunito's
     * variable file holds its thinnest weight: made bold, thickened, and its shadow drawn as an outline. (An engine
     * whose TtfFont takes fewer arguments ignores the last ones.)
     */
    public const MAIN_FONT = ['assets/fonts/Nunito.ttf', 10, true, 1, 0.3, 0.55];
    /** Without HD: the pixel font. */
    public const PIXEL_FONT = ['assets/fonts/PressStart2P-Regular.ttf', 8, false, 0, 0.0, 0.0];
    public const CJK_FONT = ['assets/fonts/unifont.otf', 16, false, 0, 0.0, 0.0];

    /** @var array<string, TextDrawer|null> */
    private array $fonts = [];
    private ?string $applied = null;

    public function apply(Engine $engine): TextDrawer
    {
        $key = $engine->lang->getLang() . (Hd::supported() ? ':hd' : ':pixel');
        if ($this->applied !== $key) {
            $engine->setFont($this->forLang($engine, $engine->lang->getLang()));
            $this->applied = $key;
        }
        return $engine->font();
    }

    public function forLang(Engine $engine, string $lang): TextDrawer
    {
        $cjk = $this->load($engine, self::CJK_FONT);
        $mainFont = Hd::supported() ? self::MAIN_FONT : self::PIXEL_FONT;
        $main = $this->load($engine, $mainFont);
        if (in_array($lang, self::CJK, true) && $cjk !== null) {
            return $cjk;
        }
        $main ??= $this->builtIn($engine);
        return $cjk !== null ? ($this->fonts['mixed:' . $mainFont[0]] ??= new MixedFont($main, $cjk)) : $main;
    }

    private function load(Engine $engine, array $font): ?TextDrawer
    {
        [$file, $size, $smooth, $style, $thicken, $outline] = $font;
        if (!array_key_exists($file, $this->fonts)) {
            $this->fonts[$file] = is_file($engine->config->path($file))
                ? new TtfFont($engine->sdl, $file, $size, $smooth, $style, $thicken, $outline) : null;
        }
        return $this->fonts[$file];
    }

    private function builtIn(Engine $engine): TextDrawer
    {
        return $this->fonts['builtin'] ??= new \JumPHP\Renderer\BitmapFont($engine->sdl);
    }
}
