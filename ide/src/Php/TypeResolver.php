<?php

namespace JumPHP\Ide\Php;

use PhpParser\Node;
use PhpParser\Node\Expr;
use PhpParser\Node\Scalar;

/** The type of an expression, with the whole project's index (members of other files, return types...). */
final class TypeResolver
{
    public function __construct(private Index $index)
    {
    }

    public function type(Expr $expr, Context $context, int $depth = 0): ?string
    {
        if ($depth > 24) {
            return null;
        }
        $type = fn (Expr $e) => $this->type($e, $context, $depth + 1);
        return match (true) {
            $expr instanceof Expr\Variable => is_string($expr->name)
                ? ($expr->name === 'this' ? $context->class : ($context->vars[$expr->name] ?? null))
                : null,
            $expr instanceof Expr\New_ => $expr->class instanceof Node\Name ? $this->className($expr->class, $context) : null,
            $expr instanceof Expr\Clone_ => $type($expr->expr),
            $expr instanceof Expr\Assign, $expr instanceof Expr\AssignRef => $type($expr->expr),
            $expr instanceof Expr\Ternary => $this->union([$expr->if !== null ? $type($expr->if) : $type($expr->cond), $type($expr->else)]),
            $expr instanceof Expr\BinaryOp\Coalesce => $this->union([$this->notNull($type($expr->left)), $type($expr->right)]),
            $expr instanceof Expr\Match_ => $this->union(array_map(fn ($arm) => $type($arm->body), $expr->arms)),
            $expr instanceof Expr\PropertyFetch, $expr instanceof Expr\NullsafePropertyFetch => $expr->name instanceof Node\Identifier
                ? $this->memberType($type($expr->var), 'props', $expr->name->toString())
                : null,
            $expr instanceof Expr\MethodCall, $expr instanceof Expr\NullsafeMethodCall => $expr->name instanceof Node\Identifier
                ? $this->memberType($type($expr->var), 'methods', $expr->name->toString(), $this->classArgs($expr->args, $context))
                : null,
            $expr instanceof Expr\StaticCall => $expr->name instanceof Node\Identifier
                ? $this->memberType($this->classOf($expr->class, $context, $depth), 'methods', $expr->name->toString(), $this->classArgs($expr->args, $context))
                : null,
            $expr instanceof Expr\StaticPropertyFetch => $expr->name instanceof Node\VarLikeIdentifier
                ? $this->memberType($this->classOf($expr->class, $context, $depth), 'props', $expr->name->toString())
                : null,
            $expr instanceof Expr\ClassConstFetch => $this->constType($expr, $context, $depth),
            $expr instanceof Expr\FuncCall => $expr->name instanceof Node\Name ? $this->functionType($expr->name, $this->classArgs($expr->args, $context)) : null,
            $expr instanceof Expr\ArrayDimFetch => $this->dimType($type($expr->var), $expr->dim),
            $expr instanceof Expr\Closure, $expr instanceof Expr\ArrowFunction => 'Closure',
            $expr instanceof Expr\Array_ => 'array',
            $expr instanceof Scalar\String_, $expr instanceof Scalar\InterpolatedString, $expr instanceof Expr\BinaryOp\Concat => 'string',
            $expr instanceof Scalar\Int_ => 'int',
            $expr instanceof Scalar\Float_ => 'float',
            $expr instanceof Expr\ConstFetch => match ($expr->name->toLowerString()) {
                'true', 'false' => 'bool', 'null' => 'null', default => null,
            },
            $expr instanceof Expr\Cast\Int_ => 'int',
            $expr instanceof Expr\Cast\String_ => 'string',
            $expr instanceof Expr\Cast\Array_ => 'array',
            $expr instanceof Expr\Cast\Double => 'float',
            $expr instanceof Expr\Cast\Bool_, $expr instanceof Expr\BooleanNot, $expr instanceof Expr\Isset_, $expr instanceof Expr\Empty_ => 'bool',
            default => null,
        };
    }

    /** The class an expression in class position names (Foo::, self::, $object::). */
    public function classOf(Node $class, Context $context, int $depth = 0): ?string
    {
        if ($class instanceof Node\Name) {
            return $this->className($class, $context);
        }
        return $class instanceof Expr ? $this->type($class, $context, $depth + 1) : null;
    }

