<?php

namespace JumPHP\Ide\Tests;

use JumPHP\Ide\Ide;
use JumPHP\Ide\Workspace\LocalWorkspace;
use JumPHP\Ide\Workspace\MemoryWorkspace;
use PHPUnit\Framework\TestCase;

final class LocalHistoryTest extends TestCase
{
    private string $dir;

    protected function setUp(): void
    {
        $this->dir = sys_get_temp_dir() . '/jumphp-history-' . bin2hex(random_bytes(4));
        mkdir($this->dir);
        mkdir("$this->dir/src");
        file_put_contents("$this->dir/src/Game.php", "<?php // one\n");
    }

    protected function tearDown(): void
    {
        exec('rm -rf ' . escapeshellarg($this->dir));
    }

    public function testEachSaveAndEachChangeFromOutsideIsAVersionOfTheFile(): void
    {
        $ide = new Ide(new LocalWorkspace($this->dir));
        self::assertTrue($ide->handle('project.info', [])['capabilities']['localHistory']);
        self::assertSame([], $ide->handle('history.local.list', ['path' => 'src/Game.php']));

        $ide->handle('file.write', ['path' => 'src/Game.php', 'content' => "<?php // two\n"]);
        $ide->handle('file.write', ['path' => 'src/Game.php', 'content' => "<?php // two\n"]);     // the same: no new version
        // Claude (or a terminal) changes it, then the IDE opens it again
        file_put_contents("$this->dir/src/Game.php", "<?php // three, by Claude\n");
        $ide->handle('file.read', ['path' => 'src/Game.php']);
        $ide->handle('file.write', ['path' => 'src/Game.php', 'content' => "<?php // four\n"]);

        $versions = $ide->handle('history.local.list', ['path' => 'src/Game.php']);
        self::assertSame(['', 'Changed outside the IDE', '', 'Before the first save'], array_column($versions, 'label'));
        $contents = array_map(fn (array $v) => $ide->handle('history.local.read', ['path' => 'src/Game.php', 'id' => $v['id']]), $versions);
        self::assertSame(["<?php // four\n", "<?php // three, by Claude\n", "<?php // two\n", "<?php // one\n"], $contents);
        self::assertStringContainsString("\n*\n", (string)file_get_contents("$this->dir/.jumphp/history/.gitignore"));
        // the history is not a file of the project
        self::assertNotContains('.jumphp/history/.gitignore', array_column($ide->handle('files.list', [])['files'], 'path'));
    }

    public function testOnlyTheNewestVersionsAreKept(): void
    {
        $ide = new Ide(new LocalWorkspace($this->dir));
        for ($i = 0; $i < 70; $i++) {
            $ide->handle('file.write', ['path' => 'src/Game.php', 'content' => "<?php // $i\n"]);
        }
        $versions = $ide->handle('history.local.list', ['path' => 'src/Game.php']);
        self::assertCount(60, $versions);
        self::assertSame("<?php // 69\n", $ide->handle('history.local.read', ['path' => 'src/Game.php', 'id' => $versions[0]['id']]));
        self::assertCount(60 + 1, glob("$this->dir/.jumphp/history/files/*/*") ?: []);     // + index.json
    }

    public function testFilesToolsRebuildHaveNoHistory(): void
    {
        $ide = new Ide(new LocalWorkspace($this->dir));
        $ide->handle('file.write', ['path' => 'vendor/x.php', 'content' => 'a']);
        $ide->handle('file.write', ['path' => 'vendor/x.php', 'content' => 'b']);
        self::assertSame([], $ide->handle('history.local.list', ['path' => 'vendor/x.php']));
        $this->expectException(\InvalidArgumentException::class);
        $ide->handle('history.local.read', ['path' => 'src/Game.php', 'id' => '../../x']);
    }

    public function testTheWebIdeKeepsNoLocalHistory(): void
    {
        $ide = new Ide(new MemoryWorkspace(['a.php' => '<?php']));
        self::assertFalse($ide->handle('project.info', [])['capabilities']['localHistory']);
        self::assertFalse($ide->has('history.local.list'));
    }
}
