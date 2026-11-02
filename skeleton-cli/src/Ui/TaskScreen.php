<?php

namespace App\Ui;

use App\Main;
use App\Tasks;
use Symfony\Component\Tui\Event\CancelEvent;
use Symfony\Component\Tui\Event\SelectEvent;
use Symfony\Component\Tui\Event\SubmitEvent;
use Symfony\Component\Tui\Terminal\TerminalInterface;
use Symfony\Component\Tui\Tui;
use Symfony\Component\Tui\Widget\InputWidget;
use Symfony\Component\Tui\Widget\SelectListWidget;
use Symfony\Component\Tui\Widget\TextWidget;

/**
 * The full-screen screen (symfony/tui): the tasks in a list (arrows, Enter: done / not done, d: delete), a field to
 * add one (a), q or Ctrl+C to quit. Tests drive it on a VirtualTerminal (tests/TaskScreenTest.php).
 */
final class TaskScreen
{
    public readonly Tui $tui;
    private SelectListWidget $list;
    private InputWidget $input;
    private TextWidget $help;

    /** @param TerminalInterface|null $terminal null: this console's (WindowsTerminal::create()) */
    public function __construct(private Tasks $tasks, ?TerminalInterface $terminal = null)
    {
        $this->tui = new Tui(terminal: $terminal ?? WindowsTerminal::create());
        $this->list = new SelectListWidget([], 12);
        $this->input = new InputWidget();
        $this->input->setPrompt('New task: ');
        $this->help = new TextWidget();
        $this->tui->add(new TextWidget(Main::title()))->add($this->list)->add($this->help);

        // the list: its own keys (arrows, Enter), plus a / d / q
        $this->list->onInput(function (string $data): bool {
            return match ($data) {
                'a' => $this->startAdding(),
                'd' => $this->removeSelected(),
                'q', "\x03" => $this->quit(),
                default => false,
            };
        });
        $this->list->onSelect(fn (SelectEvent $e) => $this->toggle((int)$e->getValue()));
        $this->input->onSubmit(function (SubmitEvent $e): void {
            if (trim($e->getValue()) !== '') {
                $this->tasks->add($e->getValue());
            }
            $this->stopAdding();
        });
        $this->input->onCancel(fn (CancelEvent $e) => $this->stopAdding());
        $this->refresh();
        $this->tui->setFocus($this->list);
    }

    public function run(): void
    {
        $this->tui->run();
    }

    private function refresh(int $select = -1): void
    {
        $items = [];
        foreach ($this->tasks->all() as $i => $task) {
            $items[] = ['value' => (string)($i + 1), 'label' => ($task['done'] ? '[x] ' : '[ ] ') . $task['text']];
        }
        $this->list->setItems($items ?: [['value' => '0', 'label' => '(no task yet: press a)']]);
        if ($select >= 0) {
            $this->list->setSelectedIndex($select);
        }
        $done = count(array_filter($this->tasks->all(), fn ($t) => $t['done']));
        $this->help->setText(sprintf('%d / %d done   ↑↓ move · Enter done · a add · d delete · q quit', $done, count($this->tasks->all())));
    }

    private function toggle(int $n): void
    {
        if ($n > 0) {
            $this->tasks->toggle($n);
            $this->refresh($n - 1);
        }
    }

    private function removeSelected(): bool
    {
        $n = (int)($this->list->getSelectedItem()['value'] ?? 0);
        if ($n > 0) {
            $this->tasks->remove($n);
            $this->refresh(max(0, $n - 2));
        }
        return true;
    }

    private function startAdding(): bool
    {
        $this->input->setValue('');
        $this->tui->add($this->input);
        $this->tui->setFocus($this->input);
        return true;
    }

    private function stopAdding(): void
    {
        $this->tui->remove($this->input);
        $this->refresh(count($this->tasks->all()) - 1);
        $this->tui->setFocus($this->list);
    }

    private function quit(): bool
    {
        $this->tui->stop();
        return true;
    }
}
