<?php

namespace JumPHP\Ide\Php;

use PhpParser\Node;

/**
 * Types as strings: class names fully qualified without the leading "\" (App\Entity\Hero), scalar types lower case
 * (int, string, array...), unions with "|" (nullable: "Foo|null"). Arrays of a class: "Foo[]".
 */
final class Types
{
    public const SCALARS = ['int', 'float', 'string', 'bool', 'array', 'callable', 'iterable', 'object', 'mixed', 'void',
        'null', 'never', 'false', 'true', 'self', 'static', 'parent', 'resource', 'integer', 'boolean', 'double', '$this',
        'list', 'non-empty-string', 'non-empty-array', 'positive-int', 'class-string', 'array-key', 'scalar', 'numeric'];

    /** A type node (or a name) of php-parser, after NameResolver. */
    public static function fromNode(?Node $type): ?string
    {
        if ($type === null) {
            return null;
        }
        if ($type instanceof Node\NullableType) {
            $inner = self::fromNode($type->type);
            return $inner === null ? null : "$inner|null";
        }
        if ($type instanceof Node\UnionType || $type instanceof Node\IntersectionType) {
            $parts = array_filter(array_map(fn (Node $t) => self::fromNode($t), $type->types));
            return $parts ? implode('|', $parts) : null;
        }
        if ($type instanceof Node\Identifier) {
            return strtolower($type->toString());
        }
        if ($type instanceof Node\Name) {
            $name = $type->toString();
            return in_array(strtolower($name), ['self', 'static', 'parent'], true) ? strtolower($name) : ltrim($name, '\\');
        }
        return null;
    }

    /** The class names of a type ("Foo|null" -> ["Foo"]); "Foo[]" counts as "Foo" when $elements. */
    public static function classes(?string $type, bool $elements = false): array
    {
        if ($type === null || $type === '') {
            return [];
        }
        $out = [];
        foreach (self::split($type) as $part) {
            $part = ltrim($part, '?\\');
            $isArray = str_ends_with($part, '[]');
            if ($isArray !== $elements) {
                continue;
            }
            $part = $isArray ? substr($part, 0, -2) : $part;
            $part = self::base($part);
            if ($part !== '' && !in_array(strtolower($part), self::SCALARS, true) && preg_match('/^[A-Za-z_\\\\][\w\\\\]*$/', $part)) {
                $out[] = ltrim($part, '\\');
            }
        }
        return array_values(array_unique($out));
    }

    /** Splits a union at its top level ("A|array<int, B>|null" -> [A, array<int, B>, null]). */
    public static function split(string $type): array
    {
        $parts = [];
        $depth = 0;
        $current = '';
        foreach (str_split($type) as $char) {
            if ($char === '<' || $char === '(' || $char === '{') {
                $depth++;
            } elseif ($char === '>' || $char === ')' || $char === '}') {
                $depth--;
            }
            if ($char === '|' && $depth === 0) {
                $parts[] = trim($current);
                $current = '';
            } else {
                $current .= $char;
            }
        }
        $parts[] = trim($current);
        return array_values(array_filter($parts, fn ($p) => $p !== ''));
    }

    /** "Box<Hero>" -> "Box"; "Hero" -> "Hero" (a type's name without its generic arguments). */
    public static function base(string $type): string
    {
        $pos = strpos($type, '<');
        return $pos === false ? $type : substr($type, 0, $pos);
    }

    /** "Box<int, Hero>" -> ["int", "Hero"]: the generic arguments of one type (not a union). */
    public static function args(string $type): array
    {
        if (!preg_match('/^[^<{]+<(.*)>$/s', $type, $m)) {
            return [];
        }
        return self::splitAt($m[1], ',');
    }

    /**
     * The fields of an array shape: "array{hero: Hero, count?: int}" -> ["hero" => "Hero", "count" => "int"]
     * (a list shape "array{Hero, int}": keys 0, 1...). Null: not a shape.
     *
     * @return array<string|int, string>|null
     */
    public static function shape(string $type): ?array
    {
        if (!preg_match('/^(?:array|list)\{(.*)\}$/s', trim($type), $m)) {
            return null;
        }
        $fields = [];
        $i = 0;
        foreach (self::splitAt($m[1], ',') as $field) {
            if (preg_match('/^([\w-]+|\'[^\']*\'|"[^"]*")\??\s*:\s*(.+)$/s', $field, $f)) {
                $fields[trim($f[1], '\'"')] = trim($f[2]);
            } else {
                $fields[$i++] = $field;
            }
        }
        return $fields;
    }

