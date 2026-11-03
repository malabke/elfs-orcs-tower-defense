<?php

namespace JumPHP\Ide\Tests;

use JumPHP\Ide\Async\Docker;
use JumPHP\Ide\Async\Environment;
use JumPHP\Ide\Async\Processes;
use JumPHP\Ide\Run\Runner;
use PHPUnit\Framework\TestCase;

/**
 * What the IDE does on a real Windows (run by the workflow ide-windows on GitHub's Windows; skipped elsewhere):
 * programs whose output arrives as they write it, without the frame ever waiting; bin/jumphp through Git's bash;
 * a program stopped with what it started; a Docker that does not answer said in time, never waited for forever.
 */
final class WindowsTest extends TestCase
{
    private string $dir;

    protected function setUp(): void
    {
        if (PHP_OS_FAMILY !== 'Windows') {
            self::markTestSkipped('Windows only (workflow ide-windows)');
        }
        $this->dir = sys_get_temp_dir() . '\\jumphp-ide-' . bin2hex(random_bytes(4));
        mkdir($this->dir);
    }

    protected function tearDown(): void
    {
        if (PHP_OS_FAMILY === 'Windows') {
            exec('rmdir /s /q ' . escapeshellarg($this->dir));
        }
    }

    /** Polls until $done settles; fails if one poll() made the frame wait (the IDE polls once per frame). */
    private function pollUntil(Processes $processes, callable $done, float $timeout, ?Docker $docker = null): float
    {
        $start = microtime(true);
        $slowest = 0.0;
        while (!$done() && microtime(true) - $start < $timeout) {
            $t = microtime(true);
            $processes->poll();
            $docker?->tick();
            $slowest = max($slowest, microtime(true) - $t);
            usleep(15000);
        }
        self::assertLessThan(0.25, $slowest, 'a poll() made the frame wait');
        return microtime(true) - $start;
    }

    public function testTheOutputArrivesWhileTheProgramWrites(): void
    {
        $processes = new Processes();
        $chunks = [];
        $firstAt = null;
        $t0 = microtime(true);
        $script = 'for ($i = 0; $i < 40; $i++) { echo str_repeat("o", 2000), "\n"; fwrite(STDERR, "e$i\n"); usleep(50000); }';
        $run = $processes->start([PHP_BINARY, '-r', $script], $this->dir, [], function (string $chunk) use (&$chunks, &$firstAt, $t0) {
            $chunks[] = $chunk;
            $firstAt ??= microtime(true) - $t0;
        });
        $this->pollUntil($processes, fn () => $run['done']->isDone(), 30);
        $result = $run['done']->result();
        self::assertSame(0, $result['code']);
        self::assertSame(40 * 2001, strlen(str_replace("\r", '', $result['stdout'])));
        self::assertStringContainsString("e39\n", str_replace("\r", '', $result['stderr']));
        self::assertNotNull($firstAt);
        self::assertLessThan(1.5, $firstAt, 'the first output arrives while the program runs (it takes 2 s)');
        self::assertGreaterThan(5, count($chunks), 'read a little at a time');
    }

