<?php

namespace JumPHP\Ide\Tests;

use JumPHP\Ide\Async\Docker;
use JumPHP\Ide\Async\Processes;
use JumPHP\Ide\Async\Wsl;
use JumPHP\Ide\Run\Runner;
use PHPUnit\Framework\TestCase;

/** Windows with Docker Engine inside WSL (docs/windows-wsl.md), tried here with fake docker / wsl programs. */
final class WslTest extends TestCase
{
    private string $dir;
    private string $path;

    protected function setUp(): void
    {
        $this->dir = sys_get_temp_dir() . '/jumphp-wsl-' . bin2hex(random_bytes(4));
        mkdir("$this->dir/fake", 0777, true);
        $this->path = (string)getenv('PATH');
    }

    protected function tearDown(): void
    {
        putenv("PATH=$this->path");
        exec('rm -rf ' . escapeshellarg($this->dir));
    }

    public function testWindowsFoldersAreSeenFromWsl(): void
    {
        self::assertSame('/mnt/c/Users/x/Proj', Wsl::linuxPath('C:\\Users\\x\\Proj'));
        self::assertSame('/mnt/c/Users/x/My Game', Wsl::linuxPath('C:/Users/x/My Game/'));
        self::assertSame('/mnt/d/work', Wsl::linuxPath('/d/work'));
        self::assertSame('/home/x', Wsl::linuxPath('\\\\wsl$\\Ubuntu\\home\\x'));
        self::assertSame('/home/x/p', Wsl::linuxPath('\\\\wsl.localhost\\Ubuntu-24.04\\home\\x\\p\\'));
        self::assertSame('/', Wsl::linuxPath('\\\\wsl$\\Ubuntu'));
        self::assertNull(Wsl::linuxPath('relative\\dir'));
    }

    public function testArgumentsReachBashUnchanged(): void
    {
        $args = ['test', "it's \"quoted\"", '$HOME', 'a;b', 'two words', ''];
        $command = Wsl::command(['printf', '<%s>', ...$args], 'C:\\Users\\x');
        self::assertSame(['wsl.exe', '-e', 'bash', '-lc'], array_slice($command, 0, 4));
        self::assertStringStartsWith('cd /mnt/c/Users/x && exec printf ', $command[4]);
        $script = substr($command[4], strlen('cd /mnt/c/Users/x && '));
        self::assertSame('<' . implode('><', $args) . '>', shell_exec('bash -c ' . escapeshellarg($script)));
        self::assertStringContainsString(' /mnt/c/tmp/a.png', Wsl::command(['bin/jumphp', 'x', 'C:\\tmp\\a.png'], 'C:\\p')[4], 'a Windows path argument too');
    }

    public function testJumphpVariablesGoThroughWslenv(): void
    {
        self::assertSame('PATH/l:JUMPHP_SCENE:JUMPHP_DOCKER', Wsl::wslenv(['JUMPHP_SCENE' => 'x', 'HOME' => 'y', 'JUMPHP_DOCKER' => 'wsl'], 'PATH/l'));
    }

    public function testWithoutGitForWindowsTheRunGoesStraightIntoWsl(): void
    {
        $command = Runner::windowsCommand(['bin/jumphp', 'test', 'pong'], 'C:\\p', 'Windows', true, null);
        self::assertSame('cd /mnt/c/p && exec bin/jumphp test pong', end($command));
        self::assertSame(['bin/jumphp', 'x'], Runner::windowsCommand(['bin/jumphp', 'x'], $this->dir, 'Linux', true, null), 'not on Linux');
    }

    /** @return array{0: string, 1: list<string>, 2: string} the final state, the states seen, Docker::via() */
    private function dockerState(?string $docker, ?string $wsl): array
    {
        foreach (['docker' => $docker, 'wsl' => $wsl] as $name => $script) {
            if ($script !== null) {
                file_put_contents("$this->dir/fake/$name", "#!/bin/sh\n$script\n");
                chmod("$this->dir/fake/$name", 0755);
            }
        }
        putenv("PATH=$this->dir/fake:/usr/bin:/bin");     // no real docker of this computer
        $states = [];
        $processes = new Processes();
        $d = new Docker($processes, $this->dir, function (string $event, array $p) use (&$states) {
            $states[] = $p['state'];
        }, 'Windows');      // no Docker Desktop to start here
        $ready = $d->ensure();
        for ($i = 0; $i < 400 && !$ready->isDone(); $i++) {
            $processes->poll();
            $d->tick();
            usleep(10000);
        }
        self::assertTrue($ready->isDone());
        return [$ready->result()['state'], $states, $d->via()];
    }

    public function testDockerDesktopAnsweringIsUsedAsBefore(): void
    {
        [$state, , $via] = $this->dockerState('echo 29.1.0', 'echo 28.0.0');
        self::assertSame('ready', $state);
        self::assertSame('native', $via);
    }

    public function testOnlyWslDockerAnsweringMeansWsl(): void
    {
        [$state, $states, $via] = $this->dockerState('echo down >&2; exit 1', '[ "$2" = docker ] && { echo 28.0.0; exit 0; }; exit 1');
        self::assertSame('wsl', $state);
        self::assertSame(['checking', 'wsl'], $states);
        self::assertSame('wsl', $via);
    }

    public function testWslDockerWithoutWindowsDocker(): void
    {
        [$state] = $this->dockerState(null, '[ "$2" = docker ] && { echo 28.0.0; exit 0; }; exit 1');
        self::assertSame('wsl', $state);
    }

    public function testNoDockerAnywhereSaysWhatToInstall(): void
    {
        [$state] = $this->dockerState(null, 'exit 1');
        self::assertSame('missing', $state);
        [$state, , $via] = $this->dockerState('exit 1', 'exit 1');
        self::assertSame('failed', $state, 'a Windows docker that does not answer, no WSL Docker: as before');
        self::assertSame('native', $via);
    }
}
