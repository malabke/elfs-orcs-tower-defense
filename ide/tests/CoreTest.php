<?php

namespace JumPHP\Ide\Tests;

use JumPHP\Ide\Async\Processes;
use JumPHP\Ide\Git\Git;
use JumPHP\Ide\Ide;
use JumPHP\Ide\Project\ProjectInfo;
use JumPHP\Ide\Search\Search;
use JumPHP\Ide\Templates;
use JumPHP\Ide\Workspace\LocalWorkspace;
use JumPHP\Ide\Workspace\MemoryChangeStore;
use JumPHP\Ide\Workspace\MemoryWorkspace;
use JumPHP\Ide\Workspace\OverlayWorkspace;
use JumPHP\Ide\Workspace\WorkspaceException;
use PHPUnit\Framework\TestCase;

final class CoreTest extends TestCase
{
    private string $dir;

    protected function setUp(): void
    {
        $this->dir = sys_get_temp_dir() . '/jumphp-ide-' . bin2hex(random_bytes(4));
        mkdir($this->dir);
    }

    protected function tearDown(): void
    {
        exec('rm -rf ' . escapeshellarg($this->dir));
    }

    public function testALocalFolderListsItsFilesButNotItsDependencies(): void
    {
        $ws = new LocalWorkspace($this->dir);
        $ws->write('src/A.php', '<?php');
        $ws->write('vendor/x/B.php', '<?php');
        $ws->write('.git/HEAD', 'ref');
        self::assertSame(['src/A.php'], array_keys($ws->files()));
        self::assertContains('vendor/x/B.php', array_keys($ws->files(true)));
        $ws->rename('src', 'lib');
        self::assertTrue($ws->exists('lib/A.php'));
        $this->expectException(WorkspaceException::class);
        $ws->read('../etc/passwd');
    }

    public function testChangesOnTopOfAPublishedVersion(): void
    {
        $store = new MemoryChangeStore();
        $ws = new OverlayWorkspace(new MemoryWorkspace(['a.php' => 'A', 'b.php' => 'B']), $store);
        $ws->write('a.php', 'A2');
        $ws->write('c.php', 'C');
        $ws->delete('b.php');
        self::assertSame(['a.php', 'c.php'], array_keys($ws->files()));
        self::assertSame(['a.php' => 'A2', 'c.php' => 'C', 'b.php' => null], $store->all());
        self::assertSame('modified', $ws->state('a.php'));
        self::assertSame('deleted', $ws->state('b.php'));
        $ws->write('a.php', 'A');
        self::assertNull($ws->state('a.php'), 'back to the published text: no change');
        $ws->rename('c.php', 'd.php');
        self::assertEquals(['d.php' => 'C', 'b.php' => null], $store->all());
        $ws->discard();
        self::assertSame([], $store->all());
    }

    public function testFindAndReplaceInFiles(): void
    {
        $ws = new MemoryWorkspace(['src/A.php' => "<?php\n\$speed = 3;\n\$maxSpeed = 9;", 'README.md' => 'Speed matters', 'img.png' => "\x89PNG"]);
        $search = new Search($ws);
        $found = $search->find('speed', ['word' => true]);
        self::assertSame(2, $found['matches']);
        self::assertSame(['line' => 2, 'col' => 2, 'length' => 5, 'text' => '$speed = 3;'], $found['results'][1]['matches'][0]);
        self::assertSame(1, $search->find('speed', ['case' => true, 'include' => '*.php'])['files']);
        self::assertSame(1, $search->find('max\w+', ['regex' => true])['matches']);
        $search->replace('speed', 'velocity', ['word' => true, 'case' => true]);
        self::assertSame("<?php\n\$velocity = 3;\n\$maxSpeed = 9;", $ws->read('src/A.php'));
        self::assertSame('src/A.php', $search->files('sa')[0]['path']);
    }

