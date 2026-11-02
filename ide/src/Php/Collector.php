<?php

namespace JumPHP\Ide\Php;

use PhpParser\Node;
use PhpParser\Node\Expr;
use PhpParser\Node\Stmt;
use PhpParser\NodeVisitorAbstract;

/** The visitor behind Analyzer::analyze(). */
final class Collector extends NodeVisitorAbstract
{
    private string $namespace = '';

    /** @var array<string, string> */
    private array $uses = [];

    /** @var array<string, array<string, mixed>> */
    private array $classes = [];

    /** @var array<string, array<string, mixed>> */
    private array $functions = [];

    /** @var array<string, array{0: int, 1: int}> */
    private array $constants = [];

    /** @var list<array{0: string, 1: string, 2: int, 3: int, 4: int, 5: bool}> */
    private array $refs = [];

    /** @var list<string|null> lower fqn of the classes being visited */
    private array $classStack = [];

    /** @var list<array<string, string>> variable types of the functions being visited */
    private array $scopes = [];

    public function __construct(private Source $source)
    {
    }

    /** @return array<string, mixed> */
    public function result(): array
    {
        return ['namespace' => $this->namespace, 'uses' => $this->uses, 'classes' => $this->classes,
            'functions' => $this->functions, 'constants' => $this->constants, 'refs' => $this->refs];
    }

    public function enterNode(Node $node)
    {
        if ($node instanceof Stmt\Namespace_) {
            $this->namespace = $node->name?->toString() ?? '';
            $this->uses = [];
        } elseif ($node instanceof Stmt\Use_ || $node instanceof Stmt\GroupUse) {
            $prefix = $node instanceof Stmt\GroupUse ? $node->prefix->toString() . '\\' : '';
            foreach ($node->uses as $use) {
                $type = $node->type !== Stmt\Use_::TYPE_UNKNOWN ? $node->type : $use->type;
                $fqn = $prefix . $use->name->toString();
                if ($type === Stmt\Use_::TYPE_NORMAL) {
                    $this->uses[strtolower($use->getAlias()->toString())] = $fqn;
                    $this->nameRef('class', $fqn, $use->name);
                }
            }
        } elseif ($node instanceof Stmt\ClassLike) {
            $this->enterClass($node);
        } elseif ($node instanceof Stmt\ClassMethod) {
            $this->enterMethod($node);
        } elseif ($node instanceof Stmt\Function_) {
            $this->enterFunction($node);
        } elseif ($node instanceof Expr\Closure) {
            $scope = ['this' => $this->currentClass() ?? ''];
            foreach ($node->uses as $use) {
                $name = is_string($use->var->name) ? $use->var->name : null;
                if ($name !== null && isset($this->scope()[$name])) {
                    $scope[$name] = $this->scope()[$name];
                }
            }
            $this->pushScope($node, $scope);
        } elseif ($node instanceof Expr\ArrowFunction) {
            $this->pushScope($node, $this->scope());
        } elseif ($node instanceof Stmt\Const_) {
            foreach ($node->consts as $const) {
                $this->constants[$this->namespaced($const->name->toString())] = $this->at($const->name);
            }
        } else {
            $this->reference($node);
        }
        return null;
    }

    public function leaveNode(Node $node)
    {
        if ($node instanceof Stmt\ClassLike) {
            array_pop($this->classStack);
        } elseif ($node instanceof Node\FunctionLike) {
            array_pop($this->scopes);
        } elseif ($node instanceof Expr\Assign && $node->var instanceof Expr\Variable && is_string($node->var->name)) {
            $type = $this->exprType($node->expr);
            if ($type !== null && $this->scopes) {
                $this->scopes[count($this->scopes) - 1][$node->var->name] = $type;
            }
        } elseif ($node instanceof Stmt\Foreach_ && $node->valueVar instanceof Expr\Variable && is_string($node->valueVar->name)) {
            $elements = Types::classes($this->exprType($node->expr), true);
            if ($elements && $this->scopes) {
                $this->scopes[count($this->scopes) - 1][$node->valueVar->name] = implode('|', $elements);
            }
        }
        return null;
    }

