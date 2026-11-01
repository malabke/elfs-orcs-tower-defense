<?php

namespace JumPHP\Ide\Gui;

use Symfony\Component\Yaml\Yaml;

/**
 * Form files of graphic PHP apps (forms/MainForm.form.yaml, JumPHP\Gui) for the visual editor: read into arrays, written
 * back the way a person would write them: a widget without children on one line, containers as blocks, the comments
 * at the top of the file kept.
 */
final class FormFile
{
    /** @return array{form: array<string, mixed>, header: string} */
    public static function parse(string $text): array
    {
        $data = trim($text) === '' ? [] : Yaml::parse($text);
        $form = is_array($data) && is_array($data['form'] ?? null) ? $data['form'] : [];
        return ['form' => $form, 'header' => self::header($text)];
    }

    /** The comment lines before `form:`. */
    public static function header(string $text): string
    {
        $out = '';
        foreach (preg_split('/\R/', $text) ?: [] as $line) {
            if (!str_starts_with(ltrim($line), '#') && trim($line) !== '') {
                break;
            }
            $out .= $line . "\n";
        }
        return rtrim($out) === '' ? '' : rtrim($out) . "\n";
    }

    /** @param array<string, mixed> $form */
    public static function dump(array $form, string $header = ''): string
    {
        return $header . "form:\n" . self::map($form, 1);
    }

    /** @param array<string, mixed> $map */
    private static function map(array $map, int $depth): string
    {
        $pad = str_repeat('  ', $depth);
        $out = '';
        foreach ($map as $key => $value) {
            if ($key === 'children' && is_array($value)) {
                if ($value === []) {
                    continue;
                }
                $out .= "{$pad}children:\n";
                foreach ($value as $child) {
                    $out .= self::item(is_array($child) ? $child : [], $depth + 1);
                }
                continue;
            }
            $out .= $pad . self::key((string)$key) . ': ' . self::inline($value) . "\n";
        }
        return $out;
    }

    /** @param array<string, mixed> $widget */
    private static function item(array $widget, int $depth): string
    {
        $pad = str_repeat('  ', $depth);
        if (empty($widget['children'])) {
            unset($widget['children']);
            $line = self::inline($widget);
            if (strlen($pad) + strlen($line) <= 120) {
                return "$pad- $line\n";
            }
        }
        // a block: the first key after the dash, the others under it
        $body = self::map($widget, $depth + 1);
        return $pad . '- ' . ltrim($body);
    }

    private static function key(string $key): string
    {
        return preg_match('/^[A-Za-z_][A-Za-z0-9_]*$/', $key) ? $key : Yaml::dump($key);
    }

    /** Flow style without the spaces inside the braces: {type: button, events: {click: onOkClick}} */
    private static function inline(mixed $value): string
    {
        if (!is_array($value)) {
            return Yaml::dump($value, 0);
        }
        if (array_is_list($value)) {
            return '[' . implode(', ', array_map(fn ($v) => self::inline($v), $value)) . ']';
        }
        $parts = [];
        foreach ($value as $k => $v) {
            $parts[] = self::key((string)$k) . ': ' . self::inline($v);
        }
        return '{' . implode(', ', $parts) . '}';
    }
}