    public function testNewFilesStartInTheirNamespace(): void
    {
        $ws = new MemoryWorkspace(['games/x/composer.json' => '{"autoload": {"psr-4": {"App\\\\": "src/"}}, "autoload-dev": {"psr-4": {"Tests\\\\": "tests/"}}}']);
        self::assertSame('App\\Scene', Templates::namespaceOf('games/x/src/Scene/PlayScene.php', $ws));
        self::assertStringContainsString("namespace App\\Scene;\n\nuse JumPHP\\Renderer\\Renderer;", Templates::forFile('games/x/src/Scene/PlayScene.php', $ws));
        self::assertStringContainsString('extends GameTestCase', Templates::forFile('games/x/tests/JumpTest.php', $ws));
    }

    public function testTheProjectKnowsItsGamesAndApps(): void
    {
        $ws = new MemoryWorkspace([
            'bin/jumphp' => '#!/bin/bash',
            'games/pong/config/game.yaml' => "game:\n  id: pong\n  title: \"PONG\"\n",
            'games/notes/config/game.yaml' => "game:\n  ui: webview\n  app:\n    pages: ui\n",
            'games/retro/config/game.yaml' => "game:\n  title: Retro\n",
            'games/retro/BRIEF.md' => "Remake of an Amstrad CPC game from its .dsk",
        ]);
        $info = (new ProjectInfo($ws))->describe();
        $kinds = array_column($info['modules'], 'kind', 'id');
        self::assertSame(['notes' => 'app', 'pong' => 'game', 'retro' => 'cpc'], $kinds);
        $ids = array_column($info['run'], 'id');
        self::assertContains('run:pong', $ids);
        self::assertContains('export:notes', $ids);
        $notes = array_values(array_filter($info['run'], fn ($c) => $c['id'] === 'run:notes'))[0];
        self::assertSame(['bin/jumphp', 'app:run', 'notes'], $notes['command']);
    }

    public function testTestResultsAreReadFromTheOutput(): void
    {
        $output = "Paddle (Tests\\Paddle)\n ✔ The paddle moves\n ✘ The paddle stops\n   │\n   │ Failed asserting that 3 is 4.\n   │\n\nBall (Tests\\Ball)\n ✔ The ball bounces\n";
        $tests = ProjectInfo::parseTests($output);
        self::assertSame(2, $tests['passed']);
        self::assertSame(1, $tests['failed']);
        self::assertSame('The paddle stops', $tests['suites'][0]['tests'][1]['name']);
        self::assertStringContainsString('Failed asserting', $tests['suites'][0]['tests'][1]['message']);
    }

    public function testGitStatusCommitAndLog(): void
    {
        $processes = new Processes();
        $git = new Git($processes, $this->dir);
        $git->setFallbackAuthor(['name' => 'Agent', 'email' => 'agent@localhost']);
        $processes->wait($git->run(['init', '-q', '-b', 'main']));
        file_put_contents("$this->dir/a.txt", "one\n");
        file_put_contents("$this->dir/b.txt", "two\n");
        $status = $processes->wait($git->status());
        self::assertSame('main', $status['branch']);
        self::assertSame(['untracked', 'untracked'], array_column($status['files'], 'status'));

        $sha = $processes->wait($git->commit('First commit', ['a.txt', 'b.txt']));
        self::assertMatchesRegularExpression('/^[0-9a-f]{40}$/', $sha);
        file_put_contents("$this->dir/a.txt", "one more\n");
        unlink("$this->dir/b.txt");
        $status = $processes->wait($git->status());
        self::assertSame(['modified', 'deleted'], array_column($status['files'], 'status'));
        self::assertSame("one\n", $processes->wait($git->show('a.txt')));

        $processes->wait($git->rollback(['a.txt', 'b.txt']));
        self::assertSame([], $processes->wait($git->status())['files']);
        $log = $processes->wait($git->log());
        self::assertSame('First commit', $log[0]['subject']);
        self::assertSame('Agent', $log[0]['author']);
        self::assertSame([['status' => 'A', 'from' => null, 'path' => 'a.txt'], ['status' => 'A', 'from' => null, 'path' => 'b.txt']], $processes->wait($git->commitFiles($sha)));
        self::assertSame('First commit', $processes->wait($git->blame('a.txt'))[0]['summary']);
        self::assertSame(['main'], array_column($processes->wait($git->branches()), 'name'));
    }