    public function testBinJumphpRunsThroughGitBash(): void
    {
        self::assertNotNull(Environment::gitBash(), 'Git for Windows bash');
        mkdir("$this->dir\\bin");
        file_put_contents("$this->dir\\bin\\jumphp", "#!/usr/bin/env bash\necho \"uname=\$(uname -s) args=\$*\"\nfor i in 1 2 3; do echo line \$i; sleep 0.3; done\n");
        $events = [];
        $runner = new Runner($processes = new Processes(), $this->dir, function (string $event, array $payload) use (&$events) {
            $events[] = [$event, $payload];
        });
        $saved = (string)getenv('PATH');
        putenv('PATH=' . dirname((string)Environment::gitBash()) . ';' . $saved);     // a docker command for its check
        @mkdir("$this->dir\\fake");
        file_put_contents("$this->dir\\fake\\docker.bat", "@echo 29.0.0\n");
        putenv("PATH=$this->dir\\fake;" . getenv('PATH'));
        try {
            $runner->start(['id' => 'x', 'label' => 'x', 'command' => ['bin/jumphp', 'install', 'pong'], 'kind' => 'tool']);
            $this->pollUntil($processes, fn () => in_array('run.exit', array_column($events, 0), true), 30);
        } finally {
            putenv("PATH=$saved");
        }
        $output = implode('', array_map(fn ($e) => $e[1]['chunk'], array_filter($events, fn ($e) => $e[0] === 'run.output')));
        $exit = array_values(array_filter($events, fn ($e) => $e[0] === 'run.exit'))[0][1];
        self::assertSame(0, $exit['code'], $output . ($exit['error'] ?? ''));
        self::assertMatchesRegularExpression('/uname=(MINGW|MSYS)/', $output);
        self::assertStringContainsString('args=install pong', $output);
        self::assertStringContainsString('line 3', $output);
    }

    public function testAStoppedRunEndsWithWhatItStarted(): void
    {
        $processes = new Processes();
        $bash = (string)Environment::gitBash();
        // bash starts a PHP that would run for 10 minutes: stopping the run must end both
        $run = $processes->start([$bash, '-c', escapeshellarg(str_replace('\\', '/', PHP_BINARY)) . ' -r "sleep(600);"'], $this->dir);
        $this->pollUntil($processes, fn () => false, 1.5);
        self::assertTrue($processes->isRunning($run['id']));
        $processes->stop($run['id']);
        $seconds = $this->pollUntil($processes, fn () => $run['done']->isDone(), 15);
        self::assertTrue($run['done']->isDone(), 'stopped');
        self::assertLessThan(10, $seconds);
        exec('tasklist /FI "IMAGENAME eq php.exe" /FO CSV /NH', $lines);
        $sleeping = array_filter($lines, fn ($l) => str_contains($l, 'php.exe'));
        self::assertLessThanOrEqual(1, count($sleeping), 'no PHP left sleeping (only this test\'s own): ' . implode(' | ', $lines));
    }

    public function testADockerThatDoesNotAnswerIsSaidInTime(): void
    {
        $states = [];
        $processes = new Processes();
        $docker = new Docker($processes, $this->dir, function (string $event, array $payload) use (&$states) {
            $states[] = $payload;
        });
        $ready = $docker->ensure();
        $seconds = $this->pollUntil($processes, fn () => $ready->isDone(), 60, $docker);
        $state = $ready->result();
        fwrite(STDERR, "\nDocker without its service: " . json_encode($states) . sprintf(' in %.1f s', $seconds) . "\n");
        self::assertNotSame('ready', $state['state'], 'the workflow stops the Docker service first');
        self::assertContains($state['state'], ['failed', 'missing']);
        self::assertStringContainsString('Docker', $state['message']);
        self::assertLessThan(30, $seconds, 'said in time');
    }

    public function testADockerThatHangsIsGivenUpAfterItsTimeout(): void
    {
        $saved = (string)getenv('PATH');
        @mkdir("$this->dir\\hang");
        file_put_contents("$this->dir\\hang\\docker.bat", "@ping -n 90 127.0.0.1 >NUL\n");    // a docker that never answers
        putenv("PATH=$this->dir\\hang;$saved");
        try {
            $processes = new Processes();
            $docker = new Docker($processes, $this->dir, fn () => null, 'Windows', ['cmd', '/c', 'exit', '0']);
            $ready = $docker->ensure();
            // a hung docker info is stopped after 20 s; then Docker Desktop is "started" (here: nothing) and polled
            $this->pollUntil($processes, fn () => $ready->isDone() || $docker->state()['state'] === 'starting', 40, $docker);
            self::assertSame('starting', $docker->state()['state'], json_encode($docker->state()));
        } finally {
            putenv("PATH=$saved");
        }
    }
}
