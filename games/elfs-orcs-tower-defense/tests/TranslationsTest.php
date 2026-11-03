<?php

namespace Tests;

use App\Td\GameData;
use App\Ui\FontProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Yaml\Yaml;

/**
 * Every text the game shows is translated in the 13 other languages, and no translation is left unused.
 * Keys: Lang::t('...') in src/, the menus' $title, and the names / descriptions of assets/data.
 */
final class TranslationsTest extends TestCase
{
    private const LANGUAGES = ['fr', 'es', 'de', 'it', 'pt', 'nl', 'pl', 'ru', 'uk', 'tr', 'ja', 'zh', 'ko'];

    /** @return list<string> */
    private static function usedKeys(): array
    {
        $root = dirname(__DIR__);
        $keys = [];
        $files = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($root . '/src'));
        foreach ($files as $file) {
            if ($file->getExtension() !== 'php') {
                continue;
            }
            $code = (string)file_get_contents($file->getPathname());
            preg_match_all("/Lang::t\\('((?:[^'\\\\]|\\\\.)+)'/", $code, $calls);
            preg_match_all("/string \\\$title = '([^']+)'/", $code, $titles);
            array_push($keys, ...$calls[1], ...$titles[1]);
        }
        array_push($keys, ...(new GameData($root . '/assets/data'))->textKeys());
        return array_values(array_unique($keys));
    }

    private static function tables(): array
    {
        return Yaml::parseFile(dirname(__DIR__) . '/config/lang.yaml');
    }

    public function testEveryLanguageOfThePlaygroundHasATable(): void
    {
        self::assertSame(self::LANGUAGES, array_keys(self::tables()));
        self::assertSame(['en', ...self::LANGUAGES], array_keys(FontProvider::LANGUAGES));
    }

    public function testEveryKeyUsedIsTranslatedInEveryLanguage(): void
    {
        $tables = self::tables();
        $missing = [];
        foreach (self::usedKeys() as $key) {
            foreach (self::LANGUAGES as $lang) {
                $text = $tables[$lang][$key] ?? null;
                if (!is_string($text) || $text === '') {
                    $missing[] = "$lang: $key";
                }
            }
        }
        self::assertSame([], $missing);
    }

    public function testEveryTranslationIsUsed(): void
    {
        $used = array_flip(self::usedKeys());
        $unused = [];
        foreach (self::tables() as $lang => $table) {
            foreach (array_keys($table) as $key) {
                if (!isset($used[$key])) {
                    $unused[] = "$lang: $key";
                }
            }
        }
        self::assertSame([], $unused);
    }

    public function testTranslationsKeepTheirPlaceholders(): void
    {
        $wrong = [];
        foreach (self::tables() as $lang => $table) {
            foreach ($table as $key => $text) {
                // the same placeholders, in any order (word order changes between languages)
                preg_match_all('/\{\w+\}/', (string)$key, $a);
                preg_match_all('/\{\w+\}/', (string)$text, $b);
                sort($a[0]);
                sort($b[0]);
                if ($a[0] !== $b[0]) {
                    $wrong[] = "$lang: $key";
                }
            }
        }
        self::assertSame([], $wrong);
    }
}
