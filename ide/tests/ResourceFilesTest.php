<?php

namespace JumPHP\Ide\Tests;

use JumPHP\Ide\Ide;
use JumPHP\Ide\Templates;
use JumPHP\Ide\Workspace\LocalWorkspace;
use JumPHP\Ide\Workspace\MemoryWorkspace;
use PHPUnit\Framework\TestCase;

final class ResourceFilesTest extends TestCase
{
    private string $dir;

    protected function setUp(): void
    {
        $this->dir = sys_get_temp_dir() . '/jumphp-ide-res-' . bin2hex(random_bytes(4));
        mkdir($this->dir);
    }

    protected function tearDown(): void
    {
        exec('rm -rf ' . escapeshellarg($this->dir));
    }

    public function testTheSpriteEditorSavesAPngAsItsBytes(): void
    {
        $png = base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mP8z8BQDwAEhQGAhKmMIQAAAABJRU5ErkJggg==', true);
        $ide = new Ide(new LocalWorkspace($this->dir));
        $ide->handle('file.create', ['path' => 'assets/sprites/hero.png', 'base64' => base64_encode((string)$png)]);
        self::assertSame($png, file_get_contents("$this->dir/assets/sprites/hero.png"));
        $ide->handle('file.write', ['path' => 'assets/sprites/hero.png', 'base64' => base64_encode("\x89PNG\0\x01")]);
        self::assertSame("\x89PNG\0\x01", file_get_contents("$this->dir/assets/sprites/hero.png"));
        self::assertStringStartsWith('data:image/png;base64,', $ide->handle('file.read', ['path' => 'assets/sprites/hero.png'])['image']);
    }

    public function testNewSoundsAndTunesStartAsTheEditorsSettings(): void
    {
        $ws = new MemoryWorkspace([]);
        $sfx = json_decode(Templates::forFile('assets/sfx/jump.sfx.json', $ws), true);
        self::assertSame(['square', 300, 900], [$sfx['wave'], $sfx['start'], $sfx['end']]);
        $music = json_decode(Templates::forFile('assets/music/level.music.json', $ws), true);
        self::assertSame([120, 2, 4], [$music['tempo'], $music['bars'], count($music['voices'])]);
        self::assertContains([0, 72, 2], $music['voices'][0]['notes']);
    }

    public function testNewTilesetsAndLevelsStartAsTiledFiles(): void
    {
        $ws = new MemoryWorkspace([]);
        $ts = json_decode(Templates::forFile('maps/tiles.tsj', $ws), true);
        self::assertSame(['tileset', 'tiles', 16, 16, []], [$ts['type'], $ts['name'], $ts['tilewidth'], $ts['tileheight'], $ts['tiles']]);
        $text = Templates::forFile('maps/level1.tmj', $ws);
        $map = json_decode($text, true);
        self::assertSame(['map', 40, 12, false], [$map['type'], $map['width'], $map['height'], $map['infinite']]);
        self::assertSame(['background', 'collision', 'objects'], array_column($map['layers'], 'name'));
        self::assertSame(['tilelayer', 'tilelayer', 'objectgroup'], array_column($map['layers'], 'type'));
        self::assertCount(40 * 12, $map['layers'][1]['data']);
        self::assertStringContainsString('"data": [0,0,0', $text);
    }
}