    public function className(Node\Name $name, Context $context): ?string
    {
        $lower = $name->toLowerString();
        if ($lower === 'self' || $lower === 'static') {
            return $context->class;
        }
        if ($lower === 'parent') {
            $found = $context->class !== null ? $this->index->findClass($context->class) : null;
            return $found[1]['extends'][0] ?? null;
        }
        return $name->toString();
    }

    /**
     * The type of a member (property type, method return type) on a receiver type; "static", "self", "$this"
     * become the receiver, the class's templates (@template T) what the receiver says ("Box<Hero>": T = Hero).
     *
     * @param 'props'|'methods' $kind
     * @param array<int, string> $classArgs the call's arguments that are "Foo::class" (position => class)
     */
    public function memberType(?string $receiver, string $kind, string $name, array $classArgs = []): ?string
    {
        $types = [];
        foreach ($this->receivers($receiver) as $part) {
            $class = Types::base($part);
            $found = $this->index->findMember($class, $kind, $name);
            if ($found === null) {
                if ($kind === 'methods' && strtolower($class) === 'closure' && in_array(strtolower($name), ['bind', 'bindto', 'fromcallable'], true)) {
                    $types[] = 'Closure';
                }
                continue;
            }
            $type = $kind === 'props' ? ($found[2]['type'] ?? null) : ($found[2]['return'] ?? null);
            if ($type !== null) {
                $bindings = $this->bindings($class, Types::args($part), $found[1]['name']) + $this->callBindings($found[2], $classArgs);
                $types[] = $this->selfType(Types::substitute($type, $bindings), $part);
            }
        }
        return $this->union($types);
    }

    /** The class types of a receiver ("?Foo|Box<Hero>|null" -> ["Foo", "Box<Hero>"]). @return list<string> */
    private function receivers(?string $type): array
    {
        $out = [];
        foreach ($type === null ? [] : Types::split($type) as $part) {
            $part = ltrim($part, '?\\');
            if (!str_ends_with($part, '[]') && Types::classes($part) !== []) {
                $out[] = $part;
            }
        }
        return $out;
    }

    /**
     * What the templates of $owner (the class declaring a member) are, seen from $class with these generic
     * arguments: its own templates, else through the @extends / @implements of the classes in between.
     * Templates nobody gave become their bound (@template T of Foo), else mixed.
     *
     * @param list<string> $args
     * @return array<string, string>
     */
    public function bindings(string $class, array $args, string $owner, int $depth = 0): array
    {
        $found = $this->index->findClass($class);
        if ($found === null || $depth > 12) {
            return [];
        }
        $info = $found[1];
        $own = [];
        $i = 0;
        foreach ($info['templates'] ?? [] as $template => $bound) {
            $own[$template] = $args[$i++] ?? $bound ?? 'mixed';
        }
        if (strtolower($info['name']) === strtolower($owner)) {
            return $own;
        }
        foreach ([...$info['extends'], ...$info['implements'], ...$info['traits']] as $parent) {
            if (!$this->index->isA($parent, $owner)) {
                continue;
            }
            $generic = $info['inherits'][strtolower($parent)] ?? null;
            $parentArgs = $generic !== null ? array_map(fn ($a) => Types::substitute($a, $own), Types::args($generic)) : [];
            return $this->bindings($parent, $parentArgs, $owner, $depth + 1);
        }
        return [];
    }

    /**
     * A method's own templates given by its arguments: `@template T @param class-string<T> $class @return T`
     * called with Foo::class returns a Foo (containers, factories, repositories).
     *
     * @param array<string, mixed> $function
     * @param array<int, string> $classArgs
     * @return array<string, string>
     */
    private function callBindings(array $function, array $classArgs): array
    {
        $out = [];
        foreach ($function['templates'] ?? [] as $template) {
            foreach ($function['params'] ?? [] as $i => $param) {
                if (isset($classArgs[$i]) && preg_match('/^class-string<' . preg_quote($template, '/') . '>/', (string)($param['type'] ?? ''))) {
                    $out[$template] = $classArgs[$i];
                }
            }
            $out[$template] ??= 'mixed';
        }
        return $out;
    }

    /** @param array<Node\Arg|Node\VariadicPlaceholder> $args @return array<int, string> position => class of "Foo::class" */
    private function classArgs(array $args, Context $context): array
    {
        $out = [];
        foreach ($args as $i => $arg) {
            if ($arg instanceof Node\Arg && $arg->value instanceof Expr\ClassConstFetch && $arg->value->name instanceof Node\Identifier
                && $arg->value->name->toLowerString() === 'class') {
                $class = $this->classOf($arg->value->class, $context);
                if ($class !== null) {
                    $out[$i] = $class;
                }
            }
        }
        return $out;
    }

