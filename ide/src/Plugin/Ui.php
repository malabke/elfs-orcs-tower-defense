<?php

namespace JumPHP\Ide\Plugin;

/**
 * What a plugin's tool window shows: a tree of plain nodes built in PHP, drawn by the IDE in its own style (both
 * themes). Callbacks (a click, a text submitted, a box checked) run in PHP; the window is drawn again after each.
 *
 *     $ide->toolWindow('todos', 'TODOs', fn () => Ui::column(
 *         Ui::heading('TODOs'),
 *         Ui::input('Filter', fn (string $q) => $this->filter = $q, $this->filter),
 *         Ui::list(array_map(fn ($t) => Ui::item($t['text'], fn () => $ide->openFile($t['path'], $t['line']), $t['path']), $todos)),
 *         Ui::button('Refresh', fn () => $this->scan(), 'refresh'),
 *     ));
 *
 * Icons: the IDE's names (play, test, refresh, file, folder, search, find, settings, plus, trash, check, warning,
 * star, globe, package, terminal, debug, history...).
 */
final class Ui
{
    /** @return array<string, mixed> children one under the other */
    public static function column(mixed ...$children): array
    {
        return ['type' => 'column', 'children' => self::children($children)];
    }

    /** @return array<string, mixed> children side by side */
    public static function row(mixed ...$children): array
    {
        return ['type' => 'row', 'children' => self::children($children)];
    }

    /** @return array<string, mixed> */
    public static function heading(string $text): array
    {
        return ['type' => 'heading', 'text' => $text];
    }

    /** @return array<string, mixed> a paragraph; muted: greyed */
    public static function text(string $text, bool $muted = false): array
    {
        return ['type' => 'text', 'text' => $text, 'muted' => $muted];
    }

    /** @return array<string, mixed> monospaced, kept as written */
    public static function code(string $text): array
    {
        return ['type' => 'code', 'text' => $text];
    }

    /** @param callable(): mixed $onClick @return array<string, mixed> */
    public static function button(string $label, callable $onClick, ?string $icon = null, bool $primary = false): array
    {
        return ['type' => 'button', 'label' => $label, 'onClick' => $onClick, 'icon' => $icon, 'primary' => $primary];
    }

    /** @param callable(): mixed $onClick @return array<string, mixed> a link-like text that runs something */
    public static function link(string $label, callable $onClick): array
    {
        return ['type' => 'link', 'label' => $label, 'onClick' => $onClick];
    }

    /** @param list<array<string, mixed>> $items Ui::item()s @return array<string, mixed> */
    public static function list(array $items, string $empty = 'Nothing'): array
    {
        return ['type' => 'list', 'items' => $items, 'empty' => $empty];
    }

    /** @param callable(): mixed|null $onClick @return array<string, mixed> a row of a list: label, a hint after it */
    public static function item(string $label, ?callable $onClick = null, ?string $hint = null, ?string $icon = null): array
    {
        return ['type' => 'item', 'label' => $label, 'onClick' => $onClick, 'hint' => $hint, 'icon' => $icon];
    }

    /** @param callable(string): mixed $onSubmit called with the text on Enter @return array<string, mixed> */
    public static function input(string $placeholder, callable $onSubmit, string $value = ''): array
    {
        return ['type' => 'input', 'placeholder' => $placeholder, 'onSubmit' => $onSubmit, 'value' => $value];
    }

    /** @param callable(bool): mixed $onChange @return array<string, mixed> */
    public static function checkbox(string $label, bool $checked, callable $onChange): array
    {
        return ['type' => 'checkbox', 'label' => $label, 'checked' => $checked, 'onChange' => $onChange];
    }

    /** @return array<string, mixed> */
    public static function separator(): array
    {
        return ['type' => 'separator'];
    }

    /** @return array<string, mixed> HTML of the plugin's own (shown as is, in the IDE's page: trusted plugins only) */
    public static function html(string $html): array
    {
        return ['type' => 'html', 'html' => $html];
    }

    /**
     * @param array<mixed> $children nodes, strings (texts), nulls (skipped) or lists of them
     * @return list<array<string, mixed>>
     */
    private static function children(array $children): array
    {
        $out = [];
        foreach ($children as $child) {
            if ($child === null || $child === false) {
                continue;
            }
            if (is_string($child)) {
                $out[] = self::text($child);
            } elseif (is_array($child) && isset($child['type'])) {
                $out[] = $child;
            } elseif (is_array($child)) {
                array_push($out, ...self::children($child));
            }
        }
        return $out;
    }
}
