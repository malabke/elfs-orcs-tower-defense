<?php

namespace Tests;

use App\Main;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Tester\ApplicationTester;

/** The commands, run as a user types them. */
final class CommandsTest extends TestCase
{
    private string $dir;
    private ApplicationTester $app;

    protected function setUp(): void
    {
        $this->dir = sys_get_temp_dir() . '/cli-' . bin2hex(random_bytes(4));
        $main = new Main($this->dir);
        $main->console->setAutoExit(false);
        $this->app = new ApplicationTester($main->console);
    }

    protected function tearDown(): void
    {
        @unlink("$this->dir/tasks.json");
        @rmdir($this->dir);
    }

    public function testAddThenList(): void
    {
        self::assertSame(0, $this->app->run(['command' => 'add', 'text' => ['Water', 'the', 'plants']]));
        self::assertStringContainsString('Task 1 added', $this->app->getDisplay());
        $this->app->run(['command' => 'list-tasks']);
        self::assertStringContainsString('Water the plants', $this->app->getDisplay());
    }

    public function testAddAsksWhatToDoWhenNothingIsGiven(): void
    {
        $this->app->setInputs(['Buy bread']);
        self::assertSame(0, $this->app->run(['command' => 'add']));
        self::assertStringContainsString('What to do?', $this->app->getDisplay());
        $this->app->run(['command' => 'list-tasks']);
        self::assertStringContainsString('Buy bread', $this->app->getDisplay());
    }

    public function testDoneMarksATask(): void
    {
        $this->app->run(['command' => 'add', 'text' => ['Call', 'Ada']]);
        $this->app->run(['command' => 'done', 'number' => '1']);
        self::assertStringContainsString('Done: Call Ada', $this->app->getDisplay());
    }

    public function testAnUnknownTaskFails(): void
    {
        self::assertSame(1, $this->app->run(['command' => 'done', 'number' => '7']));
        self::assertStringContainsString('There is no task 7', $this->app->getDisplay());
    }
}