    public function testProgramsAreFoundEvenWithTheMinimalPathOfADesktopLauncher(): void
    {
        $saved = (string)getenv('PATH');
        $home = (string)getenv('HOME');
        mkdir("$this->dir/local-bin");
        file_put_contents("$this->dir/local-bin/docker", "#!/bin/sh\necho 'Docker version 99'\n");
        chmod("$this->dir/local-bin/docker", 0755);
        putenv('PATH=/usr/bin:/bin');                       // what an app started from the menu often gets
        putenv('HOME=' . $this->dir);
        rename("$this->dir/local-bin", "$this->dir/bin");    // ~/bin is one of the usual folders
        try {
            self::assertContains('/usr/local/bin', explode(':', \JumPHP\Ide\Async\Environment::path()), 'the usual folders are added');
            self::assertSame("$this->dir/bin/docker", \JumPHP\Ide\Async\Environment::which('docker'));
            $processes = new Processes();
            $result = $processes->wait($processes->start(['docker', '--version'], $this->dir)['done']);
            self::assertSame("Docker version 99\n", $result['stdout']);
        } finally {
            putenv("PATH=$saved");
            putenv("HOME=$home");
        }
    }

    public function testClaudeCodeAnswersAsItGoesAndTheConversationGoesOn(): void
    {
        $saved = (string)getenv('PATH');
        mkdir("$this->dir/fake");
        // a stand-in claude: its arguments, then stream-json lines (one split across two writes)
        file_put_contents("$this->dir/fake/claude", "#!/bin/sh\n[ \"\$1\" = --version ] && { echo '9.9 (Claude Code)'; exit 0; }\n"
            . "echo \"\$@\" > args.txt\n"
            . "printf '%s\\n' '{\"type\":\"system\",\"subtype\":\"init\",\"session_id\":\"sess-12345678\"}'\n"
            . "printf '%s' '{\"type\":\"assistant\",\"message\":{\"content\":[{\"type\":\"text\",'\n"
            . "printf '%s\\n' '\"text\":\"Hello\"}]}}'\n"
            . "printf '%s\\n' '{\"type\":\"result\",\"subtype\":\"success\",\"session_id\":\"sess-12345678\"}'\n");
        chmod("$this->dir/fake/claude", 0755);
        putenv("PATH=$this->dir/fake:$saved");
        try {
            $events = [];
            $processes = new Processes();
            $ide = new Ide(new LocalWorkspace($this->dir), null, function (string $event, array $payload) use (&$events) {
                $events[] = [$event, $payload];
            }, $processes);
            self::assertTrue($ide->handle('project.info', [])['capabilities']['claude']);
            self::assertSame('9.9 (Claude Code)', $ide->handle('claude.status', [])['version']);
            $run = $ide->handle('claude.send', ['prompt' => 'Say hello', 'session' => 'sess-00000000', 'mode' => 'plan']);
            for ($i = 0; $i < 500 && !array_filter($events, fn ($e) => $e[0] === 'claude.exit'); $i++) {
                $processes->poll();
                usleep(5000);
            }
            $types = array_map(fn ($e) => $e[1]['event']['type'], array_values(array_filter($events, fn ($e) => $e[0] === 'claude.event')));
            self::assertSame(['system', 'assistant', 'result'], $types);
            self::assertSame('Hello', array_values(array_filter($events, fn ($e) => ($e[1]['event']['type'] ?? '') === 'assistant'))[0][1]['event']['message']['content'][0]['text']);
            self::assertSame(['claude.exit', ['id' => $run['id'], 'code' => 0, 'error' => null]], end($events));
            self::assertSame("-p Say hello --output-format stream-json --verbose --permission-mode plan --resume sess-00000000\n", file_get_contents("$this->dir/args.txt"));
        } finally {
            putenv("PATH=$saved");
        }
    }

