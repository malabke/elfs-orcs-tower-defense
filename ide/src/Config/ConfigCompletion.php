<?php

namespace JumPHP\Ide\Config;

use JumPHP\Ide\Php\Index;

/**
 * Completion in a game's YAML files: config/game.yaml (its keys, with what each one does, and their values: scenes,
 * languages, booleans, key names...), config/input.yaml (actions, then key / pad / touch / mouse names) and
 * config/services.yaml (Symfony's dependency injection: _defaults, a service's keys, the project's classes),
 * config/lang.yaml (the languages, then the texts another language has and this one not yet) and the forms of a
 * graphic app (forms/*.form.yaml: a widget's settings and their values, JumPHP\Gui\FormLoader).
 * The YAML is read by its indentation: the keys above the cursor give where it is.
 */
final class ConfigCompletion
{
    private const LANGS = ['fr', 'en', 'es', 'de', 'it', 'pt', 'nl', 'pl', 'ru', 'uk', 'tr', 'ja', 'zh', 'ko'];
    private const BOOL = ['true', 'false'];

    public function __construct(private ?Index $index = null)
    {
    }

    /** Whether a file is one this completes. */
    public static function handles(string $path): bool
    {
        return (bool)preg_match('#(^|/)config/(game|input|services|lang)\.ya?ml$|\.form\.ya?ml$#', $path);
    }

    /** @return array{items: list<array<string, mixed>>, incomplete: bool} */
    public function complete(string $path, string $text, int $line, int $column): array
    {
        if (!self::handles($path)) {
            return ['items' => [], 'incomplete' => false];
        }
        if (preg_match('/\.form\.ya?ml$/', $path)) {
            return $this->form($text, $line, $column);
        }
        if (str_contains(basename($path), 'lang')) {
            return $this->lang($text, $line, $column);
        }
        $schema = match (true) {
            str_contains(basename($path), 'input') => self::input(),
            str_contains(basename($path), 'services') => self::services(),
            default => self::game(),
        };
        $lines = preg_split('/\R/', $text) ?: [];
        $current = $lines[$line - 1] ?? '';
        $before = substr($current, 0, max(0, $column - 1));
        $indent = strlen($current) - strlen(ltrim($current, ' '));
        $path = $this->pathAbove($lines, $line - 1, $before !== '' && trim($before) === '' ? strlen($before) : $indent);
        $node = $this->node($schema, $path);

        // "key: [A, B, |" or "key: |": a value of key
        if (preg_match('/^\s*(?:-\s+)?([\w\\\\-]+)\s*:\s*(.*)$/', $before, $m)) {
            $child = $this->child($node, $m[1]);
            if ($child === null) {
                return ['items' => [], 'incomplete' => false];
            }
            $value = $m[2];
            if (str_starts_with(ltrim($value), '[')) {
                return ['items' => $this->values($child['items'] ?? null), 'incomplete' => false];
            }
            if (preg_match('/^[\'"]?[\w\\\\:. -]*$/', $value)) {
                return ['items' => $this->values($child['values'] ?? null), 'incomplete' => false];
            }
            return ['items' => [], 'incomplete' => false];
        }
        // "- |": an item of the list above
        if (preg_match('/^\s*-\s+[\w:. -]*$/', $before)) {
            return ['items' => $this->values($node['items'] ?? null), 'incomplete' => false];
        }
        // "  ke|": a key here
        if (preg_match('/^\s*[\w\\\\-]*$/', $before)) {
            $present = $this->siblings($lines, $line - 1, $indent);
            $items = [];
            foreach ($node['keys'] ?? [] as $key => $child) {
                if (isset($present[$key])) {
                    continue;
                }
                $items[] = ['label' => $key, 'kind' => 'property', 'detail' => $child['doc'] ?? '',
                    'documentation' => $child['doc'] ?? '', 'insertText' => $key . ': ',
                    'sortText' => '0' . $key];
            }
            // services.yaml: a service is keyed by its class
            if (isset($node['any']['keys']['class'])) {
                foreach ($this->classes() as $class) {
                    if (!isset($present[$class])) {
                        $items[] = ['label' => $class, 'kind' => 'class', 'detail' => 'service', 'insertText' => $class . ':', 'sortText' => '1' . $class];
                    }
                }
            }
            return ['items' => $items, 'incomplete' => false];
        }
        return ['items' => [], 'incomplete' => false];
    }

