<?php

namespace JumPHP\Ide\Tests;

use JumPHP\Ide\Async\Processes;
use JumPHP\Ide\Log\LogBuffer;
use PHPUnit\Framework\TestCase;

/** The IDE's logs: filtered entries, a ring in memory, a file that turns over, and what the programs it runs tell. */
final class LogBufferTest extends TestCase
{
    private string $dir;

    protected function setUp(): void
    {
        $this->dir = sys_get_temp_dir() . '/jumphp-log-' . bin2hex(random_bytes(4));
    }

    public function testEntriesAreFilteredByLevelSourceAndText(): void
    {
        $log = new LogBuffer();
        $log->add('debug', 'docker', 'pulling layers');
        $log->add('info', 'jumphp', '$ bin/jumphp test pong');
        $log->add('error', 'jumphp', 'ended with code 1: Fatal error');
        $log->add('warn', 'ide', 'index slow');
        self::assertCount(4, $log->list());
        self::assertSame(['jumphp', 'ide'], array_column($log->list(0, 'warn'), 'source'));
        self::assertSame(['error', 'warn'], array_column($log->list(0, 'warn'), 'level'));
        self::assertCount(2, $log->list(0, 'debug', 'jumphp'));
        self::assertCount(1, $log->list(0, 'debug', '', 'FATAL'));
        self::assertSame(['docker', 'ide', 'jumphp'], $log->sources());
        $after = $log->list()[1]['id'];
        self::assertCount(2, $log->list($after), 'only what came after');
    }

    public function testOnlyTheLastEntriesAreKeptAndTheListenerIsTold(): void
    {
        $log = new LogBuffer();
        $told = [];
        $log->onEntry(function (array $e) use (&$told): void { $told[] = $e['message']; });
        for ($i = 0; $i < LogBuffer::MAX + 20; $i++) {
            $log->add('info', 'ide', "line $i");
        }
        self::assertCount(LogBuffer::MAX, $log->list(0, 'debug', '', '', 100000));
        self::assertSame('line 20', $log->list(0, 'debug', '', '', 100000)[0]['message']);
        self::assertCount(LogBuffer::MAX + 20, $told);
        $log->clear();
        self::assertSame([], $log->list());
    }

    public function testTheFileKeepsEveryEntryAndTurnsOverWhenBig(): void
    {
        $file = "$this->dir/logs/ide.log";
        $log = new LogBuffer($file, 400);
        $log->add('error', 'ide', "first\nsecond line");
        self::assertMatchesRegularExpression('/^\d{4}-\d\d-\d\dT[\d:.]+Z ERROR ide: first\n {4}second line\n$/', (string)file_get_contents($file));
        for ($i = 0; $i < 12; $i++) {
            $log->add('info', 'ide', str_repeat('x', 60));
        }
        self::assertFileExists("$file.1", 'two files at most');
        self::assertLessThan(1000, filesize($file));
    }

    public function testTheProgramsTheIdeRunsAreLoggedWithTheirFailure(): void
    {
        $log = new LogBuffer();
        $processes = new Processes();
        $processes->logger = fn (string $level, string $source, string $message) => $log->add($level, $source, $message);
        $ok = $processes->start(['sh', '-c', 'echo fine'], sys_get_temp_dir());
        $bad = $processes->start(['sh', '-c', 'echo "boom happened" >&2; exit 3'], sys_get_temp_dir());
        for ($i = 0; $i < 300 && !($ok['done']->isDone() && $bad['done']->isDone()); $i++) {
            $processes->poll();
            usleep(10000);
        }
        self::assertTrue($ok['done']->isDone() && $bad['done']->isDone());
        $messages = array_column($log->list(), 'message');
        self::assertNotEmpty(array_filter($messages, fn ($m) => preg_match('#^\$ \S*sh -c #', $m) === 1));
        $errors = $log->list(0, 'error');
        self::assertCount(1, $errors);
        self::assertSame('sh', $errors[0]['source']);
        self::assertStringContainsString('code 3', $errors[0]['message']);
        self::assertStringContainsString('boom happened', $errors[0]['message']);
    }

    public function testASourceIsWhatAUserCallsTheProgram(): void
    {
        self::assertSame('jumphp', Processes::sourceOf(['/home/me/p/bin/jumphp', 'test', 'pong']));
        self::assertSame('jumphp', Processes::sourceOf(['bash', 'bin/jumphp', 'run']));
        self::assertSame('docker', Processes::sourceOf(['/usr/bin/docker', 'run']));
        self::assertSame('git', Processes::sourceOf(['git.exe', 'status']));
    }
}