    private function enterClass(Stmt\ClassLike $node): void
    {
        if ($node->name === null) {
            $this->classStack[] = null;
            return;
        }
        $fqn = $node->namespacedName?->toString() ?? $this->namespaced($node->name->toString());
        $doc = DocBlock::parse($node->getDocComment()?->getText());
        [$line, $col] = $this->at($node->name);
        $class = [
            'name' => $fqn,
            'kind' => match (true) {
                $node instanceof Stmt\Interface_ => 'interface',
                $node instanceof Stmt\Trait_ => 'trait',
                $node instanceof Stmt\Enum_ => 'enum',
                default => 'class',
            },
            'line' => $line, 'col' => $col, 'endLine' => $node->getEndLine(),
            'extends' => [], 'implements' => [], 'traits' => [],
            'abstract' => $node instanceof Stmt\Class_ && $node->isAbstract(),
            'final' => $node instanceof Stmt\Class_ && $node->isFinal(),
            'doc' => $doc->summary, 'deprecated' => $doc->deprecated,
            'methods' => [], 'props' => [], 'consts' => [],
        ];
        $templates = array_keys($doc->templates);
        if ($templates) {
            // generics (@template T): their bounds, and what the ancestors' templates are here (@extends Base<T>)
            $class['templates'] = array_map(fn ($bound) => $bound !== null ? Types::resolveDoc($bound, $this->namespace, $this->uses) : null, $doc->templates);
        }
        foreach ($doc->inherits as $inherit) {
            $resolved = Types::resolveDoc($inherit, $this->namespace, $this->uses, $templates);
            $class['inherits'][strtolower(Types::base($resolved))] = $resolved;
        }
        if ($node instanceof Stmt\Class_) {
            if ($node->extends) {
                $class['extends'][] = $node->extends->toString();
                $this->nameRef('class', $node->extends->toString(), $node->extends);
            }
            foreach ($node->implements as $name) {
                $class['implements'][] = $name->toString();
                $this->nameRef('class', $name->toString(), $name);
            }
        } elseif ($node instanceof Stmt\Interface_) {
            foreach ($node->extends as $name) {
                $class['extends'][] = $name->toString();
                $this->nameRef('class', $name->toString(), $name);
            }
        } elseif ($node instanceof Stmt\Enum_) {
            foreach ($node->implements as $name) {
                $class['implements'][] = $name->toString();
                $this->nameRef('class', $name->toString(), $name);
            }
        }
        $context = [$this->namespace, $this->uses, $templates];
        foreach ($doc->properties as $name => $type) {
            $class['props'][$name] = ['type' => Types::resolveDoc($type, ...$context), 'static' => false,
                'vis' => 'public', 'doc' => '', 'line' => $line, 'col' => $col, 'magic' => true];
        }
        foreach ($doc->methods as $name => $method) {
            $class['methods'][strtolower($name)] = ['name' => $name, 'static' => $method['static'], 'vis' => 'public',
                'params' => $this->magicParams($method['params']), 'return' => Types::resolveDoc($method['type'], ...$context),
                'doc' => '', 'line' => $line, 'col' => $col, 'abstract' => false, 'magic' => true];
        }
        foreach ($node->stmts as $stmt) {
            if ($stmt instanceof Stmt\TraitUse) {
                foreach ($stmt->traits as $name) {
                    $class['traits'][] = $name->toString();
                    $this->nameRef('class', $name->toString(), $name);
                }
            } elseif ($stmt instanceof Stmt\Property) {
                $pdoc = DocBlock::parse($stmt->getDocComment()?->getText());
                $type = Types::fromNode($stmt->type);
                $this->typeRefs($stmt->type);
                foreach ($stmt->props as $prop) {
                    $name = $prop->name->toString();
                    $class['props'][$name] = [
                        'type' => $pdoc->var !== null ? Types::resolveDoc($pdoc->var, ...$context) : $type,
                        'static' => $stmt->isStatic(), 'vis' => $this->visibility($stmt),
                        'readonly' => $stmt->isReadonly(), 'doc' => $pdoc->summary,
                    ] + $this->varAt($prop->name);
                }
            } elseif ($stmt instanceof Stmt\ClassConst) {
                foreach ($stmt->consts as $const) {
                    [$cl, $cc] = $this->at($const->name);
                    $class['consts'][$const->name->toString()] = ['line' => $cl, 'col' => $cc, 'vis' => $this->visibility($stmt),
                        'value' => $this->shortValue($const->value), 'case' => false];
                }
            } elseif ($stmt instanceof Stmt\EnumCase) {
                [$cl, $cc] = $this->at($stmt->name);
                $class['consts'][$stmt->name->toString()] = ['line' => $cl, 'col' => $cc, 'vis' => 'public',
                    'value' => $stmt->expr ? $this->shortValue($stmt->expr) : '', 'case' => true];
            }
        }
        $key = strtolower($fqn);
        $this->classes[$key] = $class;
        $this->classStack[] = $key;
    }