    /** Widget settings (JumPHP\Gui\Widget::BASE, each widget's DEFAULTS, the form's own) => their values. */
    private const FORM = [
        'type' => ['label', 'button', 'textbox', 'checkbox', 'radio', 'combobox', 'listbox', 'slider', 'progressbar', 'image', 'video',
            'spacer', 'separator', 'column', 'row', 'grid', 'stack', 'panel', 'container'],
        'id' => null, 'class' => null, 'children' => null, 'events' => null, 'text' => null, 'title' => null,
        'theme' => ['light', 'dark'], 'layout' => ['column', 'row', 'grid', 'stack'],
        'justify' => ['start', 'center', 'end', 'between', 'around'], 'items' => ['stretch', 'start', 'center', 'end'],
        'align' => ['start', 'center', 'end', 'stretch'], 'textAlign' => ['left', 'center', 'right'],
        'style' => ['default', 'primary', 'title', 'muted'], 'fit' => ['contain', 'cover', 'fill'],
        'width' => ['auto', 'fill'], 'height' => ['auto', 'fill'], 'hide' => ['xs', 'sm', 'md', 'lg'], 'span' => null,
        'gap' => null, 'padding' => null, 'columns' => null, 'grow' => null, 'minWidth' => null, 'minHeight' => null,
        'background' => null, 'color' => null, 'tooltip' => null, 'placeholder' => null, 'value' => null, 'min' => null,
        'max' => null, 'step' => null, 'maxLength' => null, 'rows' => null, 'size' => null, 'src' => null, 'group' => null,
        'selected' => null, 'fps' => null, 'image' => null,
        'visible' => self::BOOL, 'enabled' => self::BOOL, 'wrap' => self::BOOL, 'border' => self::BOOL, 'scroll' => self::BOOL,
        'checked' => self::BOOL, 'bold' => self::BOOL, 'multiline' => self::BOOL, 'password' => self::BOOL,
        'readonly' => self::BOOL, 'showText' => self::BOOL, 'autoplay' => self::BOOL, 'loop' => self::BOOL,
        'controls' => self::BOOL, 'audio' => self::BOOL, 'default' => self::BOOL,
    ];
    private const FORM_EVENTS = ['click', 'change', 'submit', 'focus', 'blur', 'end'];

    /** @return array{items: list<array<string, mixed>>, incomplete: bool} a form file: by the key under the cursor */
    private function form(string $text, int $line, int $column): array
    {
        $lines = preg_split('/\R/', $text) ?: [];
        $current = $lines[$line - 1] ?? '';
        $before = substr($current, 0, max(0, $column - 1));
        $indent = strlen($current) - strlen(ltrim($current, ' '));
        $path = $this->pathAbove($lines, $line - 1, $before !== '' && trim($before) === '' ? strlen($before) : $indent);
        $item = static fn (string $label, string $kind, string $insert): array => ['label' => $label, 'kind' => $kind, 'insertText' => $insert, 'sortText' => '0' . $label];
        // "key: |" or "key: [a, |": its values
        if (preg_match('/^\s*(?:-\s+)?([\w]+)\s*:\s*\[?[\w ,]*$/', $before, $m) && str_contains($before, ':')) {
            return ['items' => array_map(static fn (string $v) => $item($v, 'value', $v), (array)(self::FORM[$m[1]] ?? [])), 'incomplete' => false];
        }
        // a key: under events, an event; else a setting not written yet
        if (preg_match('/^\s*(?:-\s+)?\w*$/', $before)) {
            if (end($path) === 'events') {
                return ['items' => array_map(static fn (string $e) => $item($e, 'property', "$e: on"), self::FORM_EVENTS), 'incomplete' => false];
            }
            $present = $this->siblings($lines, $line - 1, str_starts_with(ltrim($before), '-') ? $indent + 2 : $indent);
            $items = [];
            foreach (array_keys(self::FORM) as $key) {
                if (!isset($present[$key])) {
                    $items[] = $item($key, 'property', $key . ': ');
                }
            }
            return ['items' => $items, 'incomplete' => false];
        }
        return ['items' => [], 'incomplete' => false];
    }

