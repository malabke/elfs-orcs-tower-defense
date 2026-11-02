<?php

namespace JumPHP\Ide\Tests;

use JumPHP\Ide\Ide;
use JumPHP\Ide\Workspace\LocalWorkspace;
use PHPUnit\Framework\TestCase;

final class PluginHostTest extends TestCase
{
    private string $dir;

    /** @var list<array{string, array<string, mixed>}> */
    private array $events = [];

    protected function setUp(): void
    {
        $this->dir = sys_get_temp_dir() . '/jumphp-plugins-' . bin2hex(random_bytes(4));
        mkdir("$this->dir/project", 0777, true);
        mkdir("$this->dir/user", 0777, true);
        $this->events = [];
    }

    protected function tearDown(): void
    {
        exec('rm -rf ' . escapeshellarg($this->dir));
    }

    /** A plugin folder whose activate() body is $body ($ide is the Api). */
    private function plugin(string $parent, string $id, string $body): string
    {
        $ns = 'PluginFixture' . bin2hex(random_bytes(4));
        $dir = "$parent/$id";
        @mkdir("$dir/src", 0777, true);
        file_put_contents("$dir/composer.json", json_encode(['name' => "t/$id", 'description' => "the $id plugin",
            'autoload' => ['psr-4' => ["$ns\\" => 'src/']], 'extra' => ['jumphp-ide' => ['id' => $id, 'name' => ucfirst($id), 'version' => '1.0.0', 'class' => "$ns\\P"]]]));
        file_put_contents("$dir/src/P.php", "<?php\nnamespace $ns;\nuse JumPHP\\Ide\\Plugin\\{Api, Plugin, Ui, EditorState};\n"
            . "final class P implements Plugin { public function activate(Api \$ide): void { $body } }\n");
        return $dir;
    }

    private function ide(): Ide
    {
        $ide = new Ide(new LocalWorkspace("$this->dir/project"), null, function (string $e, array $p): void {
            $this->events[] = [$e, $p];
        });
        $ide->enablePlugins("$this->dir/user");
        return $ide;
    }

    public function testTheUsersAndTheProjectsPluginsLoadAndAddWhatTheyBring(): void
    {
        $this->plugin("$this->dir/user", 'alpha', "\$ide->action('hi', 'Say Hi', fn (EditorState \$at) => 'hi ' . \$at->path, ['kbd' => 'Ctrl+Alt+H']);"
            . "\$ide->runConfiguration('lint', 'Lint it', ['echo', 'ok'], ['kind' => 'tool']);");
        $this->plugin("$this->dir/project/.jumphp/plugins", 'beta', "\$ide->toolWindow('w', 'Beta', fn () => Ui::column('hello', Ui::button('Go', fn () => null)));");
        $ide = $this->ide();
        self::assertSame(['alpha' => 'active', 'beta' => 'active'], array_column($ide->handle('plugins.list', []), 'status', 'id'));
        $c = $ide->handle('plugins.contributions', []);
        self::assertSame('plugin:alpha:hi', $c['actions'][0]['id']);
        self::assertSame('Ctrl+Alt+H', $c['actions'][0]['kbd']);
        self::assertSame('Tools', $c['actions'][0]['menu']);
        self::assertSame(['message' => 'hi a.php'], $ide->handle('plugins.action', ['id' => 'plugin:alpha:hi', 'path' => 'a.php']));
        $view = $ide->handle('plugins.view', ['tool' => 'plugin:beta:w']);
        self::assertSame('hello', $view['children'][0]['text']);
        self::assertSame(0, $view['children'][1]['onClick']);
        self::assertContains('plugin:alpha:lint', array_column($ide->handle('project.info', [])['run'], 'id'));
        self::assertTrue($ide->handle('project.info', [])['capabilities']['plugins']);
    }

    public function testAFailingPluginIsReportedAndNeverTakesTheIdeDown(): void
    {
        $this->plugin("$this->dir/user", 'broken', "\$ide->action('x', 'X', fn () => 1); throw new \\RuntimeException('boom');");
        $this->plugin("$this->dir/user", 'flaky', "\$ide->action('x', 'X', fn () => throw new \\LogicException('oops'));"
            . "\$ide->toolWindow('w', 'W', fn () => throw new \\LogicException('no view'));"
            . "\$ide->every(0, fn () => throw new \\LogicException('tick'));");
        $ide = $this->ide();
        $list = array_column($ide->handle('plugins.list', []), null, 'id');
        self::assertSame('error', $list['broken']['status']);
        self::assertStringContainsString('boom', (string)$list['broken']['error']);
        self::assertSame(['plugin:flaky:x'], array_column($ide->handle('plugins.contributions', [])['actions'], 'id'));   // the broken one's action went away
        try {
            $ide->handle('plugins.action', ['id' => 'plugin:flaky:x']);
            self::fail('the action should fail');
        } catch (\RuntimeException $e) {
            self::assertSame('Flaky: oops', $e->getMessage());
        }
        self::assertStringContainsString('no view', json_encode($ide->handle('plugins.view', ['tool' => 'plugin:flaky:w'])));
        $ide->tick();
        self::assertContains('tick', array_map(fn ($e) => $e[1]['message'] ?? null, $this->events));
    }

