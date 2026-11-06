<?php

namespace JumPHP\Ide\Tests;

use JumPHP\Ide\Async\Docker;
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

    /** Windows' way (no non-blocking pipes): the output through temporary files, read as it grows; stdin a pipe. */
    public function testDockerDesktopIsStartedInTheBackgroundWhenDockerDoesNotAnswer(): void
    {
        $saved = (string)getenv('PATH');
        @mkdir("$this->dir/fake", 0777, true);
        // a docker that answers only once "Docker Desktop" (the launch command: touch) has started
        file_put_contents("$this->dir/fake/docker", "#!/bin/sh\n[ -f \"$this->dir/desktop-up\" ] && { echo 29.1.0; exit 0; }\necho 'Cannot connect to the Docker daemon' >&2; exit 1\n");
        chmod("$this->dir/fake/docker", 0755);
        putenv("PATH=$this->dir/fake:$saved");
        try {
            $states = [];
            $processes = new Processes();
            $docker = new Docker($processes, $this->dir, function (string $event, array $payload) use (&$states) {
                $states[] = $payload['state'];
            }, 'Linux', ['touch', "$this->dir/desktop-up"]);
            $ready = $docker->ensure();
            self::assertSame($ready, $docker->ensure(), 'one wait for everyone');
            for ($i = 0; $i < 400 && !$ready->isDone(); $i++) {
                $processes->poll();
                $docker->tick();
                usleep(20000);
            }
            self::assertSame('ready', $ready->result()['state']);
            self::assertSame(['checking', 'starting', 'ready'], $states);
            self::assertSame('ready', $docker->ensure()->result()['state'], 'known at once afterwards');
        } finally {
            putenv("PATH=$saved");
        }
    }

    public function testOnWindowsShellScriptsGoThroughGitBash(): void
    {
        mkdir("$this->dir/bin");
        file_put_contents("$this->dir/bin/jumphp", "#!/usr/bin/env bash\necho hi\n");
        $bash = 'C:\\Program Files\\Git\\bin\\bash.exe';
        $run = fn (array $c) => \JumPHP\Ide\Async\Environment::runnable($c, $this->dir, 'Windows', $bash);
        self::assertSame([$bash, 'bin/jumphp', 'install', 'pong'], $run(['bin/jumphp', 'install', 'pong']));
        self::assertSame([$bash, '-c', 'bin/jumphp build:site a && bin/jumphp siteshot a'], $run(['sh', '-c', 'bin/jumphp build:site a && bin/jumphp siteshot a']));
        self::assertSame(['git', 'status'], $run(['git', 'status']), 'programs stay as they are');
        self::assertSame(['bin/jumphp', 'test', 'x'], \JumPHP\Ide\Async\Environment::runnable(['bin/jumphp', 'test', 'x'], $this->dir, 'Linux'));
    }

    public function testNoDockerIsSaidAtOnce(): void
    {
        $saved = (string)getenv('PATH');
        putenv('PATH=/nonexistent');
        try {
            $docker = new Docker(new Processes(), $this->dir, fn () => null, 'Linux', ['true']);
            $state = $docker->ensure()->result();
            self::assertSame('missing', $state['state']);
            self::assertStringContainsString('Install Docker Desktop', $state['message']);
        } finally {
            putenv("PATH=$saved");
        }
    }

    public function testProgramsRunWithTheirOutputInFilesAsOnWindows(): void
    {
        $processes = new Processes(files: true);
        $seen = '';
        // like git clone --progress: a lot on stderr, a little on stdout, over several polls
        $clone = $processes->start(['sh', '-c', 'i=0; while [ $i -lt 3000 ]; do echo "Receiving objects: $i" >&2; i=$((i+1)); done; echo cloned'],
            $this->dir, [], function (string $chunk, string $stream) use (&$seen) { $seen .= $stream === 'stderr' ? '' : $chunk; });
        $result = $processes->wait($clone['done'], 20);
        self::assertSame(0, $result['code']);
        self::assertSame(3000, substr_count($result['stderr'], 'Receiving objects'));
        self::assertSame("cloned\n", $result['stdout']);
        self::assertSame("cloned\n", $seen);
        // a conversation (Claude): stdin kept open, answers read from the file as they come
        $cat = $processes->start(['cat'], $this->dir, [], null, "hello\n", true);
        self::assertTrue($processes->write($cat['id'], "again\n"));
        $processes->closeInput($cat['id']);
        self::assertSame("hello\nagain\n", $processes->wait($cat['done'], 20)['stdout']);
        self::assertSame([], glob(sys_get_temp_dir() . '/jph*') ?: [], 'temporary files left behind');
    }

    public function testClaudesConversationsAreKeptInTheProjectOutOfGit(): void
    {
        $ws = new LocalWorkspace($this->dir);
        $ide = new Ide($ws);
        self::assertSame([], $ide->handle('claude.history.list', []));
        $items = [['role' => 'user', 'text' => "Make a\nbreakout game"], ['role' => 'tool', 'name' => 'Write', 'input' => ['file_path' => 'a.php']],
            ['role' => 'assistant', 'text' => 'Done: **breakout**.']];
        $saved = $ide->handle('claude.history.save', ['conversation' => ['id' => 'c-1234', 'session' => 'sess-12345678', 'items' => $items]]);
        self::assertSame(['c-1234', 'Make a breakout game', 2], [$saved['id'], $saved['title'], $saved['messages']]);
        $ide->handle('claude.history.save', ['conversation' => ['id' => 'c-5678', 'title' => 'Second', 'items' => []]]);
        self::assertSame(['c-5678', 'c-1234'], array_column($ide->handle('claude.history.list', []), 'id'));
        $read = $ide->handle('claude.history.read', ['id' => 'c-1234']);
        self::assertSame(['sess-12345678', $items], [$read['session'], $read['items']]);
        self::assertStringContainsString('**You:** Make a', $ide->handle('claude.history.markdown', ['id' => 'c-1234']));
        self::assertFileExists("$this->dir/.jumphp/history/claude/c-1234.json");
        self::assertStringContainsString("\n*\n", (string)file_get_contents("$this->dir/.jumphp/history/.gitignore"));
        self::assertTrue($ide->handle('claude.history.delete', ['id' => 'c-5678']));
        self::assertSame(['c-1234'], array_column($ide->handle('claude.history.list', []), 'id'));
        $this->expectException(\InvalidArgumentException::class);
        $ide->handle('claude.history.read', ['id' => '../../etc/passwd']);
    }

    public function testChangingModeKeepsTheConversationsContext(): void
    {
        $saved = (string)getenv('PATH');
        mkdir("$this->dir/fake");
        // a stand-in claude: logs its arguments, answers each message with a result carrying the session
        file_put_contents("$this->dir/fake/claude", "#!/bin/sh\n[ \"\$1\" = --version ] && { echo '9.9'; exit 0; }\n"
            . "echo \"\$@\" >> args.txt\n"
            . "while read -r line; do printf '%s\\n' '{\"type\":\"result\",\"subtype\":\"success\",\"session_id\":\"sess-12345678\"}'; done\n");
        chmod("$this->dir/fake/claude", 0755);
        putenv("PATH=$this->dir/fake:$saved");
        try {
            $events = [];
            $processes = new Processes();
            $ide = new Ide(new LocalWorkspace($this->dir), null, function (string $event, array $payload) use (&$events) {
                $events[] = [$event, $payload];
            }, $processes);
            $turn = function (string $mode, ?string $session, int $results) use ($ide, $processes, &$events): array {
                $run = $ide->handle('claude.send', ['prompt' => 'Go on', 'session' => $session, 'mode' => $mode, 'conversation' => true]);
                for ($i = 0; $i < 500 && count(array_filter($events, fn ($e) => ($e[1]['event']['type'] ?? '') === 'result')) < $results; $i++) {
                    $processes->poll();
                    usleep(5000);
                }
                return $run;
            };
            $first = $turn('plan', null, 1);
            $second = $turn('auto', 'sess-12345678', 2);       // another mode: another process, the same session
            self::assertNotSame($first['id'], $second['id']);
            $args = (string)file_get_contents("$this->dir/args.txt");       // the system prompt has line breaks: both runs in one text
            self::assertSame(1, preg_match_all('/--permission-mode plan(?! --resume)/', $args));
            self::assertSame(1, preg_match_all('/--permission-mode auto --resume sess-12345678/', $args));
            $ide->handle('claude.stop', ['id' => $second['id']]);
            $ide->agent?->close();
        } finally {
            putenv("PATH=$saved");
        }
    }

    public function testClaudeCodeAnswersAsItGoesAndTheConversationGoesOn(): void
    {
        $saved = (string)getenv('PATH');
        mkdir("$this->dir/fake");
        // a stand-in claude --input-format stream-json: its arguments, the messages it reads (the second one arrives
        // in the middle of the first turn), stream-json lines (one split across two writes); it ends with its stdin
        file_put_contents("$this->dir/fake/claude", "#!/bin/sh\n[ \"\$1\" = --version ] && { echo '9.9 (Claude Code)'; exit 0; }\n"
            . "echo \"\$@\" >> args.txt\n"
            . "read -r first; echo \"\$first\" >> received.txt\n"
            . "printf '%s\\n' '{\"type\":\"system\",\"subtype\":\"init\",\"session_id\":\"sess-12345678\"}'\n"
            . "printf '%s' '{\"type\":\"assistant\",\"message\":{\"content\":[{\"type\":\"text\",'\n"
            . "printf '%s\\n' '\"text\":\"Hello\"}]}}'\n"
            . "read -r more; echo \"\$more\" >> received.txt\n"
            . "printf '%s\\n' '{\"type\":\"result\",\"subtype\":\"success\",\"session_id\":\"sess-12345678\"}'\n"
            . "while read -r line; do echo \"\$line\" >> received.txt; printf '%s\\n' '{\"type\":\"result\",\"subtype\":\"success\",\"session_id\":\"sess-12345678\"}'; done\n");
        chmod("$this->dir/fake/claude", 0755);
        putenv("PATH=$this->dir/fake:$saved");
        try {
            $events = [];
            $processes = new Processes();
            $ide = new Ide(new LocalWorkspace($this->dir), null, function (string $event, array $payload) use (&$events) {
                $events[] = [$event, $payload];
            }, $processes);
            $until = function (callable $done) use ($processes, &$events): void {
                for ($i = 0; $i < 500 && !$done($events); $i++) {
                    $processes->poll();
                    usleep(5000);
                }
            };
            $results = fn (array $events) => count(array_filter($events, fn ($e) => ($e[1]['event']['type'] ?? '') === 'result'));
            self::assertTrue($ide->handle('project.info', [])['capabilities']['claude']);
            self::assertSame('9.9 (Claude Code)', $ide->handle('claude.status', [])['version']);
            $send = fn (string $prompt, ?string $session) => $ide->handle('claude.send', ['prompt' => $prompt, 'session' => $session, 'mode' => 'plan', 'model' => 'claude-opus-5-5', 'effort' => 'xhigh']);
            $run = $send('Say hello', 'sess-00000000');
            $until(fn (array $events) => array_filter($events, fn ($e) => ($e[1]['event']['type'] ?? '') === 'assistant'));
            // while it works: the same process reads it at once
            $more = $send('Also say goodbye', 'sess-12345678');
            self::assertSame(['id' => $run['id'], 'joined' => true], $more);
            $until(fn (array $events) => $results($events) === 1);
            $types = array_map(fn ($e) => $e[1]['event']['type'], array_values(array_filter($events, fn ($e) => $e[0] === 'claude.event')));
            self::assertSame(['system', 'assistant', 'result'], $types);
            self::assertSame('Hello', array_values(array_filter($events, fn ($e) => ($e[1]['event']['type'] ?? '') === 'assistant'))[0][1]['event']['message']['content'][0]['text']);
            // the turn is over, the conversation is not: the next message goes to the same process
            self::assertSame(['id' => $run['id'], 'joined' => false], $send('And now?', 'sess-12345678'));
            $until(fn (array $events) => $results($events) === 2);
            self::assertSame([], array_filter($events, fn ($e) => $e[0] === 'claude.exit'));
            $received = array_map(fn ($l) => json_decode($l, true)['message']['content'][0]['text'], file("$this->dir/received.txt", FILE_IGNORE_NEW_LINES));
            self::assertSame(['Say hello', 'Also say goodbye', 'And now?'], $received);
            // a new conversation: the old process ends (claude.exit), another one starts
            $other = $send('Hi', null);
            self::assertNotSame($run['id'], $other['id']);
            $until(fn (array $events) => array_filter($events, fn ($e) => $e[0] === 'claude.exit'));
            self::assertSame(['claude.exit', ['id' => $run['id'], 'conv' => null, 'code' => 0, 'error' => null]], array_values(array_filter($events, fn ($e) => $e[0] === 'claude.exit'))[0]);
            // another tab: a conversation of its own beside it; neither stops the other
            $tab = $ide->handle('claude.send', ['prompt' => 'Other tab', 'conv' => 'tab2']);
            self::assertNotSame($other['id'], $tab['id']);
            self::assertSame(['id' => $other['id'], 'joined' => true], $send('Hi again', null));
            self::assertTrue($processes->isRunning($other['id']) && $processes->isRunning($tab['id']));
            $ide->handle('claude.close', ['conv' => 'tab2']);
            $until(fn (array $events) => count(array_filter($events, fn ($e) => $e[0] === 'claude.exit')) === 2);
            self::assertFalse($processes->isRunning($tab['id']));
            self::assertTrue($processes->isRunning($other['id']));
            $ide->handle('claude.stop', ['id' => $other['id']]);
            $args = (string)file("$this->dir/args.txt")[0];
            self::assertStringStartsWith('-p --input-format stream-json --output-format stream-json --verbose --permission-mode plan --resume sess-00000000 --model claude-opus-5-5 --effort xhigh ', $args);
            // the IDE's own tools: its MCP server (a config file with the token), allowed, and told to Claude
            // bin/jumphp without asking, and what needs a yes asked in the IDE (the permission tool)
            self::assertMatchesRegularExpression('#--mcp-config (\S+jumphp-ide-mcp-\d+\.json) --allowedTools mcp__jumphp-ide Bash\(bin/jumphp:\*\) .+? --permission-prompt-tool mcp__jumphp-ide__permission --append-system-prompt You are running inside the JumPHP IDE#', $args);
            preg_match('#--mcp-config (\S+)#', $args, $m);
            self::assertSame($ide->agent?->server()->url(), json_decode((string)file_get_contents($m[1]), true)['mcpServers']['jumphp-ide']['url']);
            $ide->agent?->close();
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

    public function testAComposerInstallAfterTheProjectOpenedAddsItsPackagesToExternalLibraries(): void
    {
        // a new CLI app: opened before `composer install` ends, the libraries still being indexed
        $ws = new LocalWorkspace($this->dir);
        $ws->write('composer.json', '{"autoload": {"psr-4": {"App\\\\": "src/"}}}');
        $text = "<?php\nnamespace App\\Command;\n\nuse Symfony\\Component\\Console\\Attribute\\AsCommand;\n\n#[AsCommand('hello')]\nfinal class Hello\n{\n}\n";
        $ws->write('src/Command/Hello.php', $text);
        $ide = new Ide($ws);
        $ide->tick(0.0001);
        self::assertSame([], array_column($ide->handle('libraries.list', []), 'prefix'));

        $ws->write('vendor/composer/autoload_psr4.php', "<?php\n\$vendorDir = dirname(__DIR__);\n\$baseDir = dirname(\$vendorDir);\nreturn array(\n    'Symfony\\\\Component\\\\Console\\\\' => array(\$vendorDir . '/symfony/console'),\n    'App\\\\' => array(\$baseDir . '/src'),\n);\n");
        $ws->write('vendor/symfony/console/Attribute/AsCommand.php', "<?php\nnamespace Symfony\\Component\\Console\\Attribute;\n\n#[\\Attribute]\nclass AsCommand\n{\n}\n");
        $ide->filesChanged();
        self::assertContains('Symfony\\Component\\Console', array_column($ide->handle('libraries.list', []), 'prefix'));
        $definition = $ide->handle('php.definition', ['path' => 'src/Command/Hello.php', 'text' => $text, 'line' => 4, 'column' => 50]);
        self::assertSame('vendor/symfony/console/Attribute/AsCommand.php', $definition[0]['path']);
    }

    public function testAFileOfTheEngineLinkedByVendorOpensFromExternalLibraries(): void
    {
        // a game of a JumPHP checkout: vendor/jumphp/engine links to the engine, outside the game's folder
        $engine = (string)realpath(dirname(__DIR__, 2) . '/engine');
        $ws = new LocalWorkspace($this->dir);
        $ws->write('composer.json', '{"autoload": {"psr-4": {"App\\\\": "src/"}}}');
        $ws->write('vendor/composer/autoload_psr4.php', "<?php\n\$vendorDir = dirname(__DIR__);\nreturn array(\n    'JumPHP\\\\' => array(\$vendorDir . '/jumphp/engine/src'),\n);\n");
        mkdir("$this->dir/vendor/jumphp");
        symlink($engine, "$this->dir/vendor/jumphp/engine");
        $ide = new Ide($ws);
        $library = $ide->handle('libraries.list', [])[0];
        self::assertSame(["$engine/src", true], [$library['dir'], $library['external']]);
        $file = $ide->handle('file.read', ['path' => "$engine/src/Ui/MenuScene.php"]);
        self::assertTrue($file['readOnly']);
        self::assertStringContainsString('class MenuScene', $file['content']);
        $this->expectException(WorkspaceException::class);
        $ide->handle('file.read', ['path' => dirname($engine) . '/bin/jumphp']);
    }
}