    /** @return array{items: list<array<string, mixed>>, incomplete: bool} config/lang.yaml: languages, then texts */
    private function lang(string $text, int $line, int $column): array
    {
        $lines = preg_split('/\R/', $text) ?: [];
        $before = substr($lines[$line - 1] ?? '', 0, max(0, $column - 1));
        if (!preg_match('/^(\s*)[^:#]*$/', $before, $m)) {
            return ['items' => [], 'incomplete' => false];
        }
        try {
            $data = \Symfony\Component\Yaml\Yaml::parse($text);
        } catch (\Throwable) {
            $data = null;
        }
        $data = is_array($data) ? $data : [];
        if ($m[1] === '') {
            $items = [];
            foreach (self::LANGS as $lang) {
                if (!isset($data[$lang])) {
                    $items[] = ['label' => $lang, 'kind' => 'property', 'insertText' => "$lang:", 'sortText' => '0' . $lang];
                }
            }
            return ['items' => $items, 'incomplete' => false];
        }
        // the language this line is in: the nearest key above without indentation
        $lang = null;
        for ($i = $line - 2; $i >= 0; $i--) {
            if (preg_match('/^([\w-]+)\s*:/', $lines[$i], $k)) {
                $lang = $k[1];
                break;
            }
        }
        $mine = is_array($data[$lang] ?? null) ? $data[$lang] : [];
        $items = [];
        foreach ($data as $other => $texts) {
            foreach ($other !== $lang && is_array($texts) ? $texts : [] as $key => $translation) {
                $key = (string)$key;
                if (!array_key_exists($key, $mine) && !isset($items[$key])) {
                    $quoted = preg_match('/^[\w ]+$/u', $key) && !preg_match('/^(true|false|null|yes|no|on|off|\d+)$/i', $key) ? $key : "'" . str_replace("'", "''", $key) . "'";
                    $items[$key] = ['label' => $key, 'kind' => 'text', 'detail' => "$other: " . (is_scalar($translation) ? (string)$translation : ''),
                        'insertText' => "$quoted: ", 'filterText' => $key, 'sortText' => '0' . $key];
                }
            }
        }
        return ['items' => array_values($items), 'incomplete' => false, 'typed' => ltrim($before)];
    }

    /**
     * The keys above a line, outermost first ("game", "smoke"...).
     *
     * @param list<string> $lines
     * @return list<string>
     */
    private function pathAbove(array $lines, int $at, int $indent): array
    {
        $path = [];
        for ($i = $at - 1; $i >= 0 && $indent > 0; $i--) {
            $text = $lines[$i];
            if (trim($text) === '' || str_starts_with(ltrim($text), '#')) {
                continue;
            }
            $own = strlen($text) - strlen(ltrim($text, ' '));
            if ($own < $indent) {
                if (preg_match('/^\s*(?:-\s+)?([\w\\\\-]+)\s*:/', $text, $m)) {
                    array_unshift($path, $m[1]);
                }
                $indent = $own;
            }
        }
        return $path;
    }

    /** @param list<string> $lines @return array<string, true> the keys already written beside this line */
    private function siblings(array $lines, int $at, int $indent): array
    {
        $out = [];
        foreach ([[-1, -1], [1, count($lines)]] as [$step, $end]) {
            for ($i = $at + $step; $i !== $end && $i >= 0 && $i < count($lines); $i += $step) {
                $text = $lines[$i];
                if (trim($text) === '' || str_starts_with(ltrim($text), '#')) {
                    continue;
                }
                $own = strlen($text) - strlen(ltrim($text, ' '));
                // "- key: x" two columns left: the first key of this list item
                if ($step === -1 && $own + 2 === $indent && preg_match('/^\s*-\s+([\w\\\\-]+)\s*:/', $text, $m)) {
                    $out[$m[1]] = true;
                }
                if ($own < $indent || str_starts_with(ltrim($text), '- ')) {
                    break;
                }
                if ($own === $indent && preg_match('/^\s*([\w\\\\-]+)\s*:/', $text, $m)) {
                    $out[$m[1]] = true;
                }
            }
        }
        return $out;
    }

