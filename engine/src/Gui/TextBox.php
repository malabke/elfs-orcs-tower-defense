<?php

namespace JumPHP\Gui;

use JumPHP\Renderer\Renderer;

/**
 * A text field: `text`, `placeholder`, `password` (shown as dots), `maxLength` (0: no limit), `multiline` (Return goes
 * to the next line, the box grows with its `rows`), `readonly`.
 * The text comes from the system's text input: the keyboard's layout, dead keys (^ then e = ê) and input methods
 * (Chinese, Japanese...: what they compose is shown underlined until it is chosen).
 * Events: change (each key), submit (Return in a one-line box).
 *
 * @property string $text
 * @property string $placeholder
 */
class TextBox extends Widget
{
    public const DEFAULTS = ['text' => '', 'placeholder' => '', 'password' => false, 'maxLength' => 0, 'multiline' => false,
        'rows' => 4, 'readonly' => false];

    /** where the cursor is (characters) */
    public int $cursor = 0;
    /** what the system's input method (or a dead key) is composing: shown underlined at the cursor, not in the text yet */
    public string $composition = '';
    /** @var array{0: float, 1: float, 2: float, 3: float}|null the cursor's box (x, y, w, h, view pixels) when last drawn: the input method's window opens there */
    public ?array $caret = null;
    private bool $composing = false;
    private float $scrollX = 0;
    private float $blink = 0;

    public static function events(): array
    {
        return ['change', 'submit', 'click'];
    }

    public function focusable(): bool
    {
        return $this->isEnabled();
    }

    protected function content(Theme $t, float $maxWidth, string $breakpoint): array
    {
        $lines = $this->props['multiline'] ? max(1, (int)$this->props['rows']) : 1;
        $lh = $t->font()->lineHeight();
        return [$t->px(200), $lines === 1 ? $t->px($t->controlHeight) : $lines * $lh + 2 * $t->px($t->padding * 0.8)];
    }

    protected function changed(string $name, mixed $old): void
    {
        if ($name === 'text') {
            $this->cursor = min($this->cursor, mb_strlen((string)$this->props['text']));
        }
        parent::changed($name, $old);
    }

    private function shown(): string
    {
        $text = (string)$this->props['text'];
        if ($this->props['password']) {
            return str_repeat('•', mb_strlen($text));
        }
        return $this->composing ? mb_substr($text, 0, $this->cursor) . $this->composition . mb_substr($text, $this->cursor) : $text;
    }

    public function update(float $dt): void
    {
        $this->blink = $this->focused ? fmod($this->blink + $dt, 1.0) : 0;
    }

    public function render(Renderer $r, Theme $t): void
    {
        // a composition is drawn as if typed, the cursor after it
        $this->composing = $this->focused && $this->composition !== '' && !$this->props['password'];
        $length = $this->composing ? mb_strlen($this->composition) : 0;
        $this->cursor += $length;
        try {
            $this->draw($r, $t, $length);
        } finally {
            $this->cursor -= $length;
            $this->composing = false;
        }
    }