    /** Splits at a separator at the top level (outside <>, {}, ()). @return list<string> */
    public static function splitAt(string $text, string $separator): array
    {
        $parts = [];
        $depth = 0;
        $current = '';
        foreach (str_split($text) as $char) {
            if ($char === '<' || $char === '(' || $char === '{') {
                $depth++;
            } elseif ($char === '>' || $char === ')' || $char === '}') {
                $depth--;
            }
            if ($char === $separator && $depth === 0) {
                $parts[] = trim($current);
                $current = '';
            } else {
                $current .= $char;
            }
        }
        $parts[] = trim($current);
        return array_values(array_filter($parts, fn ($p) => $p !== ''));
    }

    /** The generic names whose last argument is what they hold ("list<Foo>" holds Foo). */
    private const LISTS = ['list', 'array', 'iterable', 'non-empty-list', 'non-empty-array', 'generator', 'traversable', 'iterator'];

    /**
     * A docblock type ("Foo|null", "list<Bar>", "?Baz", "\X\Y[]", "Box<Foo>", "array{a: Foo}") with its class names
     * resolved in a file's namespace and use statements. Generic lists of one class become "Class[]"; generic classes
     * keep their arguments ("App\Box<App\Hero>"), shapes their fields; template names ($templates) stay as they are.
     *
     * @param array<string, string> $uses alias (lower case) => fully qualified name
     * @param list<string> $templates
     */
    public static function resolveDoc(string $type, string $namespace, array $uses, array $templates = []): string
    {
        $out = [];
        foreach (self::split(trim($type)) as $part) {
            $nullable = str_starts_with($part, '?');
            $part = ltrim($part, '?');
            $suffix = '';
            while (str_ends_with($part, '[]')) {
                $suffix .= '[]';
                $part = substr($part, 0, -2);
            }
            if (str_starts_with($part, '(') && str_ends_with($part, ')')) {
                $inner = self::resolveDoc(substr($part, 1, -1), $namespace, $uses, $templates);
                $out[] = $suffix !== '' ? (str_contains($inner, '|') ? "array<$inner>" : $inner . $suffix) : $inner;
            } elseif (($fields = self::shape($part)) !== null) {
                $resolved = [];
                foreach ($fields as $key => $field) {
                    $resolved[] = (is_int($key) ? '' : "$key: ") . self::resolveDoc($field, $namespace, $uses, $templates);
                }
                $out[] = 'array{' . implode(', ', $resolved) . '}' . $suffix;
            } elseif (preg_match('/^(\\\\?[\w\\\\-]+)<(.*)>$/s', $part, $m)) {
                $args = array_map(fn ($a) => self::resolveDoc($a, $namespace, $uses, $templates), self::splitAt($m[2], ','));
                $name = ltrim($m[1], '\\');
                if (in_array(strtolower($name), self::LISTS, true) && $args) {
                    // the element type: the last argument (Generator<K, V, ...>: the second)
                    $element = strtolower($name) === 'generator' && count($args) > 1 ? $args[1] : $args[count($args) - 1];
                    $out[] = (preg_match('/^[\w\\\\]+(?:\[\])*$/', $element) ? $element . '[]' : "array<$element>") . $suffix;
                } elseif (in_array(strtolower($name), self::SCALARS, true) || $name === 'class-string' || $name === 'key-of' || $name === 'value-of') {
                    $out[] = strtolower($name) . '<' . implode(', ', $args) . '>' . $suffix;
                } else {
                    $out[] = self::resolveName($m[1], $namespace, $uses) . '<' . implode(', ', $args) . '>' . $suffix;
                }
            } elseif (in_array($part, $templates, true)) {
                $out[] = $part . $suffix;
            } elseif (in_array(strtolower($part), self::SCALARS, true) || !preg_match('/^\\\\?[A-Za-z_][\w\\\\]*$/', $part)) {
                $out[] = (in_array(strtolower($part), self::SCALARS, true) ? strtolower($part) : $part) . $suffix;
            } else {
                $out[] = self::resolveName($part, $namespace, $uses) . $suffix;
            }
            if ($nullable) {
                $out[] = 'null';
            }
        }
        return implode('|', array_unique($out));
    }

    /**
     * Replaces template names by their types ("T" -> "App\Hero") in a type.
     *
     * @param array<string, string> $bindings template name => type
     */
    public static function substitute(string $type, array $bindings): string
    {
        if ($bindings === []) {
            return $type;
        }
        $names = implode('|', array_map(fn ($n) => preg_quote($n, '/'), array_keys($bindings)));
        return (string)preg_replace_callback('/(?<![\w\\\\$])(' . $names . ')(?![\w\\\\])/', fn ($m) => $bindings[$m[1]], $type);
    }

    /** @param array<string, string> $uses */
    public static function resolveName(string $name, string $namespace, array $uses): string
    {
        if (str_starts_with($name, '\\')) {
            return substr($name, 1);
        }
        $first = strtolower(explode('\\', $name)[0]);
        if (isset($uses[$first])) {
            $rest = substr($name, strlen($first));
            return $uses[$first] . $rest;
        }
        return $namespace === '' ? $name : "$namespace\\$name";
    }
}
