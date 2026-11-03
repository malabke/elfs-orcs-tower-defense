<?php

namespace JumPHP\Ide\Tests;

use JumPHP\Ide\Ide;
use JumPHP\Ide\Workspace\LocalWorkspace;
use JumPHP\Ide\Workspace\MemoryWorkspace;
use PHPUnit\Framework\TestCase;

final class TerminalTest extends TestCase
{
    private string $dir;

    /** @var list<array{string, array<string, mixed>}> */
    private array $events = [];

    protected function setUp(): void
    {
        if (PHP_OS_FAMILY === 'Windows') {
            self::markTestSkipped('a pseudo-terminal: Linux / macOS');
        }
        $this->dir = sys_get_temp_dir() . '/jumphp-term-' . bin2hex(random_bytes(4));
        mkdir($this->dir);
        file_put_contents("$this->dir/composer.json", '{}');
    }

    protected function tearDown(): void
    {
        if (isset($this->dir)) {
            exec('rm -rf ' . escapeshellarg($this->dir));
        }
    }

    private function ide(): Ide
    {
        return new Ide(new LocalWorkspace($this->dir), null, function (string $event, array $payload): void {
            $this->events[] = [$event, $payload];
        });
    }

    /** Everything the shell printed so far. */
    private function screen(int $id): string
    {
        $out = '';
        foreach ($this->events as [$event, $payload]) {
            if ($event === 'terminal.output' && $payload['id'] === $id) {
                $out .= base64_decode($payload['data']);
            }
        }
        return $out;
    }

    private function until(Ide $ide, callable $done, float $seconds = 10.0): void
    {
        $end = microtime(true) + $seconds;
        while (!$done() && microtime(true) < $end) {
            $ide->tick(0.001);
            usleep(10000);
        }
    }

    public function testAShellRunsCommandsInTheProjectFolder(): void
    {
        $ide = $this->ide();
        $ide->enableTerminal();
        self::assertTrue($ide->handle('project.info', [])['capabilities']['terminal']);
        $t = $ide->handle('terminal.open', ['cols' => 100, 'rows' => 20, 'shell' => '/bin/sh']);
        self::assertTrue($t['pty']);
        $ide->handle('terminal.write', ['id' => $t['id'], 'data' => "pwd; echo JUMP\$((40+2)); stty size\n"]);
        $this->until($ide, fn () => str_contains($this->screen($t['id']), 'JUMP42') && preg_match('/\b20 100\b/', $this->screen($t['id'])));
        $screen = $this->screen($t['id']);
        self::assertStringContainsString('JUMP42', $screen);
        self::assertStringContainsString(basename($this->dir), $screen);
        self::assertMatchesRegularExpression('/\b20 100\b/', $screen, 'the size given to the pseudo-terminal');

        // the window grows: the shell's terminal follows
        $ide->handle('terminal.resize', ['id' => $t['id'], 'cols' => 132, 'rows' => 40]);
        $this->until($ide, fn () => false, 0.3);
        $ide->handle('terminal.write', ['id' => $t['id'], 'data' => "stty size\n"]);
        $this->until($ide, fn () => (bool)preg_match('/\b40 132\b/', $this->screen($t['id'])));
        self::assertMatchesRegularExpression('/\b40 132\b/', $this->screen($t['id']));

        $ide->handle('terminal.write', ['id' => $t['id'], 'data' => "exit\n"]);
        $this->until($ide, fn () => in_array(['terminal.exit', ['id' => $t['id']]], $this->events, true));
        self::assertContains(['terminal.exit', ['id' => $t['id']]], $this->events);
        self::assertSame(0, $ide->terminals?->count());
    }

    public function testClosingATerminalEndsItsShell(): void
    {
        $ide = $this->ide();
        $ide->enableTerminal();
        $t = $ide->handle('terminal.open', ['shell' => '/bin/sh']);
        $ide->handle('terminal.close', ['id' => $t['id']]);
        self::assertContains(['terminal.exit', ['id' => $t['id']]], $this->events);
        self::assertFalse($ide->handle('terminal.write', ['id' => $t['id'], 'data' => "echo\n"]));
    }

    public function testTheWebIdeHasNoTerminal(): void
    {
        $ide = new Ide(new MemoryWorkspace(['composer.json' => '{}']));
        self::assertArrayNotHasKey('terminal', $ide->handle('project.info', [])['capabilities']);
        $this->expectException(\LogicException::class);
        $ide->enableTerminal();
    }
}