    public function testCallbacksChangeThePluginsStateAndTheWindowIsDrawnAgain(): void
    {
        $this->plugin("$this->dir/user", 'count', "\$n = 0; \$ide->toolWindow('w', 'W', function () use (&\$n) { return Ui::column("
            . "Ui::text(\"n=\$n\"), Ui::button('+', function () use (&\$n) { \$n++; }), Ui::input('Set', function (string \$v) use (&\$n) { \$n = (int)\$v; })); });");
        $ide = $this->ide();
        $ide->handle('plugins.view', ['tool' => 'plugin:count:w']);
        $view = $ide->handle('plugins.callback', ['tool' => 'plugin:count:w', 'callback' => 0]);
        self::assertSame('n=1', $view['children'][0]['text']);
        $view = $ide->handle('plugins.callback', ['tool' => 'plugin:count:w', 'callback' => 1, 'value' => '42']);
        self::assertSame('n=42', $view['children'][0]['text']);
    }

    public function testCompletionProblemsAndSavedFilesReachTheMatchingPlugins(): void
    {
        $this->plugin("$this->dir/user", 'yaml', "\$ide->completion('*.yaml', fn (EditorState \$at) => [['label' => 'key:' . \$at->word()]]);"
            . "\$ide->diagnostics('config/*', fn (\$path, \$text) => str_contains(\$text, 'bad') ? [['line' => 2, 'message' => 'bad word', 'severity' => 'error']] : []);"
            . "\$ide->on('file.saved', fn (\$path) => \$ide->notify(\"saved \$path\"));");
        $ide = $this->ide();
        self::assertSame(['key:ti'], array_column($ide->handle('plugins.complete', ['path' => 'config/game.yaml', 'text' => "a\nti", 'line' => 2, 'column' => 3]), 'label'));
        self::assertSame([], $ide->handle('plugins.complete', ['path' => 'src/A.php', 'text' => 'x', 'line' => 1, 'column' => 2]));
        $d = $ide->handle('plugins.diagnostics', ['path' => 'config/game.yaml', 'text' => "a\nbad"]);
        self::assertSame([2, 'error', 'Yaml'], [$d[0]['startLine'], $d[0]['severity'], $d[0]['source']]);
        $ide->handle('file.write', ['path' => 'notes.txt', 'content' => 'x']);
        self::assertContains(['plugins.ui', ['do' => 'notify', 'message' => 'saved notes.txt', 'kind' => 'info', 'title' => 'Yaml']], $this->events);
    }

    public function testInstallDisableEnableRemoveAndSettings(): void
    {
        $source = $this->plugin("$this->dir/dev", 'gamma', "\$ide->action('a', 'A', fn () => \$ide->setting('greeting', 'hello'));");
        mkdir("$source/vendor/x", 0777, true);
        mkdir("$source/tests");
        $ide = $this->ide();
        self::assertSame([], $ide->handle('plugins.list', []));
        $installed = $ide->handle('plugins.install', ['dir' => $source]);
        self::assertSame('active', $installed['status']);
        self::assertDirectoryExists("$this->dir/user/gamma/src");
        self::assertDirectoryDoesNotExist("$this->dir/user/gamma/vendor");
        self::assertDirectoryDoesNotExist("$this->dir/user/gamma/tests");
        self::assertSame(['message' => 'hello'], $ide->handle('plugins.action', ['id' => 'plugin:gamma:a']));
        $ide->handle('plugins.setting', ['id' => 'gamma', 'name' => 'greeting', 'value' => 'bonjour']);
        self::assertSame(['message' => 'bonjour'], $ide->handle('plugins.action', ['id' => 'plugin:gamma:a']));
        self::assertTrue($ide->handle('plugins.install', ['dir' => $source])['restart']);    // already loaded: the new version at the next start

        self::assertSame('disabled', $ide->handle('plugins.enable', ['id' => 'gamma', 'on' => false])['status']);
        self::assertSame([], $ide->handle('plugins.contributions', [])['actions']);
        $again = $this->ide();      // the next start: still disabled
        self::assertSame('disabled', $again->handle('plugins.list', [])[0]['status']);
        self::assertSame('active', $again->handle('plugins.enable', ['id' => 'gamma', 'on' => true])['status']);
        self::assertTrue($again->handle('plugins.remove', ['id' => 'gamma']));
        self::assertDirectoryDoesNotExist("$this->dir/user/gamma");
        self::assertSame([], $again->handle('plugins.list', []));
    }
}
