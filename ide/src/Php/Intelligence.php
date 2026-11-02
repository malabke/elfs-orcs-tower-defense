<?php

namespace JumPHP\Ide\Php;

use PhpParser\Node;
use PhpParser\Node\Expr;
use PhpParser\Node\Stmt;
use PhpParser\NodeTraverser;
use PhpParser\NodeVisitor\NameResolver;

/**
 * The editor's PHP features, on a file's current text (saved or not): completion, go to definition, find
 * usages, rename, hover, parameter hints, problems, structure. Positions as the editor counts them (Source).
 */
final class Intelligence
{
    private TypeResolver $types;

    public function __construct(private Index $index)
    {
        $this->types = new TypeResolver($index);
    }

    public function index(): Index
    {
        return $this->index;
    }

    // context -------------------------------------------------------------------------------------------------

    /** What is known at a byte offset of a text. */
    public function context(string $text, int $offset): Context
    {
        [$stmts] = Analyzer::parse($text);
        $visitor = new QueryVisitor($offset, $this->types);
        (new NodeTraverser($visitor))->traverse($stmts);
        return $visitor->context;
    }

    /**
     * What is known at a byte offset, from the text before it only: the rest is replaced by what closes the
     * brackets left open, so a line being typed (an unfinished call, a "$this->") still parses.
     */
    public function contextBefore(string $text, int $offset): Context
    {
        $prefix = substr($text, 0, $offset);
        $open = [];
        foreach (@token_get_all($prefix) as $token) {
            $t = is_array($token) ? $token[1] : $token;
            if (is_array($token) && in_array($token[0], [T_CURLY_OPEN, T_DOLLAR_OPEN_CURLY_BRACES], true)) {
                $open[] = '}';
            } elseif ($t === '(' || $t === '[' || $t === '{') {
                $open[] = ['(' => ')', '[' => ']', '{' => '}'][$t];
            } elseif (($t === ')' || $t === ']' || $t === '}') && $open) {
                array_pop($open);
            }
        }
        $repair = 'jphComplete';
        $statement = false;
        while ($open) {
            $close = array_pop($open);
            if ($close === '}' && !$statement) {
                $repair .= ';';
                $statement = true;
            }
            $repair .= $close;
        }
        if (!$statement) {
            $repair .= ';';
        }
        return $this->context($prefix . $repair, $offset);
    }

    /** Parses an expression written in a file's context (its namespace and imports). */
    private function expression(string $code, Context $context): ?Expr
    {
        $header = '<?php ' . ($context->namespace !== '' ? "namespace $context->namespace; " : '');
        foreach ($context->uses as $alias => $fqn) {
            $header .= "use $fqn as $alias; ";
        }
        $errors = new \PhpParser\ErrorHandler\Collecting();
        $stmts = Analyzer::parser()->parse($header . $code . ';', $errors) ?? [];
        $stmts = (new NodeTraverser(new NameResolver(null, ['replaceNodes' => true])))->traverse($stmts);
        $last = null;
        $find = function (array $nodes) use (&$find, &$last): void {
            foreach ($nodes as $node) {
                if ($node instanceof Stmt\Expression) {
                    $last = $node->expr;
                } elseif ($node instanceof Stmt\Namespace_) {
                    $find($node->stmts);
                }
            }
        };
        $find($stmts);
        return $last;
    }

    /**
     * The expression written just before $end in the text ("$this->engine->config" before "->"), scanning back
     * through names, variables, calls and brackets.
     */
    public static function expressionBefore(string $text, int $end): string
    {
        $i = $end;
        $depth = 0;
        while ($i > 0) {
            $c = $text[$i - 1];
            if ($c === ')' || $c === ']') {
                $depth++;
                $i--;
                continue;
            }
            if ($c === '(' || $c === '[') {
                if ($depth === 0) {
                    break;
                }
                $depth--;
                $i--;
                continue;
            }
            if ($depth > 0) {
                if ($c === '"' || $c === "'") {
                    $quote = $c;
                    $i--;
                    while ($i > 0 && ($text[$i - 1] !== $quote || ($i > 1 && $text[$i - 2] === '\\'))) {
                        $i--;
                    }
                }
                $i--;
                continue;
            }
            if (ctype_alnum($c) || $c === '_' || $c === '$' || $c === '\\' || ord($c) >= 0x80) {
                $i--;
                continue;
            }
            if ($c === '>' && $i >= 2 && $text[$i - 2] === '-') {
                $i -= ($i >= 3 && $text[$i - 3] === '?') ? 3 : 2;
                continue;
            }
            if ($c === ':' && $i >= 2 && $text[$i - 2] === ':') {
                $i -= 2;
                continue;
            }
            if (($c === ' ' || $c === "\t" || $c === "\n" || $c === "\r")) {
                // "new Foo" / whitespace inside a chain written over several lines
                $j = $i - 1;
                while ($j > 0 && ctype_space($text[$j - 1])) {
                    $j--;
                }
                if (substr($text, max(0, $j - 3), 3) === 'new' && ($j < 4 || !ctype_alnum($text[$j - 4]))) {
                    $i = $j - 3;
                    break;
                }
                $next = ltrim(substr($text, $i, 3));
                if (str_starts_with($next, '->') || str_starts_with($next, '?->') || str_starts_with($next, '::')) {
                    $i = $j;
                    continue;
                }
                break;
            }
            break;
        }
        return trim(substr($text, $i, $end - $i));
    }

    // completion --------------------------------------------------------------------------------------------