    private function enterMethod(Stmt\ClassMethod $node): void
    {
        $class = $this->currentClass();
        $info = $this->functionInfo($node);
        $info['static'] = $node->isStatic();
        $info['vis'] = $this->visibility($node);
        $info['abstract'] = $node->isAbstract() || ($class !== null && ($this->classes[$class]['kind'] ?? '') === 'interface');
        if ($class !== null && isset($this->classes[$class])) {
            $this->classes[$class]['methods'][strtolower($node->name->toString())] = $info;
            if ($node->name->toLowerString() === '__construct') {
                // promoted properties
                foreach ($node->params as $param) {
                    if ($param->flags !== 0 && $param->var instanceof Expr\Variable && is_string($param->var->name)) {
                        $vis = $param->flags & Stmt\Class_::MODIFIER_PRIVATE ? 'private' : ($param->flags & Stmt\Class_::MODIFIER_PROTECTED ? 'protected' : 'public');
                        $this->classes[$class]['props'][$param->var->name] = ['type' => Types::fromNode($param->type), 'static' => false,
                            'vis' => $vis, 'readonly' => (bool)($param->flags & Stmt\Class_::MODIFIER_READONLY), 'doc' => ''] + $this->varAt($param->var);
                    }
                }
            }
        }
        $this->pushScope($node, ['this' => $class ?? '']);
    }

    private function enterFunction(Stmt\Function_ $node): void
    {
        $fqn = $node->namespacedName?->toString() ?? $this->namespaced($node->name->toString());
        $this->functions[strtolower($fqn)] = ['name' => $fqn] + $this->functionInfo($node);
        $this->pushScope($node, []);
    }

    /** @return array<string, mixed> */
    private function functionInfo(Stmt\ClassMethod|Stmt\Function_ $node): array
    {
        $doc = DocBlock::parse($node->getDocComment()?->getText());
        $context = [$this->namespace, $this->uses, [...$this->classTemplates(), ...array_keys($doc->templates)]];
        $params = [];
        foreach ($node->params as $param) {
            $name = $param->var instanceof Expr\Variable && is_string($param->var->name) ? $param->var->name : '?';
            $type = Types::fromNode($param->type);
            $this->typeRefs($param->type);
            $params[] = [
                'name' => $name,
                'type' => isset($doc->params[$name]) ? Types::resolveDoc($doc->params[$name], ...$context) : $type,
                'hint' => $type,
                'default' => $param->default !== null ? $this->shortValue($param->default) : null,
                'variadic' => $param->variadic, 'byRef' => $param->byRef,
            ];
        }
        $this->typeRefs($node->returnType);
        $return = Types::fromNode($node->returnType);
        if ($doc->return !== null) {
            $return = Types::resolveDoc($doc->return, ...$context);
        }
        [$line, $col] = $this->at($node->name);
        return ['name' => $node->name->toString(), 'params' => $params, 'return' => $return, 'doc' => $doc->summary,
            'deprecated' => $doc->deprecated, 'line' => $line, 'col' => $col, 'endLine' => $node->getEndLine()]
            + ($doc->templates ? ['templates' => array_keys($doc->templates)] : []);
    }

