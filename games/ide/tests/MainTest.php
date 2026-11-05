<?php

namespace Tests;

use JumPHP\Testing\AppTestCase;

/** The desktop IDE without a window: the test is the page calling `ide` {name, payload}. */
final class MainTest extends AppTestCase
{
    private string $project;
    private string $path = '';

    protected function setUp(): void
    {
        $this->project = sys_get_temp_dir() . '/jumphp-ide-test-' . bin2hex(random_bytes(4));
        mkdir($this->project . '/games/demo/config', 0777, true);
        mkdir($this->project . '/games/demo/src/Scene', 0777, true);
        mkdir($this->project . '/bin', 0777, true);
        file_put_contents($this->project . '/bin/jumphp', "#!/bin/bash\necho ran \"\$@\"\n");
        chmod($this->project . '/bin/jumphp', 0755);
        // bin/jumphp needs Docker: a stand-in, in a folder of the PATH
        mkdir($this->project . '/fakebin');
        file_put_contents($this->project . '/fakebin/docker', "#!/bin/sh\necho docker\n");
        chmod($this->project . '/fakebin/docker', 0755);
        $this->path = (string)getenv('PATH');
        putenv('PATH=' . $this->project . '/fakebin:' . $this->path);
        file_put_contents($this->project . '/games/demo/config/game.yaml', "game:\n  id: demo\n  title: Demo\n");
        file_put_contents($this->project . '/games/demo/composer.json', '{"autoload": {"psr-4": {"App\\\\": "src/"}}}');
        file_put_contents($this->project . '/games/demo/src/Scene/PlayScene.php', "<?php\nnamespace App\\Scene;\n\nfinal class PlayScene\n{\n    public int \$score = 0;\n    public function addPoint(): void { \$this->score++; }\n}\n");
        putenv('JUMPHP_IDE_PROJECT=' . $this->project);
        putenv('JUMPHP_IDE_SERVER=http://127.0.0.1:9');
        putenv('JUMPHP_IDE_UPDATES=0');    // no update check against GitHub during the tests
        parent::setUp();
    }

    protected function tearDown(): void
    {
        parent::tearDown();
        putenv('JUMPHP_IDE_PROJECT');
        putenv('PATH=' . $this->path);
        exec('rm -rf ' . escapeshellarg($this->project));
    }

    private function ide(string $name, array $payload = []): mixed
    {
        return $this->invoke('ide', ['name' => $name, 'payload' => $payload]);
    }

    public function testTheLastProjectOpensWithItsGameAndRunConfigurations(): void
    {
        $state = $this->ide('session.state');
        self::assertNull($state['user']);
        self::assertSame(basename($this->project), $state['project']['name']);
        self::assertSame('demo', $state['project']['modules'][0]['id']);
        self::assertContains('run:demo', array_column($state['project']['run'], 'id'));
    }

    public function testFilesAreReadWrittenAndIndexed(): void
    {
        $this->frames(5);
        $this->ide('file.write', ['path' => 'games/demo/src/Scene/PlayScene.php', 'content' => "<?php\nnamespace App\\Scene;\n\nfinal class PlayScene\n{\n    public int \$lives = 3;\n}\n"]);
        self::assertStringContainsString('$lives', $this->ide('file.read', ['path' => 'games/demo/src/Scene/PlayScene.php'])['content']);
        self::assertSame('PlayScene', $this->ide('php.symbols', ['query' => 'PlayScene'])[0]['name']);
        $created = $this->ide('file.create', ['path' => 'games/demo/src/Entity/Hero.php']);
        self::assertStringContainsString('namespace App\\Entity;', $this->ide('file.read', ['path' => $created['path']])['content']);
    }

    public function testCompletionComesFromThePhpSide(): void
    {
        $this->frames(5);
        $text = "<?php\nnamespace App\\Scene;\nfunction f(PlayScene \$s) {\n    \$s->";
        $items = $this->ide('php.complete', ['path' => 'x.php', 'text' => $text, 'line' => 4, 'column' => 9])['items'];
        self::assertSame(['addPoint', 'score'], array_column($items, 'label'));
    }

    public function testARunStreamsItsOutputToThePage(): void
    {
        $run = $this->ide('run.start', ['id' => 'test:demo']);
        $this->wait(0.5);
        $this->frames(10);
        $outputs = array_filter($this->sent('webview.emit'), fn ($c) => $c['name'] === 'run.output');
        self::assertStringContainsString('ran test demo', implode('', array_map(fn ($c) => $c['payload']['chunk'], $outputs)));
        $exit = array_values(array_filter($this->sent('webview.emit'), fn ($c) => $c['name'] === 'run.exit'));
        self::assertSame($run['id'], $exit[0]['payload']['id']);
        self::assertSame(0, $exit[0]['payload']['code']);
    }

    public function testSigningInIsNeededForThePlaygroundProjects(): void
    {
        $this->expectExceptionMessage('Sign in with jumphp.net first.');
        $this->ide('projects.remote');
        $this->frames(2);
    }

