<?php

namespace JumPHP\Ide\Tests;

use JumPHP\Ide\Async\Deferred;
use JumPHP\Ide\Async\Processes;
use JumPHP\Ide\Project\JumphpUpdater;
use PHPUnit\Framework\TestCase;

/** A project's JumPHP brought to the newest JumPHP (a local repository stands for JumPHP's), its games never touched. */
final class JumphpUpdaterTest extends TestCase
{
    private string $dir;
    private Processes $processes;

    protected function setUp(): void
    {
        $this->dir = sys_get_temp_dir() . '/jumphp-update-' . bin2hex(random_bytes(4));
        mkdir($this->dir);
        $this->processes = new Processes();
        // "JumPHP": a first version
        $this->sh('mkdir -p up/bin up/engine up/tools up/games/pong && cd up && git init -q -b main && git config user.email t@t && git config user.name t'
            . ' && echo v1 > bin/jumphp && echo e1 > engine/Engine.php && echo t1 > tools/a.sh && echo old > tools/gone.sh && echo pong > games/pong/x && echo readme > README.md'
            . ' && git add -A && git commit -qm one');
    }

    protected function tearDown(): void
    {
        exec('rm -rf ' . escapeshellarg($this->dir));
    }

    private function sh(string $command): string
    {
        exec('cd ' . escapeshellarg($this->dir) . ' && (' . $command . ') 2>&1', $out, $code);
        self::assertSame(0, $code, implode("\n", $out));
        return trim(implode("\n", $out));
    }

    /** A project made from JumPHP's first version as the IDE makes them: a fresh history, the commit recorded. */
    private function project(bool $record = true): JumphpUpdater
    {
        $first = $this->sh('git -C up rev-parse HEAD');
        $this->sh('mkdir app && cd up && git archive HEAD | tar -x -C ../app && cd ../app && rm -rf games/pong && mkdir -p games/clock && echo mine > games/clock/Main.php && git init -q -b main');
        if ($record) {
            mkdir("$this->dir/app/.jumphp");
            file_put_contents("$this->dir/app/.jumphp/jumphp.json", json_encode(['commit' => $first]));
        }
        // JumPHP moves on
        $this->sh('cd up && echo v2 > bin/jumphp && echo e2 > engine/Engine.php && echo n > tools/new.sh && git rm -q tools/gone.sh && echo readme2 > README.md && git add -A && git commit -qm two');
        return new JumphpUpdater($this->processes, "$this->dir/app", "$this->dir/up", "$this->dir/cache/jumphp.git", $this->sh('git -C up rev-list --max-parents=0 HEAD'));
    }

    private function settle(Deferred $deferred): mixed
    {
        for ($i = 0; $i < 1000 && !$deferred->isDone(); $i++) {
            $this->processes->poll();
            usleep(5000);
        }
        return $deferred->result();
    }

    public function testAnUntouchedProjectIsUpdatedAndItsGamesAreLeftAlone(): void
    {
        $updater = $this->project();
        $plan = $this->settle($updater->check());
        self::assertFalse($plan['upToDate']);
        self::assertSame([], $plan['changed']);
        $this->settle($updater->update());
        $app = "$this->dir/app";
        self::assertSame("v2\n", file_get_contents("$app/bin/jumphp"));
        self::assertSame("e2\n", file_get_contents("$app/engine/Engine.php"));
        self::assertFileExists("$app/tools/new.sh");
        self::assertFileDoesNotExist("$app/tools/gone.sh");
        self::assertSame("readme\n", file_get_contents("$app/README.md"), 'the project\'s README is its own');
        self::assertSame("mine\n", file_get_contents("$app/games/clock/Main.php"));
        self::assertDirectoryDoesNotExist("$app/games/pong");
        self::assertSame($this->sh('git -C up rev-parse HEAD'), $updater->current());
        self::assertTrue($this->settle($updater->check())['upToDate']);
        self::assertFileDoesNotExist("$app/.git/shallow", 'the project\'s git is never fetched into');
        self::assertSame('', $this->sh('cd app && git status --porcelain --untracked-files=no'), 'nothing staged');
    }

    public function testJumphpsOwnRepositoryIsLeftToGit(): void
    {
        $updater = new JumphpUpdater($this->processes, "$this->dir/up", "$this->dir/up", "$this->dir/cache/jumphp.git", $this->sh('git -C up rev-list --max-parents=0 HEAD'));
        file_put_contents("$this->dir/up/bin/jumphp", "work in progress\n");
        $plan = $this->settle($updater->check());
        self::assertTrue($plan['upToDate']);
        self::assertTrue($plan['history']);
        self::assertDirectoryDoesNotExist("$this->dir/cache", 'nothing fetched');
        self::assertSame("work in progress\n", file_get_contents("$this->dir/up/bin/jumphp"));
    }

    public function testAFileTheUserChangedIsKeptUnlessForced(): void
    {
        $updater = $this->project();
        file_put_contents("$this->dir/app/engine/Engine.php", "my fix\n");
        $plan = $this->settle($updater->check());
        self::assertSame(['engine/Engine.php'], $plan['changed']);
        $this->settle($updater->update());
        self::assertSame("my fix\n", file_get_contents("$this->dir/app/engine/Engine.php"));
        self::assertSame("v2\n", file_get_contents("$this->dir/app/bin/jumphp"));
        self::assertFalse($this->settle($updater->check())['upToDate'], 'asked again: not complete');
        $this->settle($updater->update(true));
        self::assertSame("e2\n", file_get_contents("$this->dir/app/engine/Engine.php"));
    }

    public function testAnOlderProjectWithoutItsRecordCountsEveryDifferenceAsAChange(): void
    {
        $updater = $this->project(false);
        $plan = $this->settle($updater->check());
        self::assertFalse($plan['known']);
        self::assertContains('bin/jumphp', $plan['changed']);
        $this->settle($updater->update(true));
        self::assertSame("v2\n", file_get_contents("$this->dir/app/bin/jumphp"));
        self::assertNotNull($updater->current());
    }
}
