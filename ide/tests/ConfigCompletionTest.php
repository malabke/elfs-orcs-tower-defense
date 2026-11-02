<?php

namespace JumPHP\Ide\Tests;

use JumPHP\Ide\Config\ConfigCompletion;
use JumPHP\Ide\Php\Index;
use JumPHP\Ide\Workspace\MemoryWorkspace;
use PHPUnit\Framework\TestCase;

final class ConfigCompletionTest extends TestCase
{
    /** @return list<string> */
    private function labels(string $path, string $text): array
    {
        $ws = new MemoryWorkspace([
            'games/x/src/Scene/PlayScene.php' => "<?php\nnamespace App\\Scene;\n\nclass PlayScene {}\n",
            'games/x/src/Scene/TitleScene.php' => "<?php\nnamespace App\\Scene;\n\nclass TitleScene {}\n",
        ]);
        $index = new Index($ws);
        $index->refresh();
        $offset = strpos($text, '|');
        $before = substr($text, 0, $offset);
        $line = substr_count($before, "\n") + 1;
        $col = strlen($before) - (strrpos($before, "\n") === false ? 0 : strrpos($before, "\n") + 1) + 1;
        $items = (new ConfigCompletion($index))->complete($path, str_replace('|', '', $text), $line, $col)['items'];
        return array_map(fn ($i) => $i['label'], $items);
    }

    public function testGameYamlKeysNotWrittenYet(): void
    {
        $labels = $this->labels('games/x/config/game.yaml', "game:\n  title: X\n  |\n");
        self::assertContains('start_scene', $labels);
        self::assertContains('pause_scene', $labels);
        self::assertNotContains('title', $labels, 'already written');
    }

    public function testTheScenesOfTheProjectForStartScene(): void
    {
        self::assertSame(['App\\Scene\\PlayScene', 'App\\Scene\\TitleScene'], $this->labels('games/x/config/game.yaml', "game:\n  start_scene: |\n"));
    }

    public function testNestedKeysAndTheirValues(): void
    {
        self::assertSame(['scene', 'params'], $this->labels('games/x/config/game.yaml', "game:\n  smoke:\n    |\n"));
        self::assertContains('async', $this->labels('games/x/config/game.yaml', "game:\n  runtime: |\n"));
        self::assertContains('ja', $this->labels('games/x/config/game.yaml', "game:\n  lang: |\n"));
        self::assertContains('Space', $this->labels('games/x/config/game.yaml', "game:\n  touch:\n    a: |\n"));
    }

    public function testInputBindingsInAFlowList(): void
    {
        $labels = $this->labels('games/x/config/input.yaml', "actions:\n  jump: [Space, |]\n");
        self::assertContains('pad0:a', $labels);
        self::assertContains('Left Shift', $labels);
        self::assertContains('touch:tap', $labels);
    }

    public function testEngineActionsAsKeys(): void
    {
        self::assertContains('confirm', $this->labels('games/x/config/input.yaml', "actions:\n  jump: [Space]\n  |\n"));
    }

    public function testOtherFilesAreLeftAlone(): void
    {
        self::assertSame([], $this->labels('games/x/config/services.yaml', "services:\n  |\n"));
    }
}