    public function testAPluginOrAnExtensionNeedsItsGitHubRepositoryAtOnceHenceTheSignIn(): void
    {
        foreach (['plugin', 'extension'] as $kind) {   // a game or an application is made on this computer: no account, no repository until it is published
            try {
                $this->ide('projects.create', ['kind' => $kind, 'title' => 'Todo', 'folder' => $this->project . '/new']);
                self::fail("$kind was created without a GitHub repository of its own");
            } catch (\Throwable $e) {
                self::assertStringContainsString('Sign in with jumphp.net first', $e->getMessage(), $kind);
            }
        }
        self::assertDirectoryDoesNotExist($this->project . '/new/todo');
    }

    public function testTheTrashDeletesAProjectOfTheRecentListOnly(): void
    {
        $other = sys_get_temp_dir() . '/jumphp-trash-' . bin2hex(random_bytes(4));
        mkdir("$other/.git", 0777, true);
        file_put_contents("$other/a.txt", 'x');
        try {
            $this->ide('projects.delete', ['path' => $other]);
            self::fail('a folder that is not in the recent list was deleted');
        } catch (\Throwable $e) {
            self::assertStringContainsString('recent list', $e->getMessage());
        }
        self::assertFileExists("$other/a.txt");
        $this->app->storage->writeJson('recent.json', [['path' => $other, 'name' => 'trash', 'opened' => time(), 'slug' => null]]);
        $r = $this->ide('projects.delete', ['path' => $other]);
        self::assertTrue($r['deleted']);
        self::assertDirectoryDoesNotExist($other);
        self::assertSame([], array_filter($this->ide('session.state')['recent'] ?? [], fn ($x) => $x['path'] === $other));
    }

    public function testAProjectWithoutARepositoryOfItsOwnIsOfferedOne(): void
    {
        $project = $this->ide('session.state')['project'];
        self::assertNull($project['repository']);
        self::assertTrue($project['needsRepository']);
        try {
            $this->ide('project.createRepository', ['name' => 'demo']);
            self::fail('a repository was made without the sign-in');
        } catch (\Throwable $e) {
            self::assertStringContainsString('Sign in with jumphp.net first', $e->getMessage());
        }
    }

    public function testTheRepositoryOfAProjectIsItsOriginNeverJumphps(): void
    {
        $root = $this->project . '/repo';
        mkdir($root);
        $git = fn (string $args) => exec('cd ' . escapeshellarg($root) . " && git $args 2>&1");
        $git('init -q -b main');
        self::assertNull(\App\Main::ownRepository($root));
        $git('remote add jumphp https://github.com/davidnurdin/jumPhp.git');
        $git('remote add origin https://github.com/davidnurdin/jumPhp.git');
        self::assertNull(\App\Main::ownRepository($root), 'origin = the framework, in a project made from it');
        $git('remote set-url origin git@github.com:someone/todojum.git');
        self::assertSame('someone/todojum', \App\Main::ownRepository($root));
    }

    public function testEveryKindOfTheIdeHasItsKindOnJumphpNet(): void
    {
        self::assertSame(['original', 'cpc', 'port', 'original', 'gui', 'mobile', 'desktop', 'cli'], array_values(\App\Creator::PLAYGROUND_KINDS));
    }

    public function testClaudeForJumphpNetSendsBackWhatClaudeChangedInTheGame(): void
    {
        $call = fn (string $method, mixed ...$args) => (new \ReflectionMethod(\App\ClaudeBridge::class, $method))->invoke(null, ...$args);
        $game = $this->project . '/games/demo';
        $before = $call('snapshot', $game);
        file_put_contents("$game/src/Scene/PlayScene.php", "<?php // faster\n");
        file_put_contents("$game/src/Hero.php", "<?php\n");
        unlink("$game/config/game.yaml");
        mkdir("$game/vendor");
        file_put_contents("$game/vendor/x.php", 'ignored');
        $changes = $call('changes', $game, $before);
        ksort($changes);
        self::assertSame(['config/game.yaml' => null, 'src/Hero.php' => base64_encode("<?php\n"), 'src/Scene/PlayScene.php' => base64_encode("<?php // faster\n")], $changes);
        // what the web IDE shows: paths in the game folder, not this computer's
        $event = $call('relative', ['type' => 'assistant', 'message' => ['content' => [['type' => 'tool_use', 'name' => 'Edit', 'input' => ['file_path' => "$game/src/Hero.php"]]]]],
            $this->project, 'games/demo');
        self::assertSame('src/Hero.php', $event['message']['content'][0]['input']['file_path']);
        // the switch, signed out: it asks to sign in first (the browser)
        self::assertFalse($this->ide('bridge.status')['signedIn']);
    }

    public function testClosingTheProjectShowsTheWelcomeScreenNextTime(): void
    {
        $this->ide('projects.close');
        self::assertNull($this->ide('session.state')['project']);
        self::assertSame(basename($this->project), $this->ide('session.state')['recent'][0]['name']);
    }
}