    /** @param array<string, string> $vars */
    private function pushScope(Node\FunctionLike $node, array $vars): void
    {
        $doc = DocBlock::parse($node->getDocComment()?->getText());
        $context = [$this->namespace, $this->uses, [...$this->classTemplates(), ...array_keys($doc->templates)]];
        foreach ($node->getParams() as $param) {
            if ($param->var instanceof Expr\Variable && is_string($param->var->name)) {
                $name = $param->var->name;
                $type = isset($doc->params[$name]) ? Types::resolveDoc($doc->params[$name], ...$context) : Types::fromNode($param->type);
                if ($type !== null) {
                    $vars[$name] = $this->selfType($type);
                }
            }
        }
        $this->scopes[] = $vars;
    }

    private function reference(Node $node): void
    {
        if ($node instanceof Expr\New_ && $node->class instanceof Node\Name) {
            $this->nameRef('class', $this->className($node->class), $node->class);
        } elseif ($node instanceof Expr\Instanceof_ && $node->class instanceof Node\Name) {
            $this->nameRef('class', $this->className($node->class), $node->class);
        } elseif ($node instanceof Stmt\Catch_) {
            foreach ($node->types as $type) {
                $this->nameRef('class', $type->toString(), $type);
            }
        } elseif ($node instanceof Node\Attribute) {
            $this->nameRef('class', $node->name->toString(), $node->name);
        } elseif ($node instanceof Expr\FuncCall && $node->name instanceof Node\Name) {
            $name = $node->name->getAttribute('namespacedName')?->toString() ?? $node->name->toString();
            $this->identRef('function', $name, $node->name, true);
        } elseif (($node instanceof Expr\MethodCall || $node instanceof Expr\NullsafeMethodCall) && $node->name instanceof Node\Identifier) {
            $type = Types::classes($this->exprType($node->var));
            $this->identRef('method', ($type[0] ?? '?') . '::' . $node->name->toString(), $node->name, count($type) === 1);
        } elseif ($node instanceof Expr\StaticCall && $node->name instanceof Node\Identifier && $node->class instanceof Node\Name) {
            $class = $this->className($node->class);
            $this->nameRef('class', $class, $node->class);
            $this->identRef('method', "$class::" . $node->name->toString(), $node->name, true);
        } elseif (($node instanceof Expr\PropertyFetch || $node instanceof Expr\NullsafePropertyFetch) && $node->name instanceof Node\Identifier) {
            $type = Types::classes($this->exprType($node->var));
            $this->identRef('prop', ($type[0] ?? '?') . '::' . $node->name->toString(), $node->name, count($type) === 1);
        } elseif ($node instanceof Expr\StaticPropertyFetch && $node->name instanceof Node\VarLikeIdentifier && $node->class instanceof Node\Name) {
            $class = $this->className($node->class);
            $this->nameRef('class', $class, $node->class);
            $this->identRef('prop', "$class::" . $node->name->toString(), $node->name, true, 1);
        } elseif ($node instanceof Expr\ClassConstFetch && $node->class instanceof Node\Name) {
            $class = $this->className($node->class);
            $this->nameRef('class', $class, $node->class);
            if ($node->name instanceof Node\Identifier && $node->name->toLowerString() !== 'class') {
                $this->identRef('const', "$class::" . $node->name->toString(), $node->name, true);
            }
        } elseif ($node instanceof Expr\ConstFetch) {
            $name = $node->name->toLowerString();
            if (!in_array($name, ['true', 'false', 'null'], true)) {
                $this->identRef('gconst', $node->name->getAttribute('namespacedName')?->toString() ?? $node->name->toString(), $node->name, true);
            }
        }
    }

