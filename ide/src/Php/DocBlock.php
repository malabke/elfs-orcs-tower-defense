<?php

namespace JumPHP\Ide\Php;

/** What the IDE reads in a docblock: its summary and the tags that carry types. */
final class DocBlock
{
    public string $summary = '';
    public ?string $var = null;
    public ?string $varName = null;
    public ?string $return = null;
    public bool $deprecated = false;

    /** @var array<string, string> parameter name (without $) => type */
    public array $params = [];

    /** @var array<string, string> @property name => type */
    public array $properties = [];

    /** @var array<string, array{type: string, static: bool, params: string}> @method name => ... */
    public array $methods = [];

    public static function parse(?string $doc): self
    {
        $block = new self();
        if ($doc === null || $doc === '') {
            return $block;
        }
        $lines = [];
        foreach (preg_split('/\R/', $doc) ?: [] as $line) {
            $line = preg_replace('#^\s*(/\*\*|\*/|\*)?\s?#', '', $line) ?? '';
            $line = preg_replace('#\s*\*/\s*$#', '', $line) ?? '';
            $lines[] = $line;
        }
        $summary = [];
        $inSummary = true;
        foreach ($lines as $line) {
            $trimmed = trim($line);
            if (str_starts_with($trimmed, '@')) {
                $inSummary = false;
                $block->tag($trimmed);
            } elseif ($inSummary) {
                if ($trimmed === '' && $summary) {
                    $inSummary = false;
                } elseif ($trimmed !== '') {
                    $summary[] = $trimmed;
                }
            }
        }
        $block->summary = implode(' ', $summary);
        return $block;
    }

    private function tag(string $line): void
    {
        $type = '((?:[^\s<>(){}]|<[^>]*>|\{[^}]*\})+)';
        if (preg_match('/^@(?:phpstan-|psalm-)?var\s+' . $type . '(?:\s+\$(\w+))?/', $line, $m)) {
            $this->var = $m[1];
            $this->varName = $m[2] ?? null;
        } elseif (preg_match('/^@(?:phpstan-|psalm-)?param\s+' . $type . '\s+(?:\.\.\.)?&?\$(\w+)/', $line, $m)) {
            $this->params[$m[2]] = $m[1];
        } elseif (preg_match('/^@(?:phpstan-|psalm-)?return\s+' . $type . '/', $line, $m)) {
            $this->return = $m[1];
        } elseif (preg_match('/^@property(?:-read|-write)?\s+' . $type . '\s+\$(\w+)/', $line, $m)) {
            $this->properties[$m[2]] = $m[1];
        } elseif (preg_match('/^@method\s+(static\s+)?(?:' . $type . '\s+)?(\w+)\s*\(([^)]*)\)/', $line, $m)) {
            $this->methods[$m[3]] = ['type' => $m[2] !== '' ? $m[2] : 'mixed', 'static' => $m[1] !== '', 'params' => $m[4]];
        } elseif (str_starts_with($line, '@deprecated')) {
            $this->deprecated = true;
        }
    }
}