    /** @return array{items: list<array<string, mixed>>, incomplete: bool} */
    public function complete(string $path, string $text, int $line, int $column): array
    {
        $source = new Source($text);
        $offset = $source->offset($line, $column);
        $before = substr($text, 0, $offset);
        preg_match('/[A-Za-z_\x80-\xff][\w\x80-\xff]*$/', $before, $m);
        $word = $m[0] ?? '';
        $start = $offset - strlen($word);
        $lead = substr($text, 0, $start);

        $context = $this->contextBefore($text, $offset);

        if (preg_match('/(\?->|->)$/', $lead, $op)) {
            $expr = $this->expression(self::expressionBefore($lead, $start - strlen($op[1])), $context);
            $type = $expr !== null ? $this->types->type($expr, $context) : null;
            return ['items' => $this->memberItems($type, $context, false, $word, $expr), 'incomplete' => false];
        }
        if (preg_match('/::\$?$/', $lead)) {
            $dollar = str_ends_with($lead, '$');
            $classText = self::expressionBefore($lead, $start - ($dollar ? 3 : 2));
            $expr = $this->expression($classText . '::class', $context);
            $class = $expr instanceof Expr\ClassConstFetch ? $this->types->classOf($expr->class, $context) : null;
            $parentCall = in_array(strtolower($classText), ['parent', 'self', 'static'], true);
            return ['items' => $this->memberItems($class, $context, true, $word, null, $parentCall, $dollar), 'incomplete' => false];
        }
        if (str_ends_with($lead, '$')) {
            return ['items' => $this->variableItems($context, $offset), 'incomplete' => false];
        }
        if (preg_match('/\[\s*([\'"])$/', $lead, $quote)) {
            // $array['…: the keys of an array shape (@return array{hero: Hero, count: int})
            $bracket = (int)strrpos($lead, '[');
            $context = $this->contextBefore($text, $bracket);   // before the quote left open
            $expr = $this->expression(self::expressionBefore($lead, $bracket), $context);
            $type = $expr !== null ? $this->types->type($expr, $context) : null;
            $items = [];
            foreach ($type !== null ? Types::split($type) : [] as $part) {
                foreach (Types::shape($part) ?? [] as $key => $field) {
                    if (is_string($key)) {
                        $items[$key] = ['label' => $key, 'kind' => 'field', 'detail' => $field, 'insertText' => $key, 'sortText' => '0' . $key];
                    }
                }
            }
            return ['items' => array_values($items), 'incomplete' => false];
        }
        return ['items' => $this->wordItems($lead, $word, $context, $source, $start), 'incomplete' => true];
    }

    /** @return list<array<string, mixed>> */
    private function memberItems(?string $type, Context $context, bool $static, string $word, ?Expr $expr, bool $parentCall = false, bool $dollar = false): array
    {
        $items = [];
        $seen = [];
        foreach (Types::classes($type) as $class) {
            if (!$dollar) {
                foreach ($this->index->members($class, 'methods') as $key => [$method, $owner]) {
                    if (isset($seen["m$key"]) || !$this->visible($method, $owner, $context)
                        || ($static && !$method['static'] && !$parentCall) || (!$static && $method['static'] && !$this->isThis($expr))
                        || ($key === '__construct' && !$parentCall) || (str_starts_with($key, '__') && $key !== '__construct' && !$parentCall)) {
                        continue;
                    }
                    $seen["m$key"] = true;
                    $items[] = $this->methodItem($method, $owner);
                }
            }
            foreach ($this->index->members($class, 'props') as $name => [$prop, $owner]) {
                if (isset($seen["p$name"]) || !$this->visible($prop, $owner, $context) || $prop['static'] !== $static) {
                    continue;
                }
                $seen["p$name"] = true;
                $items[] = ['label' => $static ? '$' . $name : $name, 'kind' => 'property', 'detail' => $prop['type'] ?? 'mixed',
                    'documentation' => $prop['doc'] ?? '', 'description' => Index::short($owner['name']),
                    'insertText' => $static ? '$' . $name : $name, 'sortText' => '1' . $name, 'deprecated' => false];
            }
            if ($static && !$dollar) {
                foreach ($this->index->members($class, 'consts') as $name => [$const, $owner]) {
                    if (isset($seen["c$name"])) {
                        continue;
                    }
                    $seen["c$name"] = true;
                    $items[] = ['label' => $name, 'kind' => $const['case'] ? 'enumMember' : 'constant',
                        'detail' => $const['value'] !== '' ? '= ' . $const['value'] : '', 'description' => Index::short($owner['name']),
                        'insertText' => $name, 'sortText' => '0' . $name];
                }
                $items[] = ['label' => 'class', 'kind' => 'keyword', 'detail' => 'class name', 'insertText' => 'class', 'sortText' => '3class'];
            }
        }
        return $items;
    }

    private function isThis(?Expr $expr): bool
    {
        return $expr instanceof Expr\Variable && $expr->name === 'this';
    }

    /** @param array<string, mixed> $member @param array<string, mixed> $owner */
    private function visible(array $member, array $owner, Context $context): bool
    {
        $vis = $member['vis'] ?? 'public';
        if ($vis === 'public') {
            return true;
        }
        if ($context->class === null) {
            return false;
        }
        if ($vis === 'private') {
            return strtolower($owner['name']) === strtolower($context->class) || $owner['kind'] === 'trait';
        }
        return $this->index->isA($context->class, $owner['name']) || $this->index->isA($owner['name'], $context->class);
    }

    /** @param array<string, mixed> $method @param array<string, mixed> $owner @return array<string, mixed> */
    private function methodItem(array $method, array $owner): array
    {
        $name = $method['name'];
        return ['label' => $name, 'kind' => 'method', 'detail' => $this->signature($method, false),
            'documentation' => $method['doc'] ?? '', 'description' => Index::short($owner['name']),
            'insertText' => $method['params'] ? "$name(\$0)" : "$name()", 'snippet' => true,
            'sortText' => '2' . $name, 'deprecated' => $method['deprecated'] ?? false, 'parameterHints' => (bool)$method['params']];
    }

    /** @return list<array<string, mixed>> */
    private function variableItems(Context $context, int $offset): array
    {
        $items = [];
        $names = array_unique([...array_keys($context->varDefinitions), ...array_keys($context->vars)]);
        foreach ($names as $name) {
            if ($name === 'jphComplete') {
                continue;
            }
            $items[] = ['label' => '$' . $name, 'kind' => 'variable', 'detail' => $context->vars[$name] ?? '',
                'insertText' => $name, 'filterText' => $name, 'sortText' => '0' . $name];
        }
        if ($context->class !== null) {
            $items[] = ['label' => '$this', 'kind' => 'variable', 'detail' => $context->class, 'insertText' => 'this', 'filterText' => 'this', 'sortText' => '0this'];
        }
        foreach (Builtins::SUPERGLOBALS as $name) {
            if ($name !== 'this') {
                $items[] = ['label' => '$' . $name, 'kind' => 'variable', 'detail' => 'superglobal', 'insertText' => $name, 'filterText' => $name, 'sortText' => '9' . $name];
            }
        }
        return $items;
    }

