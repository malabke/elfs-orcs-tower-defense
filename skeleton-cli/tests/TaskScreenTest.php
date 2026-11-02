<?php

namespace Tests;

use App\Tasks;
use App\Ui\TaskScreen;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Tui\Terminal\ScreenBuffer;
use Symfony\Component\Tui\Terminal\VirtualTerminal;

/** The full-screen screen, played on a virtual terminal: keys in, the screen's text out. */
final class TaskScreenTest extends TestCase
{
    private string $file;
    private VirtualTerminal $terminal;
    private TaskScreen $screen;

    protected function setUp(): void
    {
        $this->file = sys_get_temp_dir() . '/tasks-' . bin2hex(random_bytes(4)) . '.json';
        $tasks = new Tasks($this->file);
        $tasks->add('Write the tests');
        $tasks->add('Ship it');
        $this->terminal = new VirtualTerminal(60, 12);
        $this->screen = new TaskScreen($tasks, $this->terminal);
        $this->screen->tui->start();
        $this->screen->tui->tick();
    }

    protected function tearDown(): void
    {
        @unlink($this->file);
    }

    private function keys(string ...$keys): void
    {
        foreach ($keys as $key) {
            $this->terminal->simulateInput($key);
            $this->screen->tui->tick();
        }
    }

    private function screenText(): string
    {
        $buffer = new ScreenBuffer(60, 12);
        $buffer->write($this->terminal->getOutput());
        return $buffer->getScreen();
    }

    public function testTheTasksAreListed(): void
    {
        self::assertStringContainsString('[ ] Write the tests', $this->screenText());
        self::assertStringContainsString('0 / 2 done', $this->screenText());
    }

    public function testEnterMarksTheSelectedTaskDone(): void
    {
        $this->keys("\x1b[B", "\r");    // down, Enter
        self::assertStringContainsString('[x] Ship it', $this->screenText());
        self::assertTrue((new Tasks($this->file))->at(2)['done'], 'saved');
    }

    public function testATaskIsAddedFromTheField(): void
    {
        $this->keys('a', 'B', 'u', 'y', ' ', 'm', 'i', 'l', 'k', "\r");
        self::assertStringContainsString('[ ] Buy milk', $this->screenText());
        self::assertCount(3, (new Tasks($this->file))->all());
    }

    public function testQQuits(): void
    {
        $this->keys('q');
        self::assertFalse($this->screen->tui->isRunning());
    }
}
