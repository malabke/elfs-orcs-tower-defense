<?php

namespace JumPHP\Ide\Php;

use PhpParser\Node;
use PhpParser\Node\Expr;
use PhpParser\Node\Stmt;
use PhpParser\NodeVisitorAbstract;

/** Walks a file up to a byte offset and fills a Context: what is known at that point. */
final class QueryVisitor extends NodeVisitorAbstract
{
    public Context $context;

    /** @var list<Node> */
    private array $stack = [];

    /** @var list<array{vars: array<string, string>, defs: array<string, int>, class: ?string}> */
    private array $scopes = [];

    /** @var list<?string> */
    private array $classes = [];

    private bool $captured = false;

    public function __construct(private int $offset, private TypeResolver $types)
    {
        $this->context = new Context();
    }

    public function enterNode(Node $node)
    {
        $start = (int)$node->getAttribute('startFilePos', -1);
        $end = (int)$node->getAttribute('endFilePos', -1);
        $this->stack[] = $node;
        $contains = $start <= $this->offset && $this->offset <= $end + 1;

        if ($node instanceof Stmt\Namespace_) {
            $this->context->namespace = $node->name?->toString() ?? '';
            $this->context->uses = [];
            $this->context->importAt = ['namespace' => $node->name ? (int)$node->name->getAttribute('endFilePos') + 2 : 0];
        } elseif ($node instanceof Stmt\Use_ || $node instanceof Stmt\GroupUse) {
            $prefix = $node instanceof Stmt\GroupUse ? $node->prefix->toString() . '\\' : '';
            foreach ($node->uses as $use) {
                if (($node->type !== Stmt\Use_::TYPE_UNKNOWN ? $node->type : $use->type) === Stmt\Use_::TYPE_NORMAL) {
                    $this->context->uses[strtolower($use->getAlias()->toString())] = $prefix . $use->name->toString();
                }
            }
            $this->context->importAt['use'] = $end + 1;
        } elseif ($node instanceof Stmt\ClassLike) {
            $this->classes[] = $node->name !== null ? ($node->namespacedName?->toString() ?? $node->name->toString()) : null;
        } elseif ($node instanceof Node\FunctionLike) {
            $this->enterFunction($node);
        }

        if ($contains && !$this->captured) {
            $this->context->path[] = $node;
            $this->context->class = $this->currentClass();
            if ($node instanceof Node\FunctionLike) {
                $this->context->function = $node;
            }
        }
        return null;
    }

    public function leaveNode(Node $node)
    {
        array_pop($this->stack);
        $end = (int)$node->getAttribute('endFilePos', -1);
        if ($node instanceof Expr\Assign || $node instanceof Expr\AssignRef) {
            if ($end < $this->offset) {
                $this->assign($node->var, $node->expr);
            }
        } elseif ($node instanceof Stmt\Foreach_ && (int)$node->expr->getAttribute('endFilePos', 0) < $this->offset) {
            $element = $this->types->elementType($this->types->type($node->expr, $this->snapshot()));
            if ($node->valueVar instanceof Expr\Variable && is_string($node->valueVar->name)) {
                $this->define($node->valueVar->name, $element !== 'mixed' ? $element : null, $node->valueVar);
            }
            if ($node->keyVar instanceof Expr\Variable && is_string($node->keyVar->name)) {
                $this->define($node->keyVar->name, null, $node->keyVar);
            }
        } elseif ($node instanceof Stmt\Catch_ && $node->var !== null && is_string($node->var->name)
            && (int)$node->getAttribute('startFilePos', 0) <= $this->offset) {
            $this->define($node->var->name, implode('|', array_map(fn ($t) => $t->toString(), $node->types)), $node->var);
        } elseif ($node instanceof Expr\Instanceof_ && $node->expr instanceof Expr\Variable && is_string($node->expr->name)
            && $node->class instanceof Node\Name && $end < $this->offset) {
            // `if ($x instanceof Foo)`: good enough for completion inside the if
            $this->define($node->expr->name, $node->class->toString(), null);
        } elseif ($node instanceof Expr\List_ || $node instanceof Expr\Array_) {
            // [$a, $b] = ...: known variables, no type
            foreach ($node->items as $item) {
                if ($item?->value instanceof Expr\Variable && is_string($item->value->name) && $end < $this->offset) {
                    $this->define($item->value->name, null, $item->value);
                }
            }
        } elseif ($node instanceof Stmt\Global_ || $node instanceof Stmt\Static_) {
            foreach ($node->vars as $var) {
                $v = $var instanceof Stmt\StaticVar ? $var->var : $var;
                if ($v instanceof Expr\Variable && is_string($v->name)) {
                    $this->define($v->name, null, $v);
                }
            }
        }

        $contains = $this->context->path && in_array($node, $this->context->path, true);
        if ($node instanceof Node\FunctionLike) {
            $scope = array_pop($this->scopes);
            if ($contains && !$this->captured && $scope !== null) {
                $this->context->vars = $scope['vars'];
                $this->context->varDefinitions = $scope['defs'];
                $this->captured = true;
            }
        } elseif ($node instanceof Stmt\ClassLike) {
            array_pop($this->classes);
        }
        return null;
    }

