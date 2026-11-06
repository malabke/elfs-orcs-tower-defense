<?php

namespace JumPHP\Ide\Tests;

use JumPHP\Ide\Async\Processes;
use JumPHP\Ide\Project\ProjectInfo;
use JumPHP\Ide\Run\Runner;
use JumPHP\Ide\Workspace\MemoryWorkspace;
use PHPUnit\Framework\TestCase;

/** A jumphp.net project made of the game alone (its composer.json pointing at ../../engine): run through the IDE's copy of JumPHP. */
final class LinkedGameTest extends TestCase
{
    public function testAGameAloneInItsRepositoryGetsTheGameCommands(): void
    {
        $ws = new MemoryWorkspace([
            'composer.json' => '{"repositories": [{"type": "path", "url": "../../engine"}]}',
            'config/game.yaml' => "game:\n  id: moktar\n  title: MOKTAR\n",
            'app.php' => '<?php',
        ]);
        $info = (new ProjectInfo($ws))->describe();
        self::assertTrue($info['linked']);
        self::assertSame('moktar', $info['modules'][0]['id']);
        $run = array_column($info['run'], null, 'id');
        self::assertSame(['@jumphp', 'run', 'moktar'], $run['run:moktar']['command']);
        self::assertSame('moktar', $run['run:moktar']['game']);
        self::assertArrayNotHasKey('composer:install', $run, 'not a plain composer project');
        self::assertArrayNotHasKey('lint', $run);
        self::assertSame(['@jumphp-update'], $run['update']['command']);
    }

    public function testItsCommandsGoThroughTheIdesCopyOfJumphp(): void
    {
        $runner = new Runner(new Processes(), '/home/me/moktar', fn () => null);
        $runner->setToolkit('/data/jumphp', 'https://example.com/jumphp.git');
        [$sh, $c, $script] = $runner->linkedCommand(['@jumphp', 'shot', 'moktar', '60'], 'moktar');
        self::assertSame(['sh', '-c'], [$sh, $c]);
        self::assertStringContainsString("tk='/data/jumphp'", $script);
        self::assertStringContainsString("git clone --depth 1 'https://example.com/jumphp.git' \"\$tk\"", $script);
        self::assertStringContainsString("ln -sfn '/home/me/moktar' \"\$tk/games/moktar\"", $script);
        self::assertStringEndsWith("exec \"\$tk/bin/jumphp\" 'shot' 'moktar' '60'", $script);
        self::assertStringEndsWith("\"\$tk/bin/jumphp\" build:site moktar && \"\$tk/bin/jumphp\" siteshot moktar",
            $runner->linkedCommand(['sh', '-c', '@jumphp build:site moktar && @jumphp siteshot moktar'], 'moktar')[2]);
    }
}
