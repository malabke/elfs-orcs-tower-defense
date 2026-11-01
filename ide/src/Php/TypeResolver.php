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
                ? $this->memberType($type($expr->var), 'methods', $expr->name->toString())
                : null,
            $expr instanceof Expr\StaticCall => $expr->name instanceof Node\Identifier
                ? $this->memberType($this->classOf($expr->class, $context, $depth), 'methods', $expr->name->toString())
                : null,
            $expr instanceof Expr\StaticPropertyFetch => $expr->name instanceof Node\VarLikeIdentifier
                ? $this->memberType($this->classOf($expr->class, $context, $depth), 'props', $expr->name->toString())
                : null,
            $expr instanceof Expr\ClassConstFetch => $this->constType($expr, $context, $depth),
            $expr instanceof Expr\FuncCall => $expr->name instanceof Node\Name ? $this->functionType($expr->name) : null,
            $expr instanceof Expr\ArrayDimFetch => $this->elementType($type($expr->var)),
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
     * become the receiver's class.
     *
     * @param 'props'|'methods' $kind
     */
    public function memberType(?string $receiver, string $kind, string $name): ?string
    {
        $types = [];
        foreach (Types::classes($receiver) as $class) {
            $found = $this->index->findMember($class, $kind, $name);
            if ($found === null) {
                if ($kind === 'methods' && strtolower($class) === 'closure' && in_array(strtolower($name), ['bind', 'bindto', 'fromcallable'], true)) {
                    $types[] = 'Closure';
                }
                continue;
            }
            $type = $kind === 'props' ? ($found[2]['type'] ?? null) : ($found[2]['return'] ?? null);
            if ($type !== null) {
                $types[] = $this->selfType($type, $class);
            }
        }
        return $this->union($types);
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

    private function functionType(Node\Name $name): ?string
    {
        $namespaced = $name->getAttribute('namespacedName');
        $found = ($namespaced instanceof Node\Name ? $this->index->findFunction($namespaced->toString()) : null)
            ?? $this->index->findFunction($name->toString());
        return $found[1]['return'] ?? null;
    }

    private function elementType(?string $type): ?string
    {
        $classes = Types::classes($type, true);
        return $classes ? implode('|', $classes) : null;
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
