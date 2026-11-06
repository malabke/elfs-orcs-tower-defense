<?php

namespace JumPHP\Ide\Php;

use PhpParser\Error;
use PhpParser\ErrorHandler\Collecting;
use PhpParser\Node;
use PhpParser\NodeTraverser;
use PhpParser\NodeVisitor\NameResolver;
use PhpParser\Parser;
use PhpParser\ParserFactory;

/**
 * Reads one PHP file: what it declares (classes and their members, functions, constants), what it uses
 * (references to classes, functions, methods, properties, constants, with the receiver's type when the file
 * alone tells it), its syntax errors. The result is a plain array (cached as JSON by the Index):
 *
 *   namespace, uses {alias => fqn}, classes {lower fqn => class}, functions {lower fqn => function},
 *   constants {name => [line, col]}, refs [[kind, target, line, col, length, certain]], errors [...]
 *
 * Lines and columns as the editor counts them (Source).
 */
final class Analyzer
{
    private static ?Parser $parser = null;

    public static function parser(): Parser
    {
        return self::$parser ??= (new ParserFactory())->createForNewestSupportedVersion();
    }

    /**
     * Parses with error recovery, names resolved.
     *
     * @return array{0: Node[], 1: Error[]}
     */
    public static function parse(string $code): array
    {
        $errors = new Collecting();
        $stmts = self::parser()->parse($code, $errors) ?? [];
        $traverser = new NodeTraverser(new NameResolver(null, ['preserveOriginalNames' => true, 'replaceNodes' => true]));
        $stmts = $traverser->traverse($stmts);
        return [$stmts, $errors->getErrors()];
    }

    /** @return array<string, mixed> */
    public static function analyze(string $code): array
    {
        $source = new Source($code);
        [$stmts, $errors] = self::parse($code);
        $collector = new Collector($source);
        (new NodeTraverser($collector))->traverse($stmts);
        $info = $collector->result();
        $info['errors'] = array_map(fn (Error $e) => self::error($e, $source), $errors);
        return $info;
    }

    /** @return array{message: string, startLine: int, startColumn: int, endLine: int, endColumn: int} */
    public static function error(Error $e, Source $source): array
    {
        $message = preg_replace('/ on line \d+$/', '', $e->getMessage()) ?? $e->getMessage();
        if ($e->hasColumnInfo()) {
            $start = (int)$e->getAttributes()['startFilePos'];
            $end = max($start + 1, (int)$e->getAttributes()['endFilePos'] + 1);
            return ['message' => $message] + $source->range($start, $end);
        }
        $line = max(1, $e->getStartLine());
        return ['message' => $message, 'startLine' => $line, 'startColumn' => 1, 'endLine' => $line,
            'endColumn' => Source::utf16Length($source->line($line)) + 1];
    }
}