    /** @param array<string, mixed> $schema @param list<string> $path @return array<string, mixed> */
    private function node(array $schema, array $path): array
    {
        $node = $schema;
        foreach ($path as $key) {
            $node = $this->child($node, $key) ?? [];
        }
        return $node;
    }

    /** @param array<string, mixed> $node @return array<string, mixed>|null */
    private function child(array $node, string $key): ?array
    {
        return $node['keys'][$key] ?? $node['any'] ?? null;
    }

    /** @return list<array<string, mixed>> */
    private function values(array|string|null $values): array
    {
        $list = match ($values) {
            'scenes' => $this->scenes(),
            'classes' => $this->classes(),
            'keys' => self::keyNames(),
            'langs' => self::LANGS,
            null => [],
            default => (array)$values,
        };
        $items = [];
        foreach ($list as $i => $value) {
            $items[] = ['label' => $value, 'kind' => in_array($values, ['scenes', 'classes'], true) ? 'class' : 'value', 'insertText' => $value,
                'sortText' => sprintf('%04d', $i)];
        }
        return $items;
    }

    /** @return list<string> the scenes of the project (classes under a Scene folder or namespace) */
    private function scenes(): array
    {
        if ($this->index === null) {
            return [];
        }
        $out = [];
        foreach ($this->index->classNames('Scene', 400) as $class) {
            if ($class['path'] !== null && $class['kind'] === 'class' && preg_match('/\\\\(Scene|Form)\\\\|Scene$|Form$/', $class['name'])) {
                $out[] = $class['name'];
            }
        }
        sort($out);
        return array_values(array_unique($out));
    }

    /** @return list<string> the project's own classes and interfaces (not the libraries') */
    private function classes(): array
    {
        $out = [];
        foreach ($this->index?->classNames('', 2000) ?? [] as $class) {
            if ($class['path'] !== null && !str_contains($class['path'], 'vendor/') && in_array($class['kind'], ['class', 'interface'], true)) {
                $out[] = $class['name'];
            }
        }
        sort($out);
        return array_values(array_unique($out));
    }

    /** @return array<string, mixed> config/services.yaml (Symfony DependencyInjection, as the engine reads it) */
    private static function services(): array
    {
        $bool = static fn (string $doc): array => ['doc' => $doc, 'values' => self::BOOL];
        $service = ['doc' => 'A service: its class name (or a namespace prefix ending with \\ and a resource)', 'keys' => [
            'class' => ['doc' => 'Its class, when the key is not the class name', 'values' => 'classes'],
            'arguments' => ['doc' => 'Constructor arguments: [\'@App\\Other\', \'%param%\', 42] or {$name: value}'],
            'calls' => ['doc' => 'Methods called after construction: - [setLogger, [\'@logger\']]'],
            'factory' => ['doc' => 'Made by a factory: [\'@App\\Factory\', create] or [App\\Factory, create]'],
            'alias' => ['doc' => 'Another name of an existing service', 'values' => 'classes'],
            'decorates' => ['doc' => 'Replaces a service, which stays reachable as .inner', 'values' => 'classes'],
            'parent' => ['doc' => 'An abstract service whose settings it takes', 'values' => 'classes'],
            'tags' => ['doc' => 'Tags: [name] or - {name: x, priority: 10}'],
            'bind' => ['doc' => 'Values for constructor arguments by name or type: $speed: 120'],
            'resource' => ['doc' => 'For a namespace prefix (App\\:): its files, \'../src/*\''],
            'exclude' => ['doc' => 'For a namespace prefix: files that are not services, \'../src/Entity\''],
            'public' => $bool('Reachable with $container->get() (scenes are public automatically)'),
            'shared' => $bool('One instance (true, the default) or a new one each time'),
            'lazy' => $bool('Made at its first use'),
            'abstract' => $bool('Only a parent of other services'),
            'autowire' => $bool('Constructor arguments found from their types'),
            'autoconfigure' => $bool('Tags from the interfaces it implements'),
            'synthetic' => $bool('Set at run time ($container->set())'),
        ]];
        return ['keys' => [
            'services' => ['doc' => 'The services: _defaults, then one key per class (or App\\: for a whole folder)', 'keys' => [
                '_defaults' => ['doc' => 'The defaults of the services of this file', 'keys' => [
                    'autowire' => $bool('Constructor arguments found from their types'),
                    'autoconfigure' => $bool('Tags from the interfaces they implement'),
                    'public' => $bool('Reachable with $container->get()'),
                    'bind' => ['doc' => 'Values for constructor arguments by name or type: $speed: 120'],
                ]],
                '_instanceof' => ['doc' => 'Settings of the services that implement an interface: App\\Contract: {tags: [x]}'],
            ], 'any' => $service],
            'parameters' => ['doc' => 'Values for %name% in arguments: speed: 120'],
            'imports' => ['doc' => 'Other files: - {resource: services_dev.yaml}'],
        ]];
    }