    /** @return list<array<string, mixed>> */
    private function wordItems(string $lead, string $word, Context $context, Source $source, int $start): array
    {
        $items = [];
        $trimmed = rtrim($lead);
        $classOnly = (bool)preg_match('/(?:\bnew|\binstanceof|\bextends|\bimplements|\binsteadof|#\[|\bcatch\s*\(|\|)\s*$/i', $trimmed)
            || preg_match('/(?:^|\n)\s*use\s+[\w\\\\]*$/i', $lead . $word);
        $inUse = (bool)preg_match('/(?:^|\n)\s*use\s+[\w\\\\]*$/i', $lead . $word);
        $qualified = preg_match('/([\w\\\\]*\\\\)$/', $lead, $q) ? $q[1] : '';
        $query = $word;

        // classes (auto-import)
        foreach ($this->index->classNames(ltrim($qualified . $query, '\\'), 150) as $class) {
            $items[] = $this->classItem($class['name'], $class['kind'], $context, $source, $inUse, $qualified);
        }
        if ($qualified === '' && $query !== '') {
            foreach (Builtins::classNames() as $class) {
                if (Index::fuzzy($query, $class) !== null && !str_contains($class, '\\')) {
                    $items[] = ['label' => $class, 'kind' => 'class', 'detail' => 'PHP', 'insertText' => $context->namespace !== '' && !$inUse ? '\\' . $class : $class,
                        'filterText' => $class, 'sortText' => '6' . $class];
                }
            }
        }
        if ($classOnly) {
            return array_slice($items, 0, 300);
        }
        if ($qualified === '') {
            $own = $this->index->files();
            foreach ($this->index->functionList() as $function) {
                // the libraries' and extensions' functions (many): only those matching what is typed
                if ($query === '' ? !isset($own[$function['path']]) : Index::fuzzy($query, Index::short($function['name'])) === null) {
                    continue;
                }
                $item = ['label' => Index::short($function['name']), 'kind' => 'function', 'detail' => $this->signature($function, false),
                    'documentation' => $function['doc'] ?? '', 'insertText' => Index::short($function['name']) . ($function['params'] ? '($0)' : '()'),
                    'snippet' => true, 'sortText' => '4' . $function['name'], 'parameterHints' => (bool)$function['params']];
                // a function of another namespace (a library's): "use function" added with it
                $namespace = ($pos = strrpos($function['name'], '\\')) !== false ? substr($function['name'], 0, $pos) : '';
                if ($namespace !== '' && strcasecmp($namespace, $context->namespace) !== 0
                    && !preg_match('/^\s*use\s+function\s+' . preg_quote($function['name'], '/') . '\s*;/mi', $source->text)) {
                    $item['description'] = $namespace;
                    $item['additionalTextEdits'] = [$this->importEdit($function['name'], $context, $source, 'function')];
                }
                $items[] = $item;
            }
            if ($query !== '') {
                $count = 0;
                foreach (Builtins::functionNames() as $function) {
                    if (Index::fuzzy($query, $function) !== null && $count++ < 400) {
                        $info = Builtins::functionInfo($function);
                        $items[] = ['label' => $function, 'kind' => 'function', 'detail' => $info ? $this->signature($info, false) : '',
                            'insertText' => $function . ($info && $info['params'] ? '($0)' : '()'), 'snippet' => true,
                            'sortText' => '5' . $function, 'deprecated' => $info['deprecated'] ?? false, 'parameterHints' => (bool)($info['params'] ?? false)];
                    }
                }
                if (strtoupper($query) === $query) {
                    foreach (Builtins::constantNames() as $constant) {
                        if (str_starts_with($constant, $query)) {
                            $items[] = ['label' => $constant, 'kind' => 'constant', 'insertText' => $constant, 'sortText' => '7' . $constant];
                        }
                    }
                }
            }
            foreach (Builtins::KEYWORDS as $keyword) {
                $items[] = ['label' => $keyword, 'kind' => 'keyword', 'insertText' => $keyword, 'sortText' => '3' . $keyword];
            }
        }
        return array_slice($items, 0, 600);
    }

    /** @return array<string, mixed> a class completion, importing it when needed */
    private function classItem(string $fqn, string $kind, Context $context, Source $source, bool $inUse, string $qualified): array
    {
        $short = Index::short($fqn);
        $item = ['label' => $short, 'kind' => $kind, 'detail' => $fqn, 'filterText' => $short, 'sortText' => '5' . $short, 'insertText' => $short];
        if ($inUse) {
            $item['insertText'] = ltrim(substr($fqn, strlen(ltrim($qualified, '\\'))), '\\');
            $item['filterText'] = $fqn;
            return $item;
        }
        if ($qualified !== '') {
            $item['insertText'] = ltrim(substr($fqn, strlen(ltrim($qualified, '\\'))), '\\');
            return $item;
        }
        $namespace = ($pos = strrpos($fqn, '\\')) !== false ? substr($fqn, 0, $pos) : '';
        $alias = strtolower($short);
        if (isset($context->uses[$alias])) {
            if (strcasecmp($context->uses[$alias], $fqn) !== 0) {
                $item['insertText'] = '\\' . $fqn;
            }
            return $item;
        }
        if (strcasecmp($namespace, $context->namespace) === 0) {
            $item['sortText'] = '4' . $short;
            return $item;
        }
        if ($namespace === '' && $context->namespace !== '') {
            $item['insertText'] = '\\' . $fqn;
            return $item;
        }
        $item['additionalTextEdits'] = [$this->importEdit($fqn, $context, $source)];
        return $item;
    }

    /** @return array<string, mixed> the edit adding "use $fqn;" */
    public function importEdit(string $fqn, Context $context, Source $source, string $kind = ''): array
    {
        $fqn = $kind !== '' ? "$kind $fqn" : $fqn;
        if (isset($context->importAt['use'])) {
            $at = $context->importAt['use'];
            $text = "\nuse $fqn;";
        } elseif (isset($context->importAt['namespace']) && $context->importAt['namespace'] > 0) {
            $at = $context->importAt['namespace'];
            $text = "\nuse $fqn;\n";
        } else {
            $at = (int)(strpos($source->text, "\n") ?: 0);
            $text = "\nuse $fqn;\n";
        }
        return ['range' => $source->range($at, $at), 'text' => $text];
    }

    // symbols ------------------------------------------------------------------------------------------------

    /**
     * The symbol at a position: kind (class, method, prop, const, function, gconst, var), name, class, its
     * declaration, the range of the identifier under the cursor.
     *
     * @return array<string, mixed>|null
     */
    public function symbolAt(string $path, string $text, int $line, int $column): ?array
    {
        $source = new Source($text);
        $offset = $source->offset($line, $column);
        $context = $this->context($text, $offset);
        $node = $context->innermost();
        $parent = $context->parent();
        if ($node === null) {
            return null;
        }
        $range = $source->range((int)$node->getAttribute('startFilePos', 0), (int)$node->getAttribute('endFilePos', 0) + 1);
        $symbol = $this->symbolOf($node, $parent, $context, $path, $source);
        if ($symbol === null) {
            return null;
        }
        if ($symbol['kind'] !== 'var' && in_array($symbol['kind'], ['method', 'prop', 'const'], true) && $node instanceof Node\Name === false) {
            // the identifier only, not "$"
            $start = (int)$node->getAttribute('startFilePos', 0);
            if (($text[$start] ?? '') === '$') {
                $range = $source->range($start + 1, (int)$node->getAttribute('endFilePos', 0) + 1);
            }
        }
        if ($node instanceof Node\Name) {
            $end = (int)$node->getAttribute('endFilePos', 0) + 1;
            $original = $node->getAttribute('originalName');
            $last = $original instanceof Node\Name ? $original->getLast() : $node->getLast();
            $range = $source->range($end - strlen($last), $end);
        }
        return $symbol + ['range' => $range];
    }