    /** The type of an expression from this file alone (null: unknown). */
    private function exprType(Expr $expr): ?string
    {
        $class = $this->currentClass();
        if ($expr instanceof Expr\Variable && is_string($expr->name)) {
            if ($expr->name === 'this') {
                return $class !== null ? ($this->classes[$class]['name'] ?? null) : null;
            }
            return $this->scope()[$expr->name] ?? null;
        }
        if ($expr instanceof Expr\New_ && $expr->class instanceof Node\Name) {
            return $this->className($expr->class);
        }
        if ($expr instanceof Expr\Clone_ || $expr instanceof Expr\Assign) {
            return $this->exprType($expr instanceof Expr\Clone_ ? $expr->expr : $expr->expr);
        }
        if ($expr instanceof Expr\Ternary) {
            return $this->exprType($expr->else);
        }
        if ($expr instanceof Expr\BinaryOp\Coalesce) {
            return $this->exprType($expr->left) ?? $this->exprType($expr->right);
        }
        if (($expr instanceof Expr\PropertyFetch || $expr instanceof Expr\NullsafePropertyFetch) && $expr->name instanceof Node\Identifier) {
            return $this->memberType($this->exprType($expr->var), 'props', $expr->name->toString());
        }
        if (($expr instanceof Expr\MethodCall || $expr instanceof Expr\NullsafeMethodCall) && $expr->name instanceof Node\Identifier) {
            return $this->memberType($this->exprType($expr->var), 'methods', $expr->name->toLowerString());
        }
        if ($expr instanceof Expr\StaticCall && $expr->class instanceof Node\Name && $expr->name instanceof Node\Identifier) {
            return $this->memberType($this->className($expr->class), 'methods', $expr->name->toLowerString());
        }
        return null;
    }

    /** A member's type when its class is in this file. */
    private function memberType(?string $type, string $kind, string $name): ?string
    {
        foreach (Types::classes($type) as $class) {
            $member = $this->classes[strtolower($class)][$kind][$name] ?? null;
            if ($member !== null) {
                $result = $kind === 'props' ? $member['type'] : $member['return'];
                return $result === null ? null : $this->selfType($result, $class);
            }
        }
        return null;
    }

    private function selfType(string $type, ?string $class = null): string
    {
        $class ??= $this->currentClass() !== null ? ($this->classes[$this->currentClass()]['name'] ?? null) : null;
        if ($class === null) {
            return $type;
        }
        return implode('|', array_map(fn ($t) => in_array($t, ['self', 'static', '$this'], true) ? $class : $t, Types::split($type)));
    }

    private function className(Node\Name $name): string
    {
        $lower = $name->toLowerString();
        $class = $this->currentClass();
        if (in_array($lower, ['self', 'static'], true) && $class !== null) {
            return $this->classes[$class]['name'] ?? $name->toString();
        }
        if ($lower === 'parent' && $class !== null) {
            return $this->classes[$class]['extends'][0] ?? 'parent';
        }
        return $name->toString();
    }

    private function typeRefs(?Node $type): void
    {
        if ($type instanceof Node\NullableType) {
            $this->typeRefs($type->type);
        } elseif ($type instanceof Node\UnionType || $type instanceof Node\IntersectionType) {
            foreach ($type->types as $t) {
                $this->typeRefs($t);
            }
        } elseif ($type instanceof Node\Name && !in_array($type->toLowerString(), ['self', 'static', 'parent'], true)) {
            $this->nameRef('class', $type->toString(), $type);
        }
    }