    /** @return list<string> what config/input.yaml binds: SDL key names, gamepad, touch, mouse */
    public static function keyNames(): array
    {
        $keys = ['Left', 'Right', 'Up', 'Down', 'Space', 'Return', 'Escape', 'Tab', 'Backspace', 'Delete', 'Insert', 'Home', 'End',
            'PageUp', 'PageDown', 'Left Shift', 'Right Shift', 'Left Ctrl', 'Right Ctrl', 'Left Alt', 'Right Alt'];
        foreach (range('A', 'Z') as $letter) {
            $keys[] = $letter;
        }
        foreach (range(0, 9) as $digit) {
            $keys[] = (string)$digit;
        }
        foreach (range(1, 12) as $f) {
            $keys[] = "F$f";
        }
        foreach (range(0, 9) as $digit) {
            $keys[] = "Keypad $digit";
        }
        foreach (['a', 'b', 'x', 'y', 'lb', 'rb', 'lt', 'rt', 'select', 'start', 'home', 'ls', 'rs', 'up', 'down', 'left', 'right',
            'ls_left', 'ls_right', 'ls_up', 'ls_down', 'rs_left', 'rs_right', 'rs_up', 'rs_down'] as $button) {
            $keys[] = "pad0:$button";
        }
        foreach (['tap', 'left', 'right', 'up', 'down'] as $touch) {
            $keys[] = "touch:$touch";
        }
        foreach ([1, 2, 3] as $button) {
            $keys[] = "mouse:$button";
        }
        return $keys;
    }

    /** @return array<string, mixed> config/input.yaml */
    private static function input(): array
    {
        $binding = ['doc' => 'Keys, pad buttons, touch or mouse', 'items' => 'keys', 'values' => ['[]']];
        $actions = [];
        foreach (['up', 'down', 'left', 'right', 'confirm', 'back', 'pause'] as $action) {
            $actions[$action] = ['doc' => "Engine action (redefining it replaces its bindings)"] + $binding;
        }
        return ['keys' => [
            'actions' => ['doc' => 'The game\'s actions => their bindings: jump: [Space, pad0:a, touch:tap]', 'keys' => $actions, 'any' => $binding],
        ]];
    }