    /** @return array<string, mixed>|null */
    private function symbolOf(Node $node, ?Node $parent, Context $context, string $path, Source $source): ?array
    {
        $class = fn (string $name) => ['kind' => 'class', 'name' => ltrim($name, '\\'), 'class' => null];
        if ($node instanceof Node\Name) {
            if ($parent instanceof Expr\FuncCall) {
                $namespaced = $node->getAttribute('namespacedName');
                $name = $namespaced instanceof Node\Name && $this->index->findFunction($namespaced->toString()) ? $namespaced->toString() : $node->toString();
                return ['kind' => 'function', 'name' => $name, 'class' => null];
            }
            if ($parent instanceof Expr\ConstFetch) {
                return ['kind' => 'gconst', 'name' => $node->toString(), 'class' => null];
            }
            $resolved = $this->types->className($node, $context);
            return $resolved !== null ? $class($resolved) : null;
        }
        if ($node instanceof Node\Identifier) {
            $name = $node->toString();
            return match (true) {
                $parent instanceof Stmt\ClassLike => $class($context->class ?? $name),
                $parent instanceof Stmt\ClassMethod => ['kind' => 'method', 'name' => $name, 'class' => $context->class],
                $parent instanceof Stmt\Function_ => ['kind' => 'function', 'name' => $parent->namespacedName?->toString() ?? $name, 'class' => null],
                $parent instanceof Node\PropertyItem => ['kind' => 'prop', 'name' => $name, 'class' => $context->class],
                $parent instanceof Node\Const_ && $context->parent(2) instanceof Stmt\ClassConst => ['kind' => 'const', 'name' => $name, 'class' => $context->class],
                $parent instanceof Stmt\EnumCase => ['kind' => 'const', 'name' => $name, 'class' => $context->class],
                $parent instanceof Expr\MethodCall, $parent instanceof Expr\NullsafeMethodCall
                    => ['kind' => 'method', 'name' => $name, 'class' => $this->ownerOf($this->types->type($parent->var, $context), 'methods', $name)],
                $parent instanceof Expr\PropertyFetch, $parent instanceof Expr\NullsafePropertyFetch
                    => ['kind' => 'prop', 'name' => $name, 'class' => $this->ownerOf($this->types->type($parent->var, $context), 'props', $name)],
                $parent instanceof Expr\StaticCall
                    => ['kind' => 'method', 'name' => $name, 'class' => $this->ownerOf($this->types->classOf($parent->class, $context), 'methods', $name)],
                $parent instanceof Expr\StaticPropertyFetch
                    => ['kind' => 'prop', 'name' => $name, 'class' => $this->ownerOf($this->types->classOf($parent->class, $context), 'props', $name)],
                $parent instanceof Expr\ClassConstFetch && strtolower($name) !== 'class'
                    => ['kind' => 'const', 'name' => $name, 'class' => $this->ownerOf($this->types->classOf($parent->class, $context), 'consts', $name)],
                $parent instanceof Node\Param && $context->parent(2) instanceof Stmt\ClassMethod => null,
                default => null,
            };
        }
        if ($node instanceof Expr\Variable && is_string($node->name)) {
            if ($node->name === 'this') {
                return $context->class !== null ? $class($context->class) : null;
            }
            if ($parent instanceof Node\Param && $parent->flags !== 0 && $context->parent(2) instanceof Stmt\ClassMethod) {
                return ['kind' => 'prop', 'name' => $node->name, 'class' => $context->class];
            }
            return ['kind' => 'var', 'name' => $node->name, 'class' => null, 'definition' => $context->varDefinitions[$node->name] ?? null,
                'function' => $context->function];
        }
        return null;
    }

    /** The class (of a receiver type) that declares the member, else the first class of the type. */
    private function ownerOf(?string $type, string $kind, string $name): ?string
    {
        $classes = Types::classes($type);
        foreach ($classes as $class) {
            $found = $this->index->findMember($class, $kind, $name);
            if ($found !== null) {
                return $found[1]['name'];
            }
        }
        return $classes[0] ?? null;
    }

    /** @return list<array{path: string, line: int, col: int}> where the symbol is declared */
    public function definition(string $path, string $text, int $line, int $column): array
    {
        $symbol = $this->symbolAt($path, $text, $line, $column);
        if ($symbol === null) {
            return [];
        }
        if ($symbol['kind'] === 'var') {
            if ($symbol['definition'] === null) {
                return [];
            }
            [$l, $c] = (new Source($text))->position($symbol['definition'] + 1);
            return [['path' => $path, 'line' => $l, 'col' => $c]];
        }
        $found = $this->declarations($symbol);
        if ($found === [] && in_array($symbol['kind'], ['method', 'prop', 'const'], true) && $symbol['class'] === null) {
            // the receiver's type is unknown: every member of that name in the project
            $kind = ['method' => 'methods', 'prop' => 'props', 'const' => 'consts'][$symbol['kind']];
            $key = $symbol['kind'] === 'method' ? strtolower($symbol['name']) : $symbol['name'];
            foreach ($this->index->files() as $p => $entry) {
                foreach ($entry['classes'] as $class) {
                    if (isset($class[$kind][$key]) && empty($class[$kind][$key]['magic'])) {
                        $found[] = ['path' => $p, 'line' => $class[$kind][$key]['line'], 'col' => $class[$kind][$key]['col']];
                    }
                }
            }
        }
        return $found;
    }

