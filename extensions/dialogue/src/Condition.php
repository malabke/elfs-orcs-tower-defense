<?php

namespace JumPHP\Dialogue;

/**
 * The conditions of a dialogue ("if": "gold >= 5 && !helped || quest.rescue == 'done'"), read by a small parser: names (variables, dots
 * allowed), numbers, 'strings', true / false / null, == != < <= > >=, ! && || and parentheses. Never PHP's eval: what a file says cannot run
 * code. An unknown variable is null (false, and 0 against a number).
 */
final class Condition
{
    /** @var list<array{0: string, 1: mixed}> */
    private array $tokens = [];
    private int $at = 0;

    private function __construct(private Variables $vars, private string $source)
    {
    }

    public static function test(string $expression, Variables $vars): bool
    {
        $c = new self($vars, $expression);
        $c->tokens = $c->tokenize($expression);
        if ($c->tokens === []) {
            return true;
        }
        $value = $c->orExpr();
        if ($c->at < count($c->tokens)) {
            throw new DialogueException("Condition '$expression': unexpected '" . $c->tokens[$c->at][1] . "'");
        }
        return (bool)$value;
    }

    /** Checks the syntax without variables (a dialogue file is checked when it is loaded). */
    public static function check(string $expression): void
    {
        self::test($expression, new Variables());
    }

    /** @return list<array{0: string, 1: mixed}> */
    private function tokenize(string $s): array
    {
        $out = [];
        $n = strlen($s);
        for ($i = 0; $i < $n;) {
            $ch = $s[$i];
            if (ctype_space($ch)) {
                $i++;
            } elseif (preg_match('/\G(==|!=|>=|<=|&&|\|\||[<>!()])/', $s, $m, 0, $i)) {
                $out[] = ['op', $m[1]];
                $i += strlen($m[1]);
            } elseif (preg_match('/\G-?\d+(\.\d+)?/', $s, $m, 0, $i)) {
                $out[] = ['val', str_contains($m[0], '.') ? (float)$m[0] : (int)$m[0]];
                $i += strlen($m[0]);
            } elseif ($ch === "'" || $ch === '"') {
                $end = strpos($s, $ch, $i + 1);
                if ($end === false) {
                    throw new DialogueException("Condition '$this->source': a string is not closed");
                }
                $out[] = ['val', substr($s, $i + 1, $end - $i - 1)];
                $i = $end + 1;
            } elseif (preg_match('/\G[A-Za-z_][A-Za-z0-9_.]*/', $s, $m, 0, $i)) {
                $out[] = match ($m[0]) {
                    'true' => ['val', true],
                    'false' => ['val', false],
                    'null' => ['val', null],
                    default => ['var', $m[0]],
                };
                $i += strlen($m[0]);
            } else {
                throw new DialogueException("Condition '$this->source': unexpected '$ch'");
            }
        }
        return $out;
    }

    private function peek(): ?array
    {
        return $this->tokens[$this->at] ?? null;
    }

    private function accept(string $op): bool
    {
        $t = $this->peek();
        if ($t !== null && $t[0] === 'op' && $t[1] === $op) {
            $this->at++;
            return true;
        }
        return false;
    }

    private function orExpr(): mixed
    {
        $left = $this->andExpr();
        while ($this->accept('||')) {
            $right = $this->andExpr();
            $left = $left || $right;
        }
        return $left;
    }

    private function andExpr(): mixed
    {
        $left = $this->notExpr();
        while ($this->accept('&&')) {
            $right = $this->notExpr();
            $left = $left && $right;
        }
        return $left;
    }

    private function notExpr(): mixed
    {
        return $this->accept('!') ? !$this->notExpr() : $this->comparison();
    }

    private function comparison(): mixed
    {
        $left = $this->primary();
        foreach (['==', '!=', '>=', '<=', '>', '<'] as $op) {
            if ($this->accept($op)) {
                $right = $this->primary();
                return match ($op) {
                    '==' => $left == $right,
                    '!=' => $left != $right,
                    '>=' => ($left ?? 0) >= ($right ?? 0),
                    '<=' => ($left ?? 0) <= ($right ?? 0),
                    '>' => ($left ?? 0) > ($right ?? 0),
                    default => ($left ?? 0) < ($right ?? 0),
                };
            }
        }
        return $left;
    }

    private function primary(): mixed
    {
        if ($this->accept('(')) {
            $value = $this->orExpr();
            if (!$this->accept(')')) {
                throw new DialogueException("Condition '$this->source': a ')' is missing");
            }
            return $value;
        }
        $t = $this->peek();
        if ($t === null || $t[0] === 'op') {
            throw new DialogueException("Condition '$this->source': a value is missing" . ($t !== null ? " before '{$t[1]}'" : ' at the end'));
        }
        $this->at++;
        return $t[0] === 'var' ? $this->vars->get((string)$t[1]) : $t[1];
    }
}
