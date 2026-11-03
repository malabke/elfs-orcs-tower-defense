<?php

namespace JumPHP\Ide\Tests;

use JumPHP\Ide\Async\Deferred;
use JumPHP\Ide\Async\Processes;
use JumPHP\Ide\Debug\Debugger;
use PHPUnit\Framework\TestCase;

/**
 * The debugger with a real Xdebug: a PHP program started with Xdebug connects to the IDE, stops at a breakpoint, its
 * variables are read, an expression evaluated, it steps and ends. Needs the Xdebug extension: run in the desktop image
 * with Xdebug (bin/jumphp php ide with JUMPHP_XDEBUG set, or docker/desktop-xdebug); skipped elsewhere.
 */
final class DebuggerTest extends TestCase
{
    private string $dir;
    private Processes $processes;
    private Debugger $debugger;
    /** @var list<array{0: string, 1: array}> */
    private array $events = [];

    protected function setUp(): void
    {
        if (!extension_loaded('xdebug')) {
            self::markTestSkipped('Xdebug is not here (docker/desktop-xdebug runs these tests)');
        }
        $this->dir = sys_get_temp_dir() . '/jumphp-debug-' . bin2hex(random_bytes(4));
        mkdir("$this->dir/src", 0777, true);
        file_put_contents("$this->dir/src/game.php", <<<'PHP'
            <?php
            function total(array $scores): int
            {
                $sum = 0;
                foreach ($scores as $score) {
                    $sum += $score;
                }
                return $sum;
            }
            $hero = ['name' => 'Ada', 'lives' => 3];
            $points = total([10, 20, 12]);
            echo "points $points\n";
            PHP);
        $this->processes = new Processes();
        $this->debugger = new Debugger($this->dir, function (string $event, array $payload): void {
            $this->events[] = [$event, $payload];
        });
    }

    protected function tearDown(): void
    {
        if (isset($this->debugger)) {
            $this->debugger->close();
            exec('rm -rf ' . escapeshellarg($this->dir));
        }
    }

    private function until(callable $done, string $what): void
    {
        for ($i = 0; $i < 500; $i++) {
            $this->processes->poll();
            $this->debugger->tick();
            if ($done()) {
                return;
            }
            usleep(10000);
        }
        self::fail("timed out waiting for $what");
    }

    private function settle(Deferred $deferred): mixed
    {
        $this->until(fn () => $deferred->isDone(), 'an answer of Xdebug');
        return $deferred->result();
    }

    private function event(string $name): ?array
    {
        foreach (array_reverse($this->events) as [$event, $payload]) {
            if ($event === $name) {
                return $payload;
            }
        }
        return null;
    }

    public function testAProgramStopsAtABreakpointAndIsInspected(): void
    {
        $port = $this->debugger->listen();
        $this->debugger->setBreakpoints('src/game.php', [8]);      // return $sum;
        $run = $this->processes->start(['php', '-d', 'xdebug.mode=debug', '-d', 'xdebug.start_with_request=yes', '-d', 'xdebug.client_host=127.0.0.1',
            '-d', "xdebug.client_port=$port", 'src/game.php'], $this->dir, ['XDEBUG_MODE' => 'debug', 'XDEBUG_CONFIG' => "client_port=$port"]);

        $this->until(fn () => $this->event('debug.paused') !== null, 'the breakpoint');
        $paused = $this->event('debug.paused');
        self::assertSame('src/game.php', $paused['path']);
        self::assertSame(8, $paused['line']);
        self::assertSame('total', $paused['stack'][0]['where']);
        $session = $paused['session'];

        $variables = $this->settle($this->debugger->variables($session));
        $byName = array_column($variables, null, 'name');
        self::assertSame('42', $byName['$sum']['value']);
        self::assertSame('array(3)', $byName['$scores']['value']);
        $scores = $this->settle($this->debugger->children($session, '$scores'));
        self::assertSame(['10', '20', '12'], array_column($scores['items'], 'value'));
        self::assertSame('84', $this->settle($this->debugger->evaluate($session, '$sum * 2'))['value']);

        // a frame further up: the main script's variables
        $outer = array_column($this->settle($this->debugger->variables($session, 1)), null, 'name');
        self::assertSame('array(2)', $outer['$hero']['value']);

        $back = $this->settle($this->debugger->resume($session, 'step_out'));
        self::assertSame('break', $back['status']);
        self::assertSame(12, $back['line'], 'back in the main script, at the statement after the call');

        $this->debugger->resume($session, 'run');
        $this->until(fn () => $this->event('debug.ended') !== null, 'the end');
        $result = $this->settle($run['done']);
        self::assertSame("points 42\n", $result['stdout']);
    }