    private function draw(Renderer $r, Theme $t, int $composed): void
    {
        $border = $this->focused ? $t->color('focus') : ($this->hovered ? $t->color('muted') : $t->color('border'));
        if ($this->focused) {
            $t->box($r, $this->x - $t->px(2), $this->y - $t->px(2), $this->w + $t->px(4), $this->h + $t->px(4), $t->color('focus')->withAlpha(70), $t->px($t->radius + 2));
        }
        $t->frame($r, $this->x, $this->y, $this->w, $this->h, $this->isEnabled() && !$this->props['readonly'] ? $t->color('surface') : $t->color('track'), $border);
        $font = $t->font();
        $pad = $t->px($t->padding);
        $innerW = $this->w - 2 * $pad;
        $text = $this->shown();
        $lh = $font->lineHeight();
        $color = $this->isEnabled() ? $this->tint($t, 'color', 'text') : $t->color('disabled');
        if ($text === '' && !$this->focused) {
            $font->draw($r, (string)$this->props['placeholder'], $this->x + $pad, $this->textTop($t), 1, $t->color('muted'));
            return;
        }
        if ($this->props['multiline']) {
            [$lines, $cursorLine, $cursorCol] = $this->lines($t, $innerW);
            $first = max(0, $cursorLine - (int)floor(($this->h - $pad) / $lh) + 1);
            $y = $this->y + $t->px($t->padding * 0.8);
            for ($n = $first; $n < count($lines) && $y + $lh <= $this->y + $this->h; $n++, $y += $lh) {
                $font->draw($r, $lines[$n], $this->x + $pad, $y + ($lh - $font->height()) / 2, 1, $color);
                if ($this->focused && $n === $cursorLine) {
                    $cx = $this->x + $pad + $font->width(mb_substr($lines[$n], 0, $cursorCol));
                    $this->caret = [$cx, $y, max(1, $t->px(1.5)), $lh];
                    if ($composed > 0) {
                        $from = $this->x + $pad + $font->width(mb_substr($lines[$n], 0, max(0, $cursorCol - $composed)));
                        $r->fillRect($from, $y + $lh - $t->px(2), $cx - $from, max(1, $t->px(1)), $color);
                    }
                    if ($this->blink < 0.55) {
                        $r->fillRect($cx, $y + $t->px(2), max(1, $t->px(1.5)), $lh - $t->px(4), $t->color('text'));
                    }
                }
            }
            return;
        }
        $before = $font->width(mb_substr($text, 0, $this->cursor));
        // the cursor stays in sight: the text slides
        if ($before - $this->scrollX > $innerW) {
            $this->scrollX = $before - $innerW;
        } elseif ($before < $this->scrollX) {
            $this->scrollX = max(0, $before - $innerW / 3);
        }
        $visible = $this->clip($font, $text, $this->scrollX, $innerW, $offset);
        $font->draw($r, $visible, $this->x + $pad + $offset, $this->textTop($t), 1, $color);
        if ($this->focused) {
            $cx = $this->x + $pad + $before - $this->scrollX;
            $this->caret = [$cx, $this->y, max(1, $t->px(1.5)), $this->h];
            if ($composed > 0) {
                $from = $this->x + $pad + $font->width(mb_substr($text, 0, $this->cursor - $composed)) - $this->scrollX;
                $r->fillRect(max($this->x + $pad, $from), $this->textTop($t) + $font->height() + $t->px(1), $cx - max($this->x + $pad, $from), max(1, $t->px(1)), $color);
            }
            if ($this->blink < 0.55) {
                $r->fillRect($cx, $this->y + $t->px(8), max(1, $t->px(1.5)), $this->h - $t->px(16), $t->color('text'));
            }
        }
    }

    private function textTop(Theme $t): float
    {
        $font = $t->font();
        return $this->props['multiline'] ? $this->y + $t->px($t->padding * 0.8) + ($font->lineHeight() - $font->height()) / 2
            : $this->y + ($this->h - $font->height()) / 2;
    }

    /**
     * The characters seen between $from and $from + $width pixels ($offset: where the first one starts).
     *
     * @param-out float $offset
     */
    private function clip(\JumPHP\Renderer\TextDrawer $font, string $text, float $from, float $width, ?float &$offset): string
    {
        $start = 0;
        $len = mb_strlen($text);
        while ($start < $len && $font->width(mb_substr($text, 0, $start + 1)) <= $from) {
            $start++;
        }
        $offset = $font->width(mb_substr($text, 0, $start)) - $from;
        $end = $start;
        while ($end < $len && $font->width(mb_substr($text, $start, $end - $start + 1)) + $offset <= $width) {
            $end++;
        }
        return mb_substr($text, $start, $end - $start);
    }