    /** A reference whose identifier is the last part of a (qualified) name. */
    private function nameRef(string $kind, string $target, Node\Name $name): void
    {
        if (in_array(strtolower($target), ['self', 'static', 'parent'], true) || !$name->hasAttribute('startFilePos')) {
            return;
        }
        $original = $name->getAttribute('originalName');
        $text = $original instanceof Node\Name ? $original->toString() : $name->toString();
        if (in_array(strtolower($text), ['self', 'static', 'parent'], true)) {
            return;
        }
        $last = $original instanceof Node\Name ? $original->getLast() : $name->getLast();
        $end = (int)$name->getAttribute('endFilePos') + 1;
        [$line, $col] = $this->source->position($end - strlen($last));
        $this->refs[] = [$kind, ltrim($target, '\\'), $line, $col, Source::utf16Length($last), true];
    }

    private function identRef(string $kind, string $target, Node $ident, bool $certain, int $skip = 0): void
    {
        if (!$ident->hasAttribute('startFilePos')) {
            return;
        }
        $start = (int)$ident->getAttribute('startFilePos');
        $end = (int)$ident->getAttribute('endFilePos') + 1;
        if ($ident instanceof Node\Name) {
            $original = $ident->getAttribute('originalName');
            $last = $original instanceof Node\Name ? $original->getLast() : $ident->getLast();
            $start = $end - strlen($last);
        }
        if ($skip === 0 && ($this->source->text[$start] ?? '') === '$') {
            $skip = 1;
        }
        [$line, $col] = $this->source->position($start + $skip);
        $this->refs[] = [$kind, ltrim($target, '\\'), $line, $col, Source::utf16Length(substr($this->source->text, $start + $skip, $end - $start - $skip)), $certain];
    }

    /** @return array{0: int, 1: int} */
    private function at(Node $node): array
    {
        return $this->source->position((int)$node->getAttribute('startFilePos', 0));
    }

    /** Position of a variable-like name, after its "$". @return array{line: int, col: int} */
    private function varAt(Node $node): array
    {
        $start = (int)$node->getAttribute('startFilePos', 0);
        if (($this->source->text[$start] ?? '') === '$') {
            $start++;
        }
        [$line, $col] = $this->source->position($start);
        return ['line' => $line, 'col' => $col];
    }

    private function visibility(Stmt\ClassMethod|Stmt\Property|Stmt\ClassConst $node): string
    {
        return $node->isPrivate() ? 'private' : ($node->isProtected() ? 'protected' : 'public');
    }

    private function shortValue(Expr $expr): string
    {
        $start = (int)$expr->getAttribute('startFilePos', 0);
        $end = (int)$expr->getAttribute('endFilePos', -1) + 1;
        $text = preg_replace('/\s+/', ' ', substr($this->source->text, $start, max(0, $end - $start))) ?? '';
        return mb_strlen($text) > 40 ? mb_substr($text, 0, 37) . '...' : $text;
    }

    /** @return list<array<string, mixed>> */
    private function magicParams(string $params): array
    {
        $out = [];
        foreach (array_filter(array_map('trim', explode(',', $params))) as $param) {
            if (preg_match('/^(?:(\S+)\s+)?(\.\.\.)?&?\$(\w+)(?:\s*=\s*(.+))?$/', $param, $m)) {
                $out[] = ['name' => $m[3], 'type' => $m[1] !== '' ? Types::resolveDoc($m[1], $this->namespace, $this->uses) : null,
                    'hint' => null, 'default' => isset($m[4]) ? $m[4] : null, 'variadic' => $m[2] !== '', 'byRef' => false];
            }
        }
        return $out;
    }

    private function namespaced(string $name): string
    {
        return $this->namespace === '' ? $name : "$this->namespace\\$name";
    }

    /** @return list<string> the template names of the class being read */
    private function classTemplates(): array
    {
        $class = $this->currentClass();
        return array_keys($class !== null ? $this->classes[$class]['templates'] ?? [] : []);
    }

    private function currentClass(): ?string
    {
        return $this->classStack ? $this->classStack[count($this->classStack) - 1] : null;
    }

    /** @return array<string, string> */
    private function scope(): array
    {
        return $this->scopes ? $this->scopes[count($this->scopes) - 1] : [];
    }
}