    public function testTheCommandsOfTheIde(): void
    {
        $ws = new LocalWorkspace($this->dir);
        $ws->write('composer.json', '{"autoload": {"psr-4": {"App\\\\": "src/"}}}');
        $events = [];
        $ide = new Ide($ws, null, function (string $event, array $payload) use (&$events) {
            $events[] = $event;
        });
        $ide->tick(1.0);
        self::assertContains('index.progress', $events);
        $ide->handle('file.create', ['path' => 'src/Hero.php']);
        self::assertStringContainsString("namespace App;\n\nfinal class Hero", $ide->handle('file.read', ['path' => 'src/Hero.php'])['content']);
        self::assertSame('Hero', $ide->handle('php.symbols', ['query' => 'Hero'])[0]['name']);
        $ide->handle('edits.apply', ['edits' => ['src/Hero.php' => [['line' => 5, 'col' => 13, 'length' => 4, 'text' => 'Ship']]]]);
        self::assertStringContainsString('final class Ship', $ws->read('src/Hero.php'));
        self::assertFalse($ide->handle('project.info', [])['capabilities']['git']);
    }

    public function testAGameWithoutItsEngineSeesTheEngineTheHostGives(): void
    {
        $engine = dirname(__DIR__, 2) . '/engine';
        $text = "<?php\nnamespace App\\Scene;\n\nuse JumPHP\\Ui\\MenuScene;\n\nfinal class TitleScene extends MenuScene\n{\n    protected function items(): array\n    {\n        \\SDL_Delay(1);\n        \$this->refresh();\n        return [];\n    }\n}\n";
        $ws = new MemoryWorkspace(['composer.json' => '{"autoload": {"psr-4": {"App\\\\": "src/"}}}', 'src/Scene/TitleScene.php' => $text]);
        $ide = new Ide($ws);
        $ide->addLibrary('JumPHP\\', "$engine/src", "$engine/stubs");
        $ide->tick(1.0);
        $problems = array_column($ide->handle('php.diagnostics', ['path' => 'src/Scene/TitleScene.php', 'text' => $text]), 'message');
        self::assertSame([], $problems);
        $definition = $ide->handle('php.definition', ['path' => 'src/Scene/TitleScene.php', 'text' => $text, 'line' => 6, 'column' => 35]);
        self::assertStringEndsWith('/engine/src/Ui/MenuScene.php', $definition[0]['path']);
        $file = $ide->handle('file.read', ['path' => $definition[0]['path']]);
        self::assertTrue($file['readOnly']);
        self::assertStringContainsString('class MenuScene', $file['content']);

        // the Project view lists the engine under External Libraries, the Hierarchy view climbs into it
        $library = $ide->handle('libraries.list', [])[0];
        self::assertSame(['JumPHP', true], [$library['prefix'], $library['external']]);
        self::assertContains('Ui/MenuScene.php', $ide->handle('libraries.files', ['dir' => $library['dir']]));
        $tree = $ide->handle('php.hierarchy', ['path' => 'src/Scene/TitleScene.php', 'text' => $text, 'line' => 9, 'column' => 5]);
        self::assertSame('App\\Scene\\TitleScene', $tree['name']);
        self::assertSame(['JumPHP\\Ui\\MenuScene', 'extends'], [$tree['supers'][0]['name'], $tree['supers'][0]['relation']]);
        self::assertSame('JumPHP\\Scene\\AbstractScene', $tree['supers'][0]['supers'][0]['name']);
        $scene = $ide->handle('php.hierarchy', ['class' => 'JumPHP\\Ui\\MenuScene']);
        self::assertContains('App\\Scene\\TitleScene', array_column($scene['subs'], 'name'));
    }
}