    /** $array['key'] / $array[$i]: a shape's field, else what the array or collection holds. */
    private function dimType(?string $type, ?Expr $dim): ?string
    {
        if ($type !== null && ($dim instanceof Scalar\String_ || $dim instanceof Scalar\Int_)) {
            $fields = [];
            foreach (Types::split((string)$type) as $part) {
                $shape = Types::shape($part);
                if ($shape !== null && isset($shape[$dim->value])) {
                    $fields[] = $shape[$dim->value];
                }
            }
            if ($fields) {
                return $this->union($fields);
            }
        }
        return $this->elementType($type);
    }

    /**
     * What a value holds when iterated or indexed: "Foo[]" and "array<int, Foo>" hold Foo, "array{a: Foo, b: int}"
     * Foo|int, a collection class what its getIterator() / current() / offsetGet() gives (generics followed).
     */
    public function elementType(?string $type, int $depth = 0): ?string
    {
        if ($type === null || $depth > 6) {
            return null;
        }
        $out = [];
        foreach (Types::split($type) as $part) {
            $part = ltrim($part, '?\\');
            if (str_ends_with($part, '[]')) {
                $out[] = substr($part, 0, -2);
            } elseif (($shape = Types::shape($part)) !== null) {
                array_push($out, ...array_values($shape));
            } elseif (in_array(strtolower(Types::base($part)), ['array', 'iterable', 'list', 'non-empty-array', 'non-empty-list'], true) && ($args = Types::args($part))) {
                $out[] = $args[count($args) - 1];
            } elseif (Types::classes($part) !== []) {
                $base = strtolower(Types::base($part));
                $args = Types::args($part);
                if ($args && in_array($base, ['traversable', 'iterator', 'iteratoraggregate', 'arrayiterator', 'arrayobject', 'generator', 'arrayaccess', 'splobjectstorage'], true)) {
                    $out[] = $base === 'generator' && count($args) > 1 ? $args[1] : ($base === 'splobjectstorage' ? $args[0] : $args[count($args) - 1]);
                    continue;
                }
                foreach (['getIterator', 'current', 'offsetGet'] as $method) {
                    $member = $this->memberType($part, 'methods', $method);
                    if ($member !== null && $member !== 'mixed') {
                        $inner = $method === 'getIterator' ? $this->elementType($member, $depth + 1) : $member;
                        if ($inner !== null && $inner !== 'mixed') {
                            $out[] = $inner;
                            break;
                        }
                    }
                }
            }
        }
        return $this->union($out);
    }

    private function constType(Expr\ClassConstFetch $expr, Context $context, int $depth): ?string
    {
        $class = $this->classOf($expr->class, $context, $depth);
        if ($class === null || !$expr->name instanceof Node\Identifier) {
            return null;
        }
        if ($expr->name->toLowerString() === 'class') {
            return 'string';
        }
        $found = $this->index->findMember($class, 'consts', $expr->name->toString());
        return $found !== null && ($found[2]['case'] ?? false) ? $found[1]['name'] : null;
    }

    /** @param array<int, string> $classArgs */
    private function functionType(Node\Name $name, array $classArgs = []): ?string
    {
        $namespaced = $name->getAttribute('namespacedName');
        $found = ($namespaced instanceof Node\Name ? $this->index->findFunction($namespaced->toString()) : null)
            ?? $this->index->findFunction($name->toString());
        $return = $found[1]['return'] ?? null;
        return $return !== null ? Types::substitute($return, $this->callBindings($found[1], $classArgs)) : null;
    }

    public function selfType(string $type, ?string $class): string
    {
        if ($class === null) {
            return $type;
        }
        return implode('|', array_map(
            fn ($t) => in_array(strtolower($t), ['self', 'static', '$this'], true) ? $class : $t,
            Types::split($type),
        ));
    }

    /** @param list<?string> $types */
    private function union(array $types): ?string
    {
        $parts = [];
        foreach ($types as $type) {
            if ($type !== null) {
                array_push($parts, ...Types::split($type));
            }
        }
        $parts = array_values(array_unique($parts));
        return $parts ? implode('|', $parts) : null;
    }

    private function notNull(?string $type): ?string
    {
        if ($type === null) {
            return null;
        }
        $parts = array_filter(Types::split($type), fn ($t) => $t !== 'null');
        return $parts ? implode('|', $parts) : null;
    }
}