    /** @param array<string, mixed> $symbol @return list<array{path: string, line: int, col: int}> */
    public function declarations(array $symbol): array
    {
        $at = fn (string $path, array $info) => $path !== '' && ($info['line'] ?? 0) > 0 ? [['path' => $path, 'line' => $info['line'], 'col' => $info['col']]] : [];
        switch ($symbol['kind']) {
            case 'class':
                $found = $this->index->findClass($symbol['name']);
                return $found ? $at($found[0], $found[1]) : [];
            case 'function':
                $found = $this->index->findFunction($symbol['name']);
                return $found ? $at($found[0], $found[1]) : [];
            case 'method':
            case 'prop':
            case 'const':
                if ($symbol['class'] === null) {
                    return [];
                }
                $kind = ['method' => 'methods', 'prop' => 'props', 'const' => 'consts'][$symbol['kind']];
                $found = $this->index->findMember($symbol['class'], $kind, $symbol['name']);
                return $found ? $at($found[0], $found[2]) : [];
            case 'gconst':
                foreach ($this->index->files() as $path => $entry) {
                    foreach ([$symbol['name'], Index::short($symbol['name'])] as $name) {
                        if (isset($entry['constants'][$name])) {
                            return [['path' => $path, 'line' => $entry['constants'][$name][0], 'col' => $entry['constants'][$name][1]]];
                        }
                    }
                }
                return [];
        }
        return [];
    }

    /**
     * Every use of the symbol at a position (declarations included).
     *
     * @return list<array{path: string, line: int, col: int, length: int, certain: bool, declaration: bool, text: string}>
     */
    public function references(string $path, string $text, int $line, int $column, bool $declarations = true): array
    {
        $this->index->update($path, $text);
        $symbol = $this->symbolAt($path, $text, $line, $column);
        if ($symbol === null) {
            return [];
        }
        if ($symbol['kind'] === 'var') {
            return $this->variableReferences($path, $text, $symbol);
        }
        return $this->symbolReferences($symbol, $declarations);
    }

    /** @param array<string, mixed> $symbol @return list<array<string, mixed>> */
    public function symbolReferences(array $symbol, bool $declarations = true): array
    {
        $out = [];
        $name = $symbol['name'];
        $kind = $symbol['kind'];
        $class = $symbol['class'] ?? null;
        $lower = strtolower($name);
        $lines = [];
        $line = function (string $path, int $n) use (&$lines): string {
            if (!isset($lines[$path])) {
                try {
                    $lines[$path] = new Source($this->index->workspace()->read($path));
                } catch (\Throwable) {
                    return '';
                }
            }
            return rtrim($lines[$path]->line($n));
        };
        $length = Source::utf16Length($kind === 'class' ? Index::short($name) : $name);
        foreach ($this->index->files() as $path => $entry) {
            foreach ($entry['refs'] as [$refKind, $target, $l, $c, $len, $certain]) {
                $match = false;
                $sure = (bool)$certain;
                if ($kind === 'class' && $refKind === 'class') {
                    $match = strtolower($target) === $lower;
                } elseif ($kind === 'function' && $refKind === 'function') {
                    $match = strtolower($target) === $lower || (strtolower(Index::short($target)) === $lower && !str_contains($name, '\\')
                        && $this->index->findFunction($target) === null);
                } elseif ($kind === 'gconst' && $refKind === 'gconst') {
                    $match = $target === $name || Index::short($target) === Index::short($name);
                } elseif (in_array($kind, ['method', 'prop', 'const'], true) && $refKind === $kind) {
                    [$owner, $member] = explode('::', $target, 2) + [1 => ''];
                    $same = $kind === 'method' ? strtolower($member) === $lower : $member === $name;
                    if ($same) {
                        if ($owner === '?' || $class === null) {
                            $match = true;
                            $sure = false;
                        } else {
                            $match = $this->related($owner, $class);
                        }
                    }
                }
                if ($match) {
                    $out[] = ['path' => $path, 'line' => $l, 'col' => $c, 'length' => $len, 'certain' => $sure, 'declaration' => false, 'text' => $line($path, $l)];
                }
            }
        }
        if ($declarations) {
            foreach ($this->relatedDeclarations($symbol) as $declaration) {
                $out[] = $declaration + ['length' => $length, 'certain' => true, 'declaration' => true, 'text' => $line($declaration['path'], $declaration['line'])];
            }
        }
        usort($out, fn ($a, $b) => [$a['path'], $a['line'], $a['col']] <=> [$b['path'], $b['line'], $b['col']]);
        $unique = [];
        foreach ($out as $ref) {
            $unique[$ref['path'] . ':' . $ref['line'] . ':' . $ref['col']] ??= $ref;
        }
        return array_values($unique);
    }

    /** Declarations of the symbol, and for members, of the same member in parents and subclasses (overrides). */
    private function relatedDeclarations(array $symbol): array
    {
        $out = $this->declarations($symbol);
        if (in_array($symbol['kind'], ['method', 'prop'], true) && $symbol['class'] !== null) {
            $kind = $symbol['kind'] === 'method' ? 'methods' : 'props';
            $key = $symbol['kind'] === 'method' ? strtolower($symbol['name']) : $symbol['name'];
            $classes = [...$this->index->hierarchy($symbol['class']), ...$this->index->subclasses($symbol['class'])];
            foreach ($classes as [$path, $info]) {
                if ($path !== '' && isset($info[$kind][$key]) && empty($info[$kind][$key]['magic'])) {
                    $out[] = ['path' => $path, 'line' => $info[$kind][$key]['line'], 'col' => $info[$kind][$key]['col']];
                }
            }
        }
        return $out;
    }

    private function related(string $a, string $b): bool
    {
        return strcasecmp($a, $b) === 0 || $this->index->isA($a, $b) || $this->index->isA($b, $a);
    }

    /** @param array<string, mixed> $symbol @return list<array<string, mixed>> */
    private function variableReferences(string $path, string $text, array $symbol): array
    {
        $source = new Source($text);
        $function = $symbol['function'];
        [$stmts] = Analyzer::parse($text);
        $nodes = $function instanceof Node\FunctionLike ? [$function] : $stmts;
        $out = [];
        $name = $symbol['name'];
        $walk = function (array $nodes, bool $top) use (&$walk, &$out, $name, $source, $path, $function): void {
            foreach ($nodes as $node) {
                if (!$node instanceof Node) {
                    if (is_array($node)) {
                        $walk($node, $top);
                    }
                    continue;
                }
                if (!$top && $node instanceof Node\FunctionLike && $node !== $function) {
                    if ($node instanceof Expr\ArrowFunction) {
                        $walk([$node->expr], $top);
                    } elseif ($node instanceof Expr\Closure) {
                        foreach ($node->uses as $use) {
                            $walk([$use->var], $top);
                        }
                    }
                    continue;
                }
                if ($node instanceof Expr\Variable && $node->name === $name) {
                    $start = (int)$node->getAttribute('startFilePos') + 1;
                    [$l, $c] = $source->position($start);
                    $out[] = ['path' => $path, 'line' => $l, 'col' => $c, 'length' => Source::utf16Length($name), 'certain' => true,
                        'declaration' => false, 'text' => rtrim($source->line($l))];
                }
                foreach ($node->getSubNodeNames() as $sub) {
                    $child = $node->$sub;
                    if ($child instanceof Node || is_array($child)) {
                        $walk(is_array($child) ? $child : [$child], false);
                    }
                }
            }
        };
        if ($function instanceof Node\FunctionLike) {
            foreach ($function->getSubNodeNames() as $sub) {
                $child = $function->$sub;
                if ($child instanceof Node || is_array($child)) {
                    $walk(is_array($child) ? $child : [$child], false);
                }
            }
        } else {
            $walk($nodes, true);
        }
        return $out;
    }