    /** @return array<string, mixed> config/game.yaml (JumPHP\Kernel\GameConfig, AGENTS.md section 4) */
    private static function game(): array
    {
        $key = fn (string $doc, array|string|null $values = null, array $more = []) => ['doc' => $doc] + ($values !== null ? ['values' => $values] : []) + $more;
        $langs = [];
        foreach (self::LANGS as $lang) {
            $langs[$lang] = $key("The description in this language");
        }
        return ['keys' => ['game' => ['doc' => 'The game (every key optional)', 'keys' => [
            'id' => $key('Lower case, unique (a-z0-9-)'),
            'title' => $key('The title (page title, portal)'),
            'version' => $key('Its version (0.1.0)'),
            'description' => $key('One sentence about it'),
            'descriptions' => $key('The description per language: the playground shows the visitor\'s', null, ['keys' => $langs]),
            'width' => $key('Virtual width in pixels (320)', ['320', '256', '384', '640']),
            'height' => $key('Virtual height in pixels (180)', ['180', '224', '192', '240', '360']),
            'scale' => $key('Desktop window = width x scale', ['2', '3', '4']),
            'integer_scale' => $key('Scale by whole factors only (sharper, bigger borders)', self::BOOL),
            'view' => $key('fixed: the virtual size; window: the window\'s size, pixel for pixel (forms)', ['fixed', 'window']),
            'fullscreen' => $key('Start full screen (desktop)', self::BOOL),
            'vsync' => $key('Follow the screen\'s refresh', self::BOOL),
            'max_fps' => $key('Desktop frame cap', ['60', '120', '144']),
            'background' => $key('Letterbox colour [r, g, b]', ['[0, 0, 0]']),
            'start_scene' => $key('The first scene', 'scenes'),
            'start_params' => $key('Its enter() params', ['{}']),
            'lang' => $key('Default language (config/lang.yaml)', 'langs'),
            'net_url' => $key('The relay server', ['ws://localhost:8090/ws']),
            'smoke' => $key('What bin/jumphp test runs headless 600 frames: a self-playing demo', null, ['keys' => [
                'scene' => $key('The scene', 'scenes'),
                'params' => $key('Its enter() params', ['{ demo: true }']),
            ]]),
            'online' => $key('The game has online play (shown by the portal)', self::BOOL),
            'touch' => $key('The phones\' on-screen pad', null, ['keys' => [
                'a' => $key('Key of the A button', 'keys'),
                'b' => $key('Key of the B button', 'keys'),
                'start' => $key('Key of START', 'keys'),
                'select' => $key('Key of a 4th button', 'keys'),
                'pad' => $key('stick: a floating joystick (8 directions)', ['stick']),
                'labels' => $key('Button labels {a: JUMP, ...}', ['{}']),
                'fullscreen' => $key('Full screen + landscape at the first tap', self::BOOL),
            ]]),
            'controls' => $key('One line of help shown by the web page / portal'),
            'runtime' => $key('async: PHP 8.6 ZTS + TrueAsync on the desktop (threads)', ['default', 'async']),
            'pause_scene' => $key('Pushed when the tab is hidden / the window loses the focus', 'scenes'),
            'pause_on_blur' => $key('Pause when the window loses the focus', self::BOOL),
            'mute_when_hidden' => $key('Silence while hidden', self::BOOL),
            'pwa' => $key('Installable, plays offline', ['true', 'false'], ['keys' => [
                'name' => $key('Its name'), 'short_name' => $key('The name under the icon (12 characters)'),
                'display' => $key('', ['fullscreen', 'standalone']), 'orientation' => $key('', ['landscape', 'portrait', 'any']),
                'theme_color' => $key('#rrggbb'), 'background_color' => $key('#rrggbb'),
            ]]),
            'debug_key' => $key('The FPS overlay key', 'keys'),
            'fullscreen_key' => $key('The fullscreen key (desktop)', 'keys'),
            'kind' => $key('gui: an app made of forms (JumPHP\\Gui)', ['gui']),
            'ui' => $key('webview: a desktop app (HTML + PHP, Tauri); hybrid: a game with HTML windows', ['webview', 'hybrid']),
            'main' => $key('The class that boots a desktop app (App\\Main)', ['App\\Main', 'App\\Desktop']),
            'app' => $key('Desktop app settings (docs/tauri.md)', null, ['keys' => [
                'pages' => $key('Its HTML pages (ui/)'), 'window' => $key('Its window'), 'embed' => $key('The game inside the Tauri window', self::BOOL),
                'update' => $key('Updates {url: <base>/update.json}'),
            ]]),
            'php_ini' => $key('php.ini lines of the exported executable ("a=1\\nb=2")'),
        ]]]];
    }
}
