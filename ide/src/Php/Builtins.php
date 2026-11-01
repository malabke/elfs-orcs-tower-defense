<?php

namespace JumPHP\Ide\Php;

/**
 * PHP's own classes, functions and constants (those of the PHP running the IDE), described like the Analyzer
 * describes a project's, through Reflection.
 */
final class Builtins
{
    /** @var array<string, array<string, mixed>|false> */
    private static array $classes = [];

    /** @var array<string, array<string, mixed>|false> */
    private static array $functions = [];

    public const KEYWORDS = ['abstract', 'and', 'array', 'as', 'break', 'callable', 'case', 'catch', 'class', 'clone', 'const',
        'continue', 'declare', 'default', 'do', 'echo', 'else', 'elseif', 'empty', 'enum', 'extends', 'false', 'final', 'finally',
        'fn', 'for', 'foreach', 'function', 'global', 'if', 'implements', 'include', 'include_once', 'instanceof', 'insteadof',
        'interface', 'isset', 'list', 'match', 'namespace', 'new', 'null', 'or', 'print', 'private', 'protected', 'public',
        'readonly', 'require', 'require_once', 'return', 'self', 'static', 'parent', 'switch', 'throw', 'trait', 'true', 'try',
        'unset', 'use', 'var', 'while', 'xor', 'yield', 'int', 'float', 'string', 'bool', 'void', 'mixed', 'never', 'iterable', 'object'];

    public const SUPERGLOBALS = ['this', 'GLOBALS', '_SERVER', '_GET', '_POST', '_FILES', '_COOKIE', '_SESSION', '_REQUEST', '_ENV', 'argv', 'argc'];

    /** @return array<string, mixed>|null */
    public static function classInfo(string $fqn): ?array
    {
        $key = strtolower(ltrim($fqn, '\\'));
        if (!array_key_exists($key, self::$classes)) {
            self::$classes[$key] = false;
            if (class_exists($key, false) || interface_exists($key, false) || trait_exists($key, false) || enum_exists($key, false)) {
                $reflection = new \ReflectionClass($key);
                if ($reflection->isInternal()) {
                    self::$classes[$key] = self::describe($reflection);
                }
            }
        }
        return self::$classes[$key] ?: null;
    }

    /** @return array<string, mixed>|null */
    public static function functionInfo(string $name): ?array
    {
        $key = strtolower(ltrim($name, '\\'));
        if (!array_key_exists($key, self::$functions)) {
            self::$functions[$key] = false;
            if (function_exists($key)) {
                $reflection = new \ReflectionFunction($key);
                if ($reflection->isInternal()) {
                    self::$functions[$key] = ['name' => $reflection->getName(), 'params' => self::params($reflection),
                        'return' => self::type($reflection->getReturnType()), 'doc' => '', 'deprecated' => $reflection->isDeprecated(),
                        'line' => 0, 'col' => 0, 'internal' => true];
                }
            }
        }
        return self::$functions[$key] ?: null;
    }

    /** @return list<string> */
    public static function functionNames(): array
    {
        static $names = null;
        return $names ??= get_defined_functions()['internal'];
    }

    /** @return list<string> */
    public static function classNames(): array
    {
        static $names = null;
        if ($names === null) {
            $names = [];
            foreach ([...get_declared_classes(), ...get_declared_interfaces(), ...get_declared_traits()] as $class) {
                if ((new \ReflectionClass($class))->isInternal()) {
                    $names[] = $class;
                }
            }
        }
        return $names;
    }

    /** @return list<string> */
    public static function constantNames(): array
    {
        static $names = null;
        if ($names === null) {
            $names = [];
            foreach (get_defined_constants(true) as $group => $constants) {
                if ($group !== 'user') {
                    array_push($names, ...array_keys($constants));
                }
            }
        }
        return $names;
    }

    /** @return array<string, mixed> */
    private static function describe(\ReflectionClass $class): array
    {
        $info = [
            'name' => $class->getName(),
            'kind' => $class->isInterface() ? 'interface' : ($class->isTrait() ? 'trait' : ($class->isEnum() ? 'enum' : 'class')),
            'line' => 0, 'col' => 0, 'endLine' => 0,
            'extends' => $class->isInterface() ? $class->getInterfaceNames() : ($class->getParentClass() ? [$class->getParentClass()->getName()] : []),
            'implements' => $class->isInterface() ? [] : $class->getInterfaceNames(),
            'traits' => [], 'abstract' => $class->isAbstract(), 'final' => $class->isFinal(), 'doc' => '', 'deprecated' => false,
            'methods' => [], 'props' => [], 'consts' => [], 'internal' => true,
        ];
        foreach ($class->getMethods() as $method) {
            if ($method->getDeclaringClass()->getName() !== $class->getName()) {
                continue;
            }
            $info['methods'][strtolower($method->getName())] = ['name' => $method->getName(), 'params' => self::params($method),
                'return' => self::type($method->getReturnType() ?? ($method->hasTentativeReturnType() ? $method->getTentativeReturnType() : null)),
                'doc' => '', 'deprecated' => $method->isDeprecated(), 'line' => 0, 'col' => 0, 'static' => $method->isStatic(),
                'vis' => $method->isPrivate() ? 'private' : ($method->isProtected() ? 'protected' : 'public'), 'abstract' => $method->isAbstract()];
        }
        foreach ($class->getProperties() as $property) {
            if ($property->getDeclaringClass()->getName() === $class->getName()) {
                $info['props'][$property->getName()] = ['type' => self::type($property->getType()), 'static' => $property->isStatic(),
                    'vis' => $property->isPrivate() ? 'private' : ($property->isProtected() ? 'protected' : 'public'), 'doc' => '', 'line' => 0, 'col' => 0];
            }
        }
        foreach ($class->getReflectionConstants() as $constant) {
            if ($constant->getDeclaringClass()->getName() === $class->getName()) {
                $value = $constant->getValue();
                $info['consts'][$constant->getName()] = ['line' => 0, 'col' => 0, 'vis' => 'public',
                    'value' => is_scalar($value) ? var_export($value, true) : '', 'case' => $class->isEnum() && $value instanceof \UnitEnum];
            }
        }
        return $info;
    }

    /** @return list<array<string, mixed>> */
    private static function params(\ReflectionFunctionAbstract $function): array
    {
        $out = [];
        foreach ($function->getParameters() as $param) {
            $default = null;
            if ($param->isOptional() && !$param->isVariadic()) {
                try {
                    $default = $param->isDefaultValueAvailable()
                        ? ($param->isDefaultValueConstant() ? (string)$param->getDefaultValueConstantName() : var_export($param->getDefaultValue(), true))
                        : '?';
                } catch (\Throwable) {
                    $default = '?';
                }
            }
            $type = self::type($param->getType());
            $out[] = ['name' => $param->getName(), 'type' => $type, 'hint' => $type, 'default' => $default,
                'variadic' => $param->isVariadic(), 'byRef' => $param->isPassedByReference()];
        }
        return $out;
    }

    private static function type(?\ReflectionType $type): ?string
    {
        if ($type === null) {
            return null;
        }
        if ($type instanceof \ReflectionNamedType) {
            $name = $type->isBuiltin() ? strtolower($type->getName()) : $type->getName();
            return $type->allowsNull() && $name !== 'null' && $name !== 'mixed' ? "$name|null" : $name;
        }
        if ($type instanceof \ReflectionUnionType || $type instanceof \ReflectionIntersectionType) {
            return implode('|', array_map(fn ($t) => self::type($t), $type->getTypes()));
        }
        return null;
    }
}
