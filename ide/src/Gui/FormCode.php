<?php

namespace JumPHP\Ide\Gui;

/**
 * The code behind a form, written by the visual editor: a double click on a widget adds the method of its event to the
 * form's class (or finds it), and the class's docblock gets one `@property Button $save` per widget with an id
 * (completion and phpstan then know `$this->save`).
 */
final class FormCode
{
    /** form file type => widget class (JumPHP\Gui\...) */
    public const CLASSES = [
        'container' => 'Container', 'row' => 'Container', 'column' => 'Container', 'grid' => 'Container', 'stack' => 'Container',
        'panel' => 'Container', 'label' => 'Label', 'button' => 'Button', 'textbox' => 'TextBox', 'checkbox' => 'CheckBox',
        'radio' => 'Radio', 'combobox' => 'ComboBox', 'listbox' => 'ListBox', 'slider' => 'Slider', 'progressbar' => 'ProgressBar',
        'image' => 'Image', 'video' => 'Video', 'spacer' => 'Spacer', 'separator' => 'Separator',
    ];

    /** what an event passes after the widget, per widget class (default: nothing) */
    public const ARGUMENTS = [
        'change' => ['TextBox' => 'string $text', 'CheckBox' => 'bool $checked', 'Radio' => 'bool $checked',
            'ComboBox' => 'int $index, string $item', 'ListBox' => 'int $index, string $item', 'Slider' => 'float $value'],
        'submit' => ['TextBox' => 'string $text'],
        'activate' => ['ListBox' => 'int $index, string $item'],
    ];

    /** The widget class of a form file type ('button' => 'Button'). */
    public static function classOf(string $type): string
    {
        return self::CLASSES[strtolower($type)] ?? 'Widget';
    }

    /** The name of the method a double click writes: onSaveClick (as JumPHP\Gui\FormLoader::handlerName). */
    public static function handlerName(string $id, string $event): string
    {
        $words = preg_split('/[^A-Za-z0-9]+/', $id . ' ' . $event) ?: [];
        return 'on' . implode('', array_map(fn (string $w) => ucfirst($w), array_filter($words, fn (string $w) => $w !== '')));
    }

    /** A new form class. */
    public static function newClass(?string $namespace, string $class): string
    {
        return "<?php\n\n" . ($namespace ? "namespace $namespace;\n\n" : '') . "use JumPHP\\Gui\\Form;\n\n"
            . "/**\n * A window of the app: its widgets are in forms/$class.form.yaml (the IDE's visual editor), its code here.\n */\n"
            . "final class $class extends Form\n{\n    protected function loaded(array \$params = []): void\n    {\n    }\n}\n";
    }

    /**
     * The method of a widget's event, added when the class does not have it yet.
     *
     * @return array{content: string, line: int, added: bool}
     */
    public static function wire(string $source, string $method, string $widgetClass, string $event): array
    {
        if (preg_match('/function\s+' . preg_quote($method, '/') . '\s*\(/i', $source, $m, PREG_OFFSET_CAPTURE)) {
            $line = substr_count(substr($source, 0, $m[0][1]), "\n") + 1;
            return ['content' => $source, 'line' => $line + 2, 'added' => false];
        }
        $source = self::import($source, $widgetClass);
        $args = self::ARGUMENTS[$event][$widgetClass] ?? '';
        $signature = "$widgetClass \$sender" . ($args !== '' ? ", $args" : '');
        $end = self::classEnd($source);
        if ($end === null) {
            throw new \RuntimeException('No class in this file to add ' . $method . '() to');
        }
        $before = rtrim(substr($source, 0, $end));
        $code = "\n\n    public function $method($signature): void\n    {\n        \n    }\n";
        $content = $before . $code . substr($source, $end);
        $line = substr_count($before, "\n") + 1 + 4;
        return ['content' => $content, 'line' => $line, 'added' => true];
    }

