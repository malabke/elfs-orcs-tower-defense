<?php

namespace JumphpPlugins\SkeletonPlugin\Tests;

use JumPHP\Ide\Testing\PluginTestCase;

final class TodoPluginTest extends PluginTestCase
{
    public function testTheToolWindowListsTheTodosOfTheProject(): void
    {
        $this->project([
            'src/Hero.php' => "<?php\n// TODO: jump higher\nclass Hero {}\n",
            'README.md' => "# Game\n<!-- FIXME: the title -->\n",
        ]);
        $text = $this->toolText('todos');
        self::assertStringContainsString('TODO: jump higher', $text);
        self::assertStringContainsString('FIXME: the title', $text);
        self::assertStringContainsString('2 of 2', $text);
    }

    public function testAClickOpensTheLine(): void
    {
        $this->project(['src/Hero.php' => "<?php\n\n// TODO: jump higher\n"]);
        $this->click('todos', 'jump higher');
        self::assertSame(['path' => 'src/Hero.php', 'line' => 3], $this->opened());
    }

    public function testTheFilterAndTheFixmeOnlyBox(): void
    {
        $this->project(['a.php' => "<?php\n// TODO: sound\n// FIXME: crash\n// TODO: music\n"]);
        self::assertStringContainsString('1 of 3', implode(' ', array_column($this->submit('todos', 'Filter', 'music')['children'], 'text')));
        $this->submit('todos', 'Filter', '');
        $this->click('todos', 'FIXME only');
        $text = $this->toolText('todos');
        self::assertStringContainsString('FIXME: crash', $text);
        self::assertStringNotContainsString('sound', $text);
    }

    public function testASavedFileUpdatesTheList(): void
    {
        $this->project(['a.php' => "<?php\n"]);
        self::assertStringContainsString('No TODO in the project', $this->toolText('todos'));
        $this->save('a.php', "<?php\n// TODO: new one\n");
        self::assertStringContainsString('new one', $this->toolText('todos'));
    }

    public function testTheActionCountsTheTodosOfTheFile(): void
    {
        $this->project(['a.php' => "<?php\n// TODO: one\n// FIXME: two\n"]);
        $this->action('count', 'a.php');
        self::assertSame(['2 TODO / FIXME in a.php.'], $this->notifications());
    }

    public function testAFixmeIsAWarningWhileOneTypes(): void
    {
        $problems = $this->problems('a.php', "<?php\n\n  // FIXME: leaks\n");
        self::assertCount(1, $problems);
        self::assertSame(3, $problems[0]['startLine']);
        self::assertSame('FIXME: leaks', $problems[0]['message']);
    }

    public function testTodoIsCompletedInAComment(): void
    {
        self::assertSame(['@todo'], $this->complete('a.php', "<?php\n// @to|"));
        self::assertSame([], $this->complete('a.php', "<?php\n\$x = |"));
    }
}