    /**
     * The edits renaming the symbol at a position, grouped by file, and the uses that might be it but are not
     * certain (left out).
     *
     * @return array{edits: array<string, list<array<string, mixed>>>, uncertain: list<array<string, mixed>>, symbol: array<string, mixed>|null, renameFile: ?array{from: string, to: string}}
     */
    public function rename(string $path, string $text, int $line, int $column, string $newName): array
    {
        $newName = ltrim(trim($newName), '$');
        if (!preg_match('/^[A-Za-z_\x80-\xff][\w\x80-\xff]*$/', $newName)) {
            throw new \InvalidArgumentException("'$newName' is not a valid name");
        }
        $refs = $this->references($path, $text, $line, $column);
        $symbol = $this->symbolAt($path, $text, $line, $column);
        $edits = [];
        $uncertain = [];
        foreach ($refs as $ref) {
            if (!$ref['certain']) {
                $uncertain[] = $ref;
                continue;
            }
            $edits[$ref['path']][] = ['line' => $ref['line'], 'col' => $ref['col'], 'length' => $ref['length'], 'text' => $newName];
        }
        $renameFile = null;
        if ($symbol !== null && $symbol['kind'] === 'class') {
            $found = $this->index->findClass($symbol['name']);
            if ($found !== null && $found[0] !== '' && basename($found[0]) === Index::short($symbol['name']) . '.php') {
                $renameFile = ['from' => $found[0], 'to' => (dirname($found[0]) === '.' ? '' : dirname($found[0]) . '/') . $newName . '.php'];
            }
        }
        unset($symbol['function']);
        return ['edits' => $edits, 'uncertain' => $uncertain, 'symbol' => $symbol, 'renameFile' => $renameFile];
    }

    // hover, signature ---------------------------------------------------------------------------------------

    /** @return array{contents: string, range: array<string, int>}|null markdown about the symbol at a position */
    public function hover(string $path, string $text, int $line, int $column): ?array
    {
        $symbol = $this->symbolAt($path, $text, $line, $column);
        if ($symbol === null) {
            return null;
        }
        $md = null;
        switch ($symbol['kind']) {
            case 'class':
                $found = $this->index->findClass($symbol['name']);
                if ($found) {
                    $c = $found[1];
                    $head = ($c['abstract'] ? 'abstract ' : '') . ($c['final'] ? 'final ' : '') . $c['kind'] . ' ' . $c['name'];
                    if ($c['extends']) {
                        $head .= ' extends ' . implode(', ', array_map([Index::class, 'short'], $c['extends']));
                    }
                    if ($c['implements']) {
                        $head .= ' implements ' . implode(', ', array_map([Index::class, 'short'], $c['implements']));
                    }
                    $md = $this->code($head) . $this->doc($c);
                }
                break;
            case 'function':
                $found = $this->index->findFunction($symbol['name']);
                $md = $found ? $this->code('function ' . $this->signature($found[1], true)) . $this->doc($found[1]) : null;
                break;
            case 'method':
            case 'prop':
            case 'const':
                if ($symbol['class'] !== null) {
                    $kind = ['method' => 'methods', 'prop' => 'props', 'const' => 'consts'][$symbol['kind']];
                    $found = $this->index->findMember($symbol['class'], $kind, $symbol['name']);
                    if ($found) {
                        [, $owner, $m] = $found;
                        $code = match ($symbol['kind']) {
                            'method' => ($m['vis'] ?? 'public') . ($m['static'] ? ' static' : '') . ' function ' . $this->signature($m, true),
                            'prop' => ($m['vis'] ?? 'public') . ($m['static'] ? ' static' : '') . ' ' . ($m['type'] ?? 'mixed') . ' $' . $symbol['name'],
                            default => ($m['case'] ? 'case ' : 'const ') . $symbol['name'] . ($m['value'] !== '' ? ' = ' . $m['value'] : ''),
                        };
                        $md = '*' . $owner['name'] . "*\n\n" . $this->code($code) . $this->doc($m);
                    }
                }
                break;
            case 'var':
                $context = $this->context($text, (new Source($text))->offset($line, $column));
                $md = $this->code(($context->vars[$symbol['name']] ?? 'mixed') . ' $' . $symbol['name']);
                break;
        }
        return $md === null ? null : ['contents' => $md, 'range' => $symbol['range']];
    }

    /** @return array<string, mixed>|null the call around a position: its parameters and the current one */
    public function signatureHelp(string $path, string $text, int $line, int $column): ?array
    {
        $source = new Source($text);
        $offset = $source->offset($line, $column);
        $depth = 0;
        $commas = 0;
        $open = null;
        for ($i = $offset - 1; $i >= 0 && $i > $offset - 4000; $i--) {
            $c = $text[$i];
            if ($c === '"' || $c === "'") {
                $quote = $c;
                $i--;
                while ($i > 0 && ($text[$i] !== $quote || $text[$i - 1] === '\\')) {
                    $i--;
                }
                continue;
            }
            if ($c === ')' || $c === ']' || $c === '}') {
                $depth++;
            } elseif ($c === '(' || $c === '[' || $c === '{') {
                if ($depth === 0) {
                    if ($c === '(') {
                        $open = $i;
                    }
                    break;
                }
                $depth--;
            } elseif ($c === ',' && $depth === 0) {
                $commas++;
            } elseif ($c === ';' && $depth === 0) {
                break;
            }
        }
        if ($open === null) {
            return null;
        }
        $callee = self::expressionBefore($text, $open);
        if ($callee === '' || !preg_match('/[\w\x80-\xff]$/', $callee)) {
            return null;
        }
        $context = $this->contextBefore($text, $open);
        $expr = $this->expression("$callee()", $context);
        $info = null;
        if ($expr instanceof Expr\FuncCall && $expr->name instanceof Node\Name) {
            $info = ($this->index->findFunction($context->namespace . '\\' . $expr->name->toString()) ?? $this->index->findFunction($expr->name->toString()))[1] ?? null;
        } elseif (($expr instanceof Expr\MethodCall || $expr instanceof Expr\NullsafeMethodCall) && $expr->name instanceof Node\Identifier) {
            foreach (Types::classes($this->types->type($expr->var, $context)) as $class) {
                $info ??= $this->index->findMember($class, 'methods', $expr->name->toString())[2] ?? null;
            }
        } elseif ($expr instanceof Expr\StaticCall && $expr->name instanceof Node\Identifier) {
            $class = $this->types->classOf($expr->class, $context);
            $info = $class !== null ? ($this->index->findMember($class, 'methods', $expr->name->toString())[2] ?? null) : null;
        } elseif ($expr instanceof Expr\New_ && $expr->class instanceof Node\Name) {
            $class = $this->types->className($expr->class, $context);
            $info = $class !== null ? ($this->index->findMember($class, 'methods', '__construct')[2] ?? null) : null;
        }
        if ($info === null) {
            return null;
        }
        $params = array_map(fn ($p) => $this->param($p), $info['params']);
        $active = $commas;
        $last = count($params) - 1;
        if ($last >= 0 && $active > $last && ($info['params'][$last]['variadic'] ?? false)) {
            $active = $last;
        }
        return ['label' => $info['name'] . '(' . implode(', ', $params) . ')' . (($info['return'] ?? null) ? ': ' . $info['return'] : ''),
            'params' => $params, 'active' => $active, 'documentation' => $info['doc'] ?? ''];
    }