    /**
     * The class's docblock with one `@property` per widget (other lines kept, the widget ones written again).
     *
     * @param array<string, string> $widgets id => widget class
     */
    public static function properties(string $source, array $widgets): string
    {
        foreach (array_unique(array_values($widgets)) as $class) {
            $source = self::import($source, $class);
        }
        if (!preg_match('/^(?:(?:final|abstract|readonly)\s+)*class\s+\w+/m', $source, $m, PREG_OFFSET_CAPTURE)) {
            return $source;
        }
        $classAt = $m[0][1];
        $head = substr($source, 0, $classAt);
        $lines = [];
        foreach ($widgets as $id => $class) {
            $lines[] = " * @property $class \$$id";
        }
        $known = implode('|', array_unique(array_values(self::CLASSES)) + [99 => 'Widget']);
        if (preg_match('#/\*\*(?:(?!\*/).)*\*/\s*$#s', $head, $doc, PREG_OFFSET_CAPTURE)) {
            $block = $doc[0][0];
            $all = preg_split('/\R/', rtrim($block)) ?: [];
            if (count($all) === 1) {
                // a one-line docblock: /** Text. */
                $text = trim((string)preg_replace('#^/\*\*|\*/$#', '', trim($all[0])));
                $all = $text === '' ? ['/**', ' */'] : ['/**', " * $text", ' */'];
            }
            $kept = array_values(array_filter($all, fn (string $line) => !preg_match('/^\s*\*\s*@property\s+\\\\?(?:JumPHP\\\\Gui\\\\)?(' . $known . ')\s+\$\w+/', $line)));
            $close = (string)array_pop($kept);
            while (count($kept) > 1 && preg_match('/^\s*\*\s*$/', (string)end($kept))) {
                array_pop($kept);
            }
            $new = array_merge($kept, $lines !== [] && count($kept) > 1 ? [' *'] : [], $lines, [$close]);
            $head = substr($head, 0, $doc[0][1]) . rtrim(implode("\n", $new)) . "\n" . ltrim(substr($head, $doc[0][1] + strlen($block)), " \t");
            if (!str_ends_with($head, "\n")) {
                $head .= "\n";
            }
        } elseif ($lines !== []) {
            $head .= "/**\n" . implode("\n", $lines) . "\n */\n";
        }
        return $head . substr($source, $classAt);
    }

    /** `use JumPHP\Gui\<Class>;` when the file does not have it. */
    private static function import(string $source, string $class): string
    {
        if ($class === '' || preg_match('/^use\s+JumPHP\\\\Gui\\\\' . preg_quote($class, '/') . '\s*;/m', $source)
            || preg_match('/^namespace\s+JumPHP\\\\Gui\s*;/m', $source)) {
            return $source;
        }
        $use = "use JumPHP\\Gui\\$class;";
        if (preg_match_all('/^use\s+[^;]+;\s*$/m', $source, $all, PREG_OFFSET_CAPTURE)) {
            // among the other imports, in alphabetical order
            foreach ($all[0] as [$line, $offset]) {
                if (strcasecmp(trim($line), $use) > 0) {
                    return substr($source, 0, $offset) . $use . "\n" . substr($source, $offset);
                }
            }
            $last = end($all[0]);
            $at = $last[1] + strlen(rtrim($last[0]));
            return substr($source, 0, $at) . "\n" . $use . substr($source, $at);
        }
        if (preg_match('/^namespace\s+[^;]+;\s*$/m', $source, $ns, PREG_OFFSET_CAPTURE)) {
            $at = $ns[0][1] + strlen(rtrim($ns[0][0]));
            return substr($source, 0, $at) . "\n\n" . $use . substr($source, $at);
        }
        return preg_replace('/^<\?php\s*/', "<?php\n\n$use\n\n", $source, 1) ?? $source;
    }

    /** Where the class's closing brace is (the last } of the file). */
    private static function classEnd(string $source): ?int
    {
        $at = strrpos($source, '}');
        return $at === false ? null : $at;
    }
}
