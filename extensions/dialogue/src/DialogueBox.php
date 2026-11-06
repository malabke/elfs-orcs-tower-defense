<?php

namespace JumPHP\Dialogue;

use JumPHP\Input\ActionMap;
use JumPHP\Renderer\Captions;
use JumPHP\Renderer\Renderer;
use JumPHP\Renderer\TextDrawer;

/**
 * The text box of a DialogueRunner: the speaker, the text written letter by letter, the choices with a cursor. In update($dt, $this->actions()):
 * confirm shows the whole line, then goes on (or takes the choice under the cursor); up / down move the cursor. render($r, $this->font()) last in render().
 * Sizes follow the view (180 pixels high is scale 1). press() is what update() calls, for a mouse, a touch or a test.
 */
final class DialogueBox
{
    /** letters a second */
    public float $speed = 45;
    private float $shown = 0;
    private int $cursor = 0;
    private string $key = '';
    private float $time = 0;

    public function __construct(private DialogueRunner $runner)
    {
    }

    public function update(float $dt, ?ActionMap $actions = null): void
    {
        $this->time += $dt;
        $line = $this->runner->line();
        $key = $this->runner->dialogueId() . '/' . $this->runner->nodeName() . '/' . ($line['text'] ?? '');
        if ($key !== $this->key) {
            $this->key = $key;
            $this->shown = 0;
            $this->cursor = 0;
        }
        if ($line === null) {
            return;
        }
        $this->shown = min((float)mb_strlen($line['text']), $this->shown + $this->speed * $dt);
        if ($actions !== null) {
            foreach (['confirm', 'up', 'down'] as $name) {
                if ($actions->has($name) && $actions->isPressed($name)) {
                    $this->press($name);
                }
            }
        }
    }

    /** @param string $what confirm | up | down */
    public function press(string $what): void
    {
        $line = $this->runner->line();
        if ($line === null) {
            return;
        }
        $revealed = $this->isRevealed();
        if ($what === 'confirm') {
            if (!$revealed) {
                $this->shown = (float)mb_strlen($line['text']);
            } elseif ($line['choices'] !== []) {
                $this->runner->choose($this->cursor);
            } else {
                $this->runner->advance();
            }
            return;
        }
        $n = count($line['choices']);
        if ($revealed && $n > 0) {
            $this->cursor = ($this->cursor + ($what === 'down' ? 1 : $n - 1)) % $n;
        }
    }

    /** The text written so far. */
    public function visibleText(): string
    {
        return mb_substr($this->runner->line()['text'] ?? '', 0, (int)floor($this->shown));
    }

    public function isRevealed(): bool
    {
        $line = $this->runner->line();
        return $line === null || $this->shown >= mb_strlen($line['text']);
    }

    /** The choice under the cursor (its index among the visible ones). */
    public function cursor(): int
    {
        return $this->cursor;
    }

    public function render(Renderer $r, TextDrawer $font): void
    {
        $line = $this->runner->line();
        if ($line === null) {
            return;
        }
        $w = $r->getWidth();
        $h = $r->getHeight();
        $scale = max(1, (int)round($h / 180));
        $pad = 6 * $scale;
        $lineH = $font->lineHeight($scale) + $scale;
        $lines = Captions::wrap($line['text'], $w - 2 * $pad - 8 * $scale, fn (string $t): int => $font->width($t, $scale));
        $rows = ($line['speaker'] !== '' ? 1 : 0) + count($lines) + count($line['choices']);
        $boxH = 2 * $pad + $rows * $lineH;
        $top = $h - $boxH - 4 * $scale;
        $r->fillRect(4 * $scale, $top, $w - 8 * $scale, $boxH, [10, 12, 30, 225]);
        $r->drawRect(4 * $scale, $top, $w - 8 * $scale, $boxH, [150, 160, 230], max(1, $scale));
        $y = $top + $pad;
        if ($line['speaker'] !== '') {
            $font->draw($r, $line['speaker'], 4 * $scale + $pad, $y, $scale, [255, 214, 90]);
            $y += $lineH;
        }
        $left = (int)floor($this->shown);
        foreach ($lines as $text) {
            $part = mb_substr($text, 0, max(0, $left));
            $left -= mb_strlen($text) + 1;                          // the space the wrapping swallowed
            if ($part !== '') {
                $font->draw($r, $part, 4 * $scale + $pad, $y, $scale, [235, 238, 255]);
            }
            $y += $lineH;
        }
        if ($this->isRevealed()) {
            foreach ($line['choices'] as $i => $choice) {
                $on = $i === $this->cursor;
                $font->draw($r, ($on ? '> ' : '  ') . $choice['text'], 4 * $scale + $pad, $y, $scale, $on ? [255, 255, 255] : [160, 170, 210]);
                $y += $lineH;
            }
            if ($line['choices'] === [] && fmod($this->time, 0.8) < 0.5) {
                $font->draw($r, 'v', $w - 4 * $scale - $pad - $font->width('v', $scale), $top + $boxH - $pad - $lineH + $scale, $scale, [255, 214, 90]);
            }
        }
    }
}