    /**
     * The text cut into lines that fit the width, and where the cursor is in them.
     *
     * @return array{0: list<string>, 1: int, 2: int}
     */
    private function lines(Theme $t, float $width): array
    {
        $font = $t->font();
        $lines = [];
        $cursorLine = 0;
        $cursorCol = 0;
        $pos = 0;
        foreach (explode("\n", $this->shown()) as $paragraph) {
            $chars = mb_str_split($paragraph) ?: [];
            $line = '';
            $start = $pos;
            foreach ($chars as $char) {
                if ($line !== '' && $font->width($line . $char) > $width) {
                    $lines[] = $line;
                    $start += mb_strlen($line);
                    $line = '';
                }
                $line .= $char;
            }
            $lines[] = $line;
            $pos += count($chars);
            if ($this->cursor >= $start && $this->cursor <= $pos) {
                $cursorLine = count($lines) - 1;
                $cursorCol = $this->cursor - $start;
            }
            $pos++;   // the new line
        }
        return [$lines, $cursorLine, $cursorCol];
    }

    public function pointerDown(Pointer $p): void
    {
        if ($this->props['multiline'] || $this->form === null) {
            $this->cursor = mb_strlen((string)$this->props['text']);
            return;
        }
        $t = $this->form->theme;
        $font = $t->font();
        $x = $p->x - $this->x - $t->px($t->padding) + $this->scrollX;
        $text = $this->shown();
        $len = mb_strlen($text);
        $this->cursor = $len;
        for ($i = 0; $i < $len; $i++) {
            if ($font->width(mb_substr($text, 0, $i)) + $font->width(mb_substr($text, $i, 1)) / 2 > $x) {
                $this->cursor = $i;
                break;
            }
        }
        $this->blink = 0;
    }

    public function keys(Pointer $p): bool
    {
        $text = (string)$this->props['text'];
        $len = mb_strlen($text);
        $this->cursor = min($this->cursor, $len);
        $editable = !$this->props['readonly'] && $this->isEnabled();
        $new = $text;
        foreach ($p->keys as $key) {
            $this->blink = 0;
            switch ($key) {
                case Pointer::LEFT:
                    $this->cursor = max(0, $this->cursor - 1);
                    break;
                case Pointer::RIGHT:
                    $this->cursor = min(mb_strlen($new), $this->cursor + 1);
                    break;
                case Pointer::HOME:
                    $this->cursor = 0;
                    break;
                case Pointer::END:
                    $this->cursor = mb_strlen($new);
                    break;
                case Pointer::BACKSPACE:
                    if ($editable && $this->cursor > 0) {
                        $new = mb_substr($new, 0, $this->cursor - 1) . mb_substr($new, $this->cursor);
                        $this->cursor--;
                    }
                    break;
                case Pointer::DELETE:
                    if ($editable) {
                        $new = mb_substr($new, 0, $this->cursor) . mb_substr($new, $this->cursor + 1);
                    }
                    break;
                case Pointer::RETURN:
                case Pointer::KP_ENTER:
                    if ($this->props['multiline']) {
                        if ($editable) {
                            $new = mb_substr($new, 0, $this->cursor) . "\n" . mb_substr($new, $this->cursor);
                            $this->cursor++;
                        }
                    } else {
                        $this->emit('submit', $new);
                    }
                    break;
            }
        }
        if ($editable) {
            foreach ($p->text as $char) {
                $max = (int)$this->props['maxLength'];
                if ($max > 0 && mb_strlen($new) >= $max) {
                    break;
                }
                $new = mb_substr($new, 0, $this->cursor) . $char . mb_substr($new, $this->cursor);
                $this->cursor++;
            }
        }
        if ($new !== $text) {
            $this->props['text'] = $new;
            $this->form?->invalidate();
            $this->emit('change', $new);
        }
        // Tab leaves the field; everything else was for it
        return $p->keys !== [Pointer::TAB] && ($p->keys !== [] || $p->text !== []);
    }

    public function blur(): void
    {
        $this->scrollX = 0;
        $this->composition = '';
        $this->caret = null;
    }
}
