<?php

namespace JumPHP\Tests\Tauri;

use JumPHP\Async\Task;
use JumPHP\Native\Dialog;
use JumPHP\Native\FilePicker;
use JumPHP\Native\Native;
use JumPHP\Native\Notification;
use JumPHP\Native\NullProvider;
use JumPHP\Native\Shell;
use JumPHP\Native\UnsupportedFeature;
use JumPHP\Tauri\App;
use JumPHP\Tauri\FakeRuntime;
use JumPHP\Tauri\NativeEvent;
use JumPHP\Tauri\TauriRuntime;
use PHPUnit\Framework\TestCase;
use React\EventLoop\Loop;
use React\EventLoop\StreamSelectLoop;

final class AppTest extends TestCase
{
    private FakeRuntime $runtime;
    private App $app;

    protected function setUp(): void
    {
        Loop::set(new StreamSelectLoop());
        Native::setProvider(null);
        $this->runtime = new FakeRuntime();
        $this->app = App::boot(__DIR__ . '/../fixtures/app', $this->runtime);
        $this->runtime->start([], fn () => $this->app->frame());
    }

    protected function tearDown(): void
    {
        Native::setProvider(null);
        Loop::stop();
    }

    private function invoke(string $name, mixed $payload = null, int $id = 1): ?array
    {
        $this->runtime->push(['type' => 'invoke', 'id' => $id, 'name' => $name, 'payload' => $payload, 'window' => 'main']);
        $this->runtime->frame();
        foreach ($this->runtime->sentOf('invoke.resolve') as $r) {
            if ($r['id'] === $id) {
                return $r;
            }
        }
        return null;
    }

    public function testBootOpensTheMainWindowFromTheConfig(): void
    {
        $create = $this->runtime->sentOf('window.create')[0];
        self::assertSame('main', $create['window']);
        self::assertSame(640, $create['width']);
        self::assertSame('Fixture App', $create['title']);
        self::assertStringEndsWith('/webview', $create['data_directory']);
        self::assertStringEndsWith('fixtures/app/ui', $this->app->pagesDir());
    }

    public function testRunGivesTheRuntimeTheAppIdentity(): void
    {
        $runtime = new FakeRuntime(3);
        $app = App::boot(__DIR__ . '/../fixtures/app', $runtime);
        self::assertSame(0, $app->run());
        self::assertSame('net.jumphp.fixtureapp', $runtime->config['identifier']);
        self::assertSame('Fixture App', $runtime->config['product_name']);
        self::assertSame(3, $app->frameCount());
    }

    public function testACommandAnswersThePage(): void
    {
        $this->app->command('add', fn (array $p) => $p['a'] + $p['b']);
        $reply = $this->invoke('add', ['a' => 2, 'b' => 3]);
        self::assertTrue($reply['ok']);
        self::assertSame(5, $reply['result']);
        self::assertSame('main', $reply['window']);
    }

    public function testAnUnknownCommandOrAnExceptionRejects(): void
    {
        self::assertFalse($this->invoke('nope', null, 1)['ok']);
        $this->app->command('boom', fn () => throw new \DomainException('bad input'));
        $reply = $this->invoke('boom', null, 2);
        self::assertFalse($reply['ok']);
        self::assertSame('bad input', $reply['result']['message']);
        self::assertSame(\DomainException::class, $reply['result']['class']);
    }

    public function testATaskAnswersWhenItEnds(): void
    {
        $task = new Task();
        $this->app->command('later', fn () => $task);
        self::assertNull($this->invoke('later'));
        $task->settle(['done' => true]);
        $replies = $this->runtime->sentOf('invoke.resolve');
        self::assertSame(['done' => true], end($replies)['result']);
    }

    public function testPageMessagesAndLoadedReachPhp(): void
    {
        $got = null;
        $this->app->on('page:hello', function ($payload, string $window) use (&$got) {
            $got = [$payload, $window];
        });
        $this->runtime->push(['type' => 'page', 'name' => 'hello', 'payload' => ['x' => 1], 'window' => 'main']);
        $this->runtime->push(['type' => 'page', 'name' => '__loaded', 'payload' => 'tauri://localhost', 'window' => 'main']);
        $this->runtime->frame();
        self::assertSame([['x' => 1], 'main'], $got);
        self::assertTrue($this->app->window()->isLoaded());
    }

