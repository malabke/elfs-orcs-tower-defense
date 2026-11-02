<?php

namespace JumPHP\Ide\Plugin;

/**
 * Where the user is when an action runs or completion is asked: the active file, its text (as typed, saved or
 * not), the caret (lines and columns from 1) and the selected text.
 */
final class EditorState
{
    public function __construct(
        public readonly ?string $path = null,
        public readonly string $text = '',
        public readonly int $line = 1,
        public readonly int $column = 1,
        public readonly string $selection = '',
    ) {
    }

    /** @param array<string, mixed> $p what the interface sends (path, text, line, column, selection) */
    public static function from(array $p): self
    {
        return new self(isset($p['path']) && $p['path'] !== '' ? (string)$p['path'] : null, (string)($p['text'] ?? ''),
            max(1, (int)($p['line'] ?? 1)), max(1, (int)($p['column'] ?? 1)), (string)($p['selection'] ?? ''));
    }

    /** The text of the caret's line. */
    public function lineText(): string
    {
        return explode("\n", str_replace("\r\n", "\n", $this->text))[$this->line - 1] ?? '';
    }

    /** The caret's line up to the caret. */
    public function before(): string
    {
        return mb_substr($this->lineText(), 0, $this->column - 1);
    }

    /** The word being typed (letters, digits, _ - . \ $ before the caret). */
    public function word(): string
    {
        return preg_match('/[\w\-.\\\\$]*$/u', $this->before(), $m) ? $m[0] : '';
    }

    /** The file's extension, lower case ('' without one). */
    public function extension(): string
    {
        return strtolower(pathinfo((string)$this->path, PATHINFO_EXTENSION));
    }
}