    // problems, structure -------------------------------------------------------------------------------------

    /** @return list<array<string, mixed>> syntax errors, then unknown classes / methods / functions */
    public function diagnostics(string $path, string $text): array
    {
        $info = Analyzer::analyze($text);
        $out = [];
        foreach ($info['errors'] as $error) {
            $out[] = $error + ['severity' => 'error', 'source' => 'php'];
        }
        if ($info['errors']) {
            return $out;
        }
        $this->index->update($path, $text);
        foreach ($info['refs'] as [$kind, $target, $line, $col, $length, $certain]) {
            $message = null;
            if ($kind === 'class') {
                if ($this->index->findClass($target) === null && !in_array(strtolower($target), ['parent', 'self', 'static'], true)) {
                    $message = "Undefined class '" . $target . "'";
                }
            } elseif ($kind === 'function') {
                $short = Index::short($target);
                if ($this->index->findFunction($target) === null && $this->index->findFunction($short) === null) {
                    $message = "Undefined function '$short'";
                }
            } elseif ($certain && ($kind === 'method' || $kind === 'prop')) {
                [$class, $member] = explode('::', $target, 2);
                $message = $this->missingMember($class, $kind, $member);
            }
            if ($message !== null) {
                $out[] = ['message' => $message, 'startLine' => $line, 'startColumn' => $col, 'endLine' => $line,
                    'endColumn' => $col + $length, 'severity' => 'warning', 'source' => 'jumphp'];
            }
        }
        return $out;
    }

    private function missingMember(string $class, string $kind, string $member): ?string
    {
        $hierarchy = $this->index->hierarchy($class);
        if (!$hierarchy || strtolower($class) === 'stdclass') {
            return null;
        }
        $names = [];
        foreach ($hierarchy as [, $info]) {
            array_push($names, ...$info['extends'], ...$info['implements'], ...$info['traits']);
            $magic = $kind === 'method' ? ['__call', '__callstatic'] : ['__get'];
            foreach ($magic as $m) {
                if (isset($info['methods'][$m])) {
                    return null;
                }
            }
            if (($info['internal'] ?? false) && $kind === 'prop') {
                return null;
            }
        }
        foreach ($names as $name) {
            if ($this->index->findClass($name) === null) {
                return null;               // an ancestor we cannot see: maybe it is there
            }
        }
        if ($this->index->findMember($class, $kind === 'method' ? 'methods' : 'props', $member) !== null) {
            return null;
        }
        return $kind === 'method'
            ? "Method '$member' not found in " . Index::short($class)
            : "Property '\$$member' not found in " . Index::short($class);
    }

    /** @return list<array<string, mixed>> the file's structure: classes with their members, functions */
    public function outline(string $text): array
    {
        $info = Analyzer::analyze($text);
        $out = [];
        foreach ($info['classes'] as $class) {
            $children = [];
            foreach ($class['consts'] as $name => $const) {
                $children[] = ['name' => $name, 'kind' => $const['case'] ? 'enumMember' : 'constant', 'detail' => $const['value'], 'line' => $const['line'], 'col' => $const['col'], 'vis' => $const['vis']];
            }
            foreach ($class['props'] as $name => $prop) {
                if (empty($prop['magic'])) {
                    $children[] = ['name' => '$' . $name, 'kind' => 'property', 'detail' => $prop['type'] ?? '', 'line' => $prop['line'], 'col' => $prop['col'], 'vis' => $prop['vis'], 'static' => $prop['static']];
                }
            }
            foreach ($class['methods'] as $method) {
                if (empty($method['magic'])) {
                    $children[] = ['name' => $method['name'], 'kind' => 'method', 'detail' => $this->signature($method, false), 'line' => $method['line'],
                        'col' => $method['col'], 'endLine' => $method['endLine'] ?? $method['line'], 'vis' => $method['vis'], 'static' => $method['static']];
                }
            }
            $out[] = ['name' => Index::short($class['name']), 'kind' => $class['kind'], 'detail' => $class['name'], 'line' => $class['line'],
                'col' => $class['col'], 'endLine' => $class['endLine'], 'children' => $children];
        }
        foreach ($info['functions'] as $function) {
            $out[] = ['name' => Index::short($function['name']), 'kind' => 'function', 'detail' => $this->signature($function, false),
                'line' => $function['line'], 'col' => $function['col'], 'endLine' => $function['endLine'] ?? $function['line'], 'children' => []];
        }
        return $out;
    }