    public function testWindowCommandsAndSendToThePage(): void
    {
        $this->app->window()->setTitle('T')->setSize(800, 600)->send('tick', [1]);
        self::assertSame('T', $this->runtime->sentOf('window.set_title')[0]['title']);
        self::assertSame(['cmd' => 'webview.emit', 'window' => 'main', 'name' => 'tick', 'payload' => [1]], $this->runtime->sentOf('webview.emit')[0]);
    }

    public function testCloseRequestedClosesUnlessPrevented(): void
    {
        $this->runtime->push(['type' => 'window', 'window' => 'main', 'event' => 'close-requested']);
        $this->runtime->frame();
        self::assertCount(1, $this->runtime->sentOf('window.close'));

        $this->app->window()->on('close-requested', fn (NativeEvent $e) => $e->preventDefault());
        $this->runtime->push(['type' => 'window', 'window' => 'main', 'event' => 'close-requested']);
        $this->runtime->frame();
        self::assertCount(1, $this->runtime->sentOf('window.close'));
    }

    public function testTheLastWindowGoneQuitsUnlessPrevented(): void
    {
        $this->runtime->push(['type' => 'window', 'window' => 'main', 'event' => 'destroyed']);
        $this->runtime->push(['type' => 'exit-requested']);
        $this->runtime->frame();
        self::assertSame([], $this->app->windows());
        self::assertSame(0, $this->runtime->exitCode());
    }

    public function testNativeDialogsGoThroughTauriAndComeBack(): void
    {
        self::assertSame('tauri', Native::name());
        $answer = 'none';
        Dialog::confirm('Sure?', 'App', 'Yes', 'No')->then(function ($yes) use (&$answer) {
            $answer = $yes;
        });
        $cmd = $this->runtime->sentOf('dialog.confirm')[0];
        self::assertSame(['Sure?', 'Yes', 'No', 'main'], [$cmd['message'], $cmd['ok'], $cmd['cancel'], $cmd['window']]);
        $this->runtime->push(['type' => 'dialog', 'id' => $cmd['id'], 'result' => true]);
        $this->runtime->frame();
        self::assertTrue($answer);

        FilePicker::open(['filters' => ['Images' => ['.png', '*.jpg'], 'All' => ['*']], 'multiple' => true]);
        $open = $this->runtime->sentOf('dialog.open')[0];
        self::assertSame(['Images' => ['png', 'jpg'], 'All' => ['*']], $open['filters']);
        self::assertTrue($open['multiple']);
        self::assertTrue(Notification::show('Hi', 'there'));
        self::assertTrue(Shell::openUrl('https://jumphp.net'));
        self::assertFalse(Shell::openUrl('file:///etc/passwd'));
    }

    public function testBuiltInNativeCommandsForPages(): void
    {
        $this->invoke('native.message', ['message' => 'Hi'], 7);
        self::assertSame('Hi', $this->runtime->sentOf('dialog.message')[0]['message']);
        $reply = $this->invoke('native.notify', ['title' => 'T'], 8);
        self::assertTrue($reply['result']);
    }

    public function testAnotherTargetSaysWhatItLacks(): void
    {
        Native::setProvider(new NullProvider());
        self::assertFalse(Native::supports('open'));
        $error = null;
        FilePicker::open()->then(fn () => null, function (\Throwable $e) use (&$error) {
            $error = $e;
        });
        self::assertInstanceOf(UnsupportedFeature::class, $error);
        self::assertFalse(Notification::show('x'));
    }

    public function testTheLibraryIsFoundFromTheEnvironment(): void
    {
        putenv('JUMPHP_TAURI_LIB=' . __FILE__);
        try {
            self::assertSame(__FILE__, TauriRuntime::library('/nowhere'));
        } finally {
            putenv('JUMPHP_TAURI_LIB');
        }
        putenv('JUMPHP_TAURI_LIB=/nowhere/lib.so');
        try {
            self::assertNull(TauriRuntime::library('/nowhere'));
        } finally {
            putenv('JUMPHP_TAURI_LIB');
        }
        self::assertMatchesRegularExpression('/^(linux|windows|macos)-/', TauriRuntime::platform());
    }
}
