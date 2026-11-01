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

    /**
     * A docblock type ("Foo|null", "list<Bar>", "?Baz", "\X\Y[]") with its class names resolved in a file's namespace
     * and use statements. Generic lists of one class become "Class[]".
     *
     * @param array<string, string> $uses alias (lower case) => fully qualified name
     */
    public static function resolveDoc(string $type, string $namespace, array $uses): string
    {
        $out = [];
        foreach (self::split(trim($type)) as $part) {
            $nullable = str_starts_with($part, '?');
            $part = ltrim($part, '?');
            if (preg_match('/^(?:list|array|iterable|\\\\?Generator|\\\\?Traversable|\\\\?Iterator)<(?:[^,<>]+,\s*)?([\\\\\w]+)>$/i', $part, $m)) {
                $part = $m[1] . '[]';
            }
            $suffix = '';
            while (str_ends_with($part, '[]')) {
                $suffix .= '[]';
                $part = substr($part, 0, -2);
            }
            if (in_array(strtolower($part), self::SCALARS, true) || !preg_match('/^\\\\?[A-Za-z_][\w\\\\]*$/', $part)) {
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