    public function testBreakpointsAddedWhileItRuns(): void
    {
        $port = $this->debugger->listen();
        // no breakpoint at first: a conditional one, set once the program is there (as when the user clicks the gutter)
        $this->debugger->setBreakpoints('src/game.php', [['line' => 6, 'condition' => '$score == 20']]);
        $this->processes->start(['php', '-d', 'xdebug.mode=debug', '-d', 'xdebug.start_with_request=yes', '-d', 'xdebug.client_host=127.0.0.1',
            '-d', "xdebug.client_port=$port", 'src/game.php'], $this->dir, ['XDEBUG_MODE' => 'debug']);
        $this->until(fn () => $this->event('debug.paused') !== null, 'the conditional breakpoint');
        $paused = $this->event('debug.paused');
        $variables = array_column($this->settle($this->debugger->variables($paused['session'])), null, 'name');
        self::assertSame('20', $variables['$score']['value'], 'stopped only when the condition holds');
        self::assertSame('10', $variables['$sum']['value']);
        $this->debugger->setBreakpoints('src/game.php', []);
        $this->debugger->resume($paused['session'], 'run');
        $this->until(fn () => $this->event('debug.ended') !== null, 'the end');
    }

    private function start(int $port, string $script = 'src/game.php'): array
    {
        return $this->processes->start(['php', '-d', 'xdebug.mode=debug', '-d', 'xdebug.start_with_request=yes', '-d', 'xdebug.client_host=127.0.0.1',
            '-d', "xdebug.client_port=$port", $script], $this->dir, ['XDEBUG_MODE' => 'debug']);
    }

    public function testRunToCursorStopsOnceAtThatLine(): void
    {
        $port = $this->debugger->listen();
        $this->debugger->setBreakpoints('src/game.php', [4]);       // $sum = 0;
        $run = $this->start($port);
        $this->until(fn () => $this->event('debug.paused') !== null, 'the breakpoint');
        $session = $this->event('debug.paused')['session'];
        $at = $this->settle($this->debugger->runTo($session, 'src/game.php', 12));     // echo "points..."
        self::assertSame(12, $at['line'], 'ran to the cursor');
        $points = array_column($this->settle($this->debugger->variables($session)), null, 'name');
        self::assertSame('42', $points['$points']['value']);
        // the temporary breakpoint is gone: it runs to the end
        $this->debugger->resume($session, 'run');
        $this->until(fn () => $this->event('debug.ended') !== null, 'the end');
        self::assertSame("points 42\n", $this->settle($run['done'])['stdout']);
    }

    public function testAVariableGetsANewValueAndTheProgramUsesIt(): void
    {
        $port = $this->debugger->listen();
        $this->debugger->setBreakpoints('src/game.php', [8]);       // return $sum;
        $run = $this->start($port);
        $this->until(fn () => $this->event('debug.paused') !== null, 'the breakpoint');
        $session = $this->event('debug.paused')['session'];
        $sum = $this->settle($this->debugger->setValue($session, '$sum', '1000 + 1'));
        self::assertSame('1001', $sum['value']);
        $up = $this->settle($this->debugger->setValue($session, '$hero[\'lives\']', '9', 1));     // a frame further up
        self::assertSame('9', $up['value']);
        $this->debugger->resume($session, 'run');
        $this->until(fn () => $this->event('debug.ended') !== null, 'the end');
        self::assertSame("points 1001\n", $this->settle($run['done'])['stdout']);
    }

    public function testItStopsWhereAnExceptionIsThrown(): void
    {
        file_put_contents("$this->dir/src/boom.php", <<<'PHP'
            <?php
            function load(string $level): array
            {
                if ($level === '') {
                    throw new InvalidArgumentException('no level name');
                }
                return [];
            }
            try {
                load('');
            } catch (InvalidArgumentException $e) {
                echo "caught\n";
            }
            PHP);
        $port = $this->debugger->listen();
        self::assertSame(['InvalidArgumentException'], $this->debugger->setExceptionBreakpoints(['\\InvalidArgumentException', '']));
        $run = $this->start($port, 'src/boom.php');
        $this->until(fn () => $this->event('debug.paused') !== null, 'the exception');
        $paused = $this->event('debug.paused');
        self::assertSame(5, $paused['line'], 'where it is thrown, even though it is caught');
        self::assertSame('InvalidArgumentException', $paused['exception']['class']);
        self::assertSame('no level name', $paused['exception']['message']);
        self::assertSame('load', $paused['stack'][0]['where']);
        $this->debugger->setExceptionBreakpoints([]);
        $this->debugger->resume($paused['session'], 'run');
        $this->until(fn () => $this->event('debug.ended') !== null, 'the end');
        self::assertSame("caught\n", $this->settle($run['done'])['stdout']);
    }
}