    /**
     * Symbols of the whole project matching a query ("go to symbol", Search Everywhere).
     *
     * @return list<array<string, mixed>>
     */
    public function searchSymbols(string $query, int $limit = 60): array
    {
        $out = [];
        if ($query === '') {
            return [];
        }
        foreach ($this->index->files() as $path => $entry) {
            foreach ($entry['classes'] as $class) {
                $score = Index::fuzzy($query, Index::short($class['name']));
                if ($score !== null) {
                    $out[] = ['name' => Index::short($class['name']), 'detail' => $class['name'], 'kind' => $class['kind'], 'path' => $path,
                        'line' => $class['line'], 'col' => $class['col'], 'score' => $score + 20];
                }
                foreach ($class['methods'] as $method) {
                    if (!empty($method['magic'])) {
                        continue;
                    }
                    $score = Index::fuzzy($query, $method['name']);
                    if ($score !== null) {
                        $out[] = ['name' => $method['name'] . '()', 'detail' => Index::short($class['name']), 'kind' => 'method', 'path' => $path,
                            'line' => $method['line'], 'col' => $method['col'], 'score' => $score];
                    }
                }
                foreach ($class['props'] as $name => $prop) {
                    $score = empty($prop['magic']) ? Index::fuzzy($query, $name) : null;
                    if ($score !== null) {
                        $out[] = ['name' => '$' . $name, 'detail' => Index::short($class['name']), 'kind' => 'property', 'path' => $path,
                            'line' => $prop['line'], 'col' => $prop['col'], 'score' => $score - 5];
                    }
                }
                foreach ($class['consts'] as $name => $const) {
                    $score = Index::fuzzy($query, $name);
                    if ($score !== null) {
                        $out[] = ['name' => $name, 'detail' => Index::short($class['name']), 'kind' => 'constant', 'path' => $path,
                            'line' => $const['line'], 'col' => $const['col'], 'score' => $score - 5];
                    }
                }
            }
            foreach ($entry['functions'] as $function) {
                $score = Index::fuzzy($query, Index::short($function['name']));
                if ($score !== null) {
                    $out[] = ['name' => Index::short($function['name']) . '()', 'detail' => $function['name'], 'kind' => 'function', 'path' => $path,
                        'line' => $function['line'], 'col' => $function['col'], 'score' => $score];
                }
            }
        }
        usort($out, fn ($a, $b) => $b['score'] <=> $a['score'] ?: strcmp($a['name'], $b['name']));
        return array_slice($out, 0, $limit);
    }

    /** Implementations / subclasses of the class or overrides of the method at a position. */
    public function implementations(string $path, string $text, int $line, int $column): array
    {
        $symbol = $this->symbolAt($path, $text, $line, $column);
        if ($symbol === null) {
            return [];
        }
        $out = [];
        if ($symbol['kind'] === 'class') {
            foreach ($this->index->subclasses($symbol['name']) as [$p, $info]) {
                $out[] = ['path' => $p, 'line' => $info['line'], 'col' => $info['col'], 'name' => $info['name']];
            }
        } elseif ($symbol['kind'] === 'method' && $symbol['class'] !== null) {
            foreach ($this->index->subclasses($symbol['class']) as [$p, $info]) {
                $m = $info['methods'][strtolower($symbol['name'])] ?? null;
                if ($m !== null && empty($m['magic'])) {
                    $out[] = ['path' => $p, 'line' => $m['line'], 'col' => $m['col'], 'name' => $info['name'] . '::' . $m['name']];
                }
            }
        }
        return $out;
    }

    /**
     * The type hierarchy of the class under the cursor (else of the file's first class): its ancestors as a tree
     * (parent, interfaces, traits) and the classes that extend or implement it (the project and the libraries read).
     *
     * @return array<string, mixed>|null {name, kind, path, line, col, library, supers: [...], subs: [...]}
     */
    public function typeHierarchy(string $path, string $text, int $line, int $column): ?array
    {
        $symbol = $this->symbolAt($path, $text, $line, $column);
        $fqn = ($symbol['kind'] ?? null) === 'class' ? $symbol['name'] : null;
        if ($fqn === null) {
            $classes = Analyzer::analyze($text)['classes'];
            $fqn = $classes ? reset($classes)['name'] : null;
        }
        return $fqn !== null ? $this->hierarchyOf($fqn) : null;
    }

    /** @return array<string, mixed> */
    public function hierarchyOf(string $fqn, int $depth = 0): array
    {
        $found = $this->index->findClass($fqn);
        if ($found === null) {
            return ['name' => ltrim($fqn, '\\'), 'kind' => 'class', 'path' => null, 'unknown' => true, 'supers' => [], 'subs' => []];
        }
        [$path, $info] = $found;
        $node = ['name' => $info['name'], 'kind' => $info['kind'], 'path' => $path !== '' ? $path : null, 'line' => $info['line'] ?? 1,
            'col' => $info['col'] ?? 1, 'internal' => $path === '', 'supers' => [], 'subs' => []];
        if ($depth < 12) {
            foreach ([...$info['extends'], ...$info['implements'], ...$info['traits']] as $super) {
                $child = $this->hierarchyOf($super, $depth + 1);
                $child['relation'] = in_array($super, $info['traits'], true) ? 'uses' : (in_array($super, $info['implements'], true) ? 'implements' : 'extends');
                $node['supers'][] = $child;
            }
        }
        if ($depth === 0) {
            foreach ($this->index->subclasses($info['name']) as [$p, $sub]) {
                $node['subs'][] = ['name' => $sub['name'], 'kind' => $sub['kind'], 'path' => $p, 'line' => $sub['line'], 'col' => $sub['col'],
                    'direct' => in_array(strtolower($info['name']), array_map(fn ($n) => strtolower(ltrim($n, '\\')), [...$sub['extends'], ...$sub['implements'], ...$sub['traits']]), true)];
            }
            usort($node['subs'], fn ($a, $b) => [!$a['direct'], $a['name']] <=> [!$b['direct'], $b['name']]);
        }
        return $node;
    }

    // formatting helpers -----------------------------------------------------------------------------------

    /** @param array<string, mixed> $function */
    public function signature(array $function, bool $withName): string
    {
        $params = implode(', ', array_map(fn ($p) => $this->param($p), $function['params'] ?? []));
        $return = ($function['return'] ?? null) ? ': ' . $this->shortType($function['return']) : '';
        return ($withName ? $function['name'] : '') . "($params)$return";
    }

    /** @param array<string, mixed> $param */
    private function param(array $param): string
    {
        $type = $param['hint'] ?? $param['type'] ?? null;
        return ($type ? $this->shortType($type) . ' ' : '') . ($param['byRef'] ? '&' : '') . ($param['variadic'] ? '...' : '')
            . '$' . $param['name'] . ($param['default'] !== null ? ' = ' . $param['default'] : '');
    }

    private function shortType(string $type): string
    {
        return implode('|', array_map(fn ($t) => str_contains($t, '\\') ? Index::short($t) : $t, Types::split($type)));
    }

    private function code(string $code): string
    {
        return "```php\n$code\n```\n";
    }

    /** @param array<string, mixed> $info */
    private function doc(array $info): string
    {
        $doc = trim((string)($info['doc'] ?? ''));
        return ($info['deprecated'] ?? false ? "\n**Deprecated**\n" : '') . ($doc !== '' ? "\n$doc\n" : '');
    }
}
