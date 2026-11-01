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

    public function testClosingTheProjectShowsTheWelcomeScreenNextTime(): void
    {
        $this->ide('projects.close');
        self::assertNull($this->ide('session.state')['project']);
        self::assertSame(basename($this->project), $this->ide('session.state')['recent'][0]['name']);
    }
}
