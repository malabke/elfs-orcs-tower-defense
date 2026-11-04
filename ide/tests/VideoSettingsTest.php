<?php

namespace JumPHP\Ide\Tests;

use JumPHP\Ide\Video\VideoSettings;
use JumPHP\Ide\Workspace\MemoryWorkspace;
use PHPUnit\Framework\TestCase;

final class VideoSettingsTest extends TestCase
{
    public function testTheGamesThatMakeVideosAreListedWithTheirTemplates(): void
    {
        $ws = new MemoryWorkspace([
            'games/shorts/config/project.yaml' => "project:\n  id: shorts\n  title: Shorts\n  width: 270\n  height: 480\n  video:\n    scene: 'App\\Scene\\ShortScene'\n    params: { template: rings }\n    templates: [growing, rings]\n    seconds: 20\n",
            'games/pong/config/project.yaml' => "project:\n  id: pong\n  title: Pong\n",
            'apps/old/config/game.yaml' => "game:\n  id: old\n  video:\n    scene: App\\Scene\\Clip\n",
        ], 'p');
        $list = (new VideoSettings($ws))->all([['id' => 'shorts', 'dir' => 'games/shorts'], ['id' => 'pong', 'dir' => 'games/pong'], ['id' => 'old', 'dir' => 'apps/old']]);
        self::assertSame(['shorts', 'old'], array_column($list, 'id'), 'pong has no video block');
        self::assertSame(['growing', 'rings'], $list[0]['templates']);
        self::assertSame(20.0, $list[0]['seconds']);
        self::assertSame(270, $list[0]['width']);
        self::assertSame('App\\Scene\\ShortScene', $list[0]['scene']);
        self::assertSame('rings', $list[0]['params']['template']);
        self::assertSame([], $list[1]['templates']);
    }
}