    public function afterTraverse(array $nodes)
    {
        if (!$this->captured && $this->scopes === []) {
            // top-level code: what was assigned before the position
            $this->context->vars = $this->topVars;
            $this->context->varDefinitions = $this->topDefs;
        }
        return null;
    }

    /** @var array<string, string> */
    private array $topVars = [];

    /** @var array<string, int> */
    private array $topDefs = [];

    private function enterFunction(Node\FunctionLike $node): void
    {
        $class = $this->currentClass();
        $vars = [];
        $defs = [];
        if ($node instanceof Expr\ArrowFunction && $this->scopes) {
            $outer = $this->scopes[count($this->scopes) - 1];
            $vars = $outer['vars'];
            $defs = $outer['defs'];
        } elseif ($node instanceof Expr\Closure) {
            $outer = $this->scopes ? $this->scopes[count($this->scopes) - 1] : ['vars' => $this->topVars, 'defs' => $this->topDefs];
            foreach ($node->uses as $use) {
                if (is_string($use->var->name)) {
                    $name = $use->var->name;
                    if (isset($outer['vars'][$name])) {
                        $vars[$name] = $outer['vars'][$name];
                    }
                    $defs[$name] = (int)$use->var->getAttribute('startFilePos', 0);
                }
            }
        }
        $doc = DocBlock::parse($node->getDocComment()?->getText());
        foreach ($node->getParams() as $param) {
            if ($param->var instanceof Expr\Variable && is_string($param->var->name)) {
                $name = $param->var->name;
                $type = isset($doc->params[$name])
                    ? Types::resolveDoc($doc->params[$name], $this->context->namespace, $this->context->uses)
                    : Types::fromNode($param->type);
                if ($param->variadic && $type !== null && !str_contains($type, '|')) {
                    $type .= '[]';
                }
                if ($type !== null) {
                    $vars[$name] = $this->types->selfType($type, $class);
                }
                $defs[$name] = (int)$param->var->getAttribute('startFilePos', 0);
            }
        }
        $this->scopes[] = ['vars' => $vars, 'defs' => $defs, 'class' => $class];
    }

    private function assign(Expr $var, Expr $expr): void
    {
        if ($var instanceof Expr\Variable && is_string($var->name)) {
            $type = $this->docType($var) ?? $this->types->type($expr, $this->snapshot());
            $this->define($var->name, $type, $var);
        } elseif ($var instanceof Expr\List_ || $var instanceof Expr\Array_) {
            foreach ($var->items as $item) {
                if ($item?->value instanceof Expr\Variable && is_string($item->value->name)) {
                    $this->define($item->value->name, null, $item->value);
                }
            }
        }
    }

    /** `/** @var Foo $x *\/` just above the assignment. */
    private function docType(Expr\Variable $var): ?string
    {
        foreach (array_reverse($this->stack) as $node) {
            if ($node instanceof Stmt\Expression) {
                $doc = DocBlock::parse($node->getDocComment()?->getText());
                if ($doc->var !== null && ($doc->varName === null || $doc->varName === $var->name)) {
                    return Types::resolveDoc($doc->var, $this->context->namespace, $this->context->uses);
                }
                return null;
            }
        }
        return null;
    }

    private function define(string $name, ?string $type, ?Expr\Variable $at): void
    {
        $offset = $at !== null ? (int)$at->getAttribute('startFilePos', 0) : null;
        if ($this->scopes) {
            $i = count($this->scopes) - 1;
            if ($type !== null) {
                $this->scopes[$i]['vars'][$name] = $type;
            }
            if ($offset !== null) {
                $this->scopes[$i]['defs'][$name] ??= $offset;
            }
        } else {
            if ($type !== null) {
                $this->topVars[$name] = $type;
            }
            if ($offset !== null) {
                $this->topDefs[$name] ??= $offset;
            }
        }
    }

    /** The context so far (for typing expressions while walking). */
    private function snapshot(): Context
    {
        $context = clone $this->context;
        $context->class = $this->currentClass();
        $scope = $this->scopes ? $this->scopes[count($this->scopes) - 1] : ['vars' => $this->topVars];
        $context->vars = $scope['vars'];
        return $context;
    }

    private function currentClass(): ?string
    {
        return $this->classes ? $this->classes[count($this->classes) - 1] : null;
    }
}
