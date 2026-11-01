<?php

namespace Tests;

use JumPHP\Testing\AppTestCase;

/** Hello Desktop played without a window: the test is the page (and the user answering the dialogs). */
final class MainTest extends AppTestCase
{
    private string $dir;

    protected function setUp(): void
    {
        parent::setUp();
        $this->dir = sys_get_temp_dir() . '/hello-desktop-test-' . getmypid();
        @mkdir($this->dir);
    }

    public function testThePageIsGreetedByPhp(): void
    {
        self::assertSame('Hello Ada, this is PHP ' . PHP_VERSION . '.', $this->invoke('greet', ['name' => 'Ada']));
    }

    public function testTheWindowHasAMenuWhoseItemsReachThePage(): void
    {
        $menu = $this->lastSent('menu.set');
        self::assertSame('main', $menu['window']);
        self::assertSame(['File', 'Edit', 'View'], array_column($menu['menu'], 'text'));
        self::assertSame(['id' => 'wrap', 'text' => 'Wrap lines', 'checked' => true], $menu['menu'][2]['items'][0]);

        $this->menu('wrap', 'main', false);
        self::assertSame(['name' => 'menu', 'payload' => ['item' => 'wrap', 'checked' => false]],
            array_intersect_key($this->lastSent('webview.emit'), ['name' => 1, 'payload' => 1]));
        $main = $this->app->main();
        self::assertInstanceOf(\App\Main::class, $main);
        self::assertSame(1, $main->menuClicks());
    }

    public function testThePageLearnsWhereItRuns(): void
    {
        $info = $this->invoke('info');
        self::assertSame(PHP_VERSION, $info['php']);
        self::assertSame('tauri', $info['native']);
    }

    public function testSaveAsAsksWhereThenWritesTheNote(): void
    {
        $id = $this->send('notes.saveAs', ['text' => "first line\n"]);
        $this->frames(1);
        self::assertSame('note.txt', $this->lastSent('dialog.save')['file_name']);
        $this->answerDialog($this->dir . '/my-note.txt');
        $answer = $this->answer($id);
        self::assertSame("first line\n", file_get_contents($this->dir . '/my-note.txt'));
        self::assertSame('my-note.txt', $answer['name']);
        self::assertStringContainsString('my-note.txt', $this->lastSent('window.set_title')['title']);
    }

    public function testOpenReadsTheChosenFile(): void
    {
        file_put_contents($this->dir . '/read-me.md', '# hello');
        $id = $this->send('notes.open');
        $this->frames(1);
        $this->answerDialog($this->dir . '/read-me.md');
        self::assertSame('# hello', $this->answer($id)['text']);
    }

    public function testACancelledOpenGivesNothing(): void
    {
        $id = $this->send('notes.open');
        $this->frames(1);
        $this->answerDialog(null);
        self::assertNull($this->answer($id));
    }

    public function testClosingWithUnsavedTextAsksAndCanStay(): void
    {
        $this->invoke('notes.changed', ['dirty' => true]);
        $this->requestClose();
        self::assertSame([], $this->sent('window.close'));
        self::assertSame('Keep editing', $this->lastSent('dialog.confirm')['cancel']);
        $this->answerDialog(false);
        self::assertSame([], $this->sent('app.exit'));
        $this->requestClose();
        $this->answerDialog(true);
        self::assertSame(0, $this->lastSent('app.exit')['code']);
    }

    public function testClosingWhenSavedClosesAtOnce(): void
    {
        $this->requestClose();
        self::assertNotNull($this->lastSent('window.close'));
        self::assertSame([], $this->sent('dialog.confirm'));
    }

    public function testDroppedFilesAreDescribedToThePage(): void
    {
        file_put_contents($this->dir . '/a.txt', 'abc');
        $this->runtime->push(['type' => 'window', 'window' => 'main', 'event' => 'drop', 'paths' => [$this->dir . '/a.txt', $this->dir]]);
        $this->frames(1);
        $sent = $this->lastSent('webview.emit');
        self::assertSame('dropped', $sent['name']);
        self::assertSame(['name' => 'a.txt', 'size' => 3, 'dir' => false], $sent['payload'][0]);
        self::assertTrue($sent['payload'][1]['dir']);
    }

    public function testTheClockTicksEverySecond(): void
    {
        $this->wait(1.2);
        $clocks = array_filter($this->sent('webview.emit'), static fn (array $c): bool => $c['name'] === 'clock');
        self::assertNotEmpty($clocks);
        self::assertMatchesRegularExpression('/^\d\d:\d\d:\d\d$/', end($clocks)['payload']['time']);
    }

    /** an invoke whose answer comes later (a dialog in between): its id */
    private function send(string $name, mixed $payload = null): int
    {
        static $id = 5000;
        $id++;
        $this->runtime->push(['type' => 'invoke', 'id' => $id, 'name' => $name, 'payload' => $payload, 'window' => 'main']);
        return $id;
    }

    private function answer(int $id): mixed
    {
        $this->frames(1);
        foreach ($this->sent('invoke.resolve') as $reply) {
            if ($reply['id'] === $id) {
                self::assertTrue($reply['ok'], (string)json_encode($reply['result']));
                return $reply['result'];
            }
        }
        self::fail("no answer to $id");
    }
}
