<?php

namespace JumPHP\Ide\Tests;

use JumPHP\Ide\Extension\InstalledExtensions;
use JumPHP\Ide\Workspace\MemoryWorkspace;
use PHPUnit\Framework\TestCase;

/** The extensions a game was given (extension:add leaves extension.json, doc/ and the samples): listed, documented. */
final class InstalledExtensionsTest extends TestCase
{
    private static function workspace(): MemoryWorkspace
    {
        return new MemoryWorkspace([
            'games/mine/extensions/particles/extension.json' => json_encode(['package' => 'jumphp/particles', 'name' => 'Particles', 'version' => '0.5.8', 'doc' => 'doc/index.md',
                'samples' => [['class' => 'App\\Extensions\\Particles\\FireSample', 'file' => 'src/Extensions/Particles/FireSample.php', 'label' => 'A fire']]]),
            'games/mine/extensions/particles/doc/index.md' => "# Particles\nHello.",
            'games/other/extensions/particles/extension.json' => json_encode(['package' => 'jumphp/particles', 'name' => 'Particles', 'doc' => null]),
            'games/mine/extensions/broken/extension.json' => 'not json',
            'games/mine/src/Scene/PlayScene.php' => '<?php',
        ], 'p');
    }

    public function testTheExtensionsOfTheGamesAreListed(): void
    {
        $list = (new InstalledExtensions(self::workspace()))->all();
        self::assertSame(['mine', 'other'], array_column($list, 'gameId'));
        self::assertSame(['games/mine', 'games/other'], array_column($list, 'game'));
        self::assertSame('A fire', $list[0]['samples'][0]['label']);
        self::assertSame('games/mine/src/Extensions/Particles/FireSample.php', $list[0]['samples'][0]['file'], 'a path of the project, to open it in the editor');
        self::assertTrue($list[0]['hasDoc']);
        self::assertFalse($list[1]['hasDoc']);
    }

    public function testTheDocumentationComesWithWhatToPlay(): void
    {
        $docs = (new InstalledExtensions(self::workspace()))->docs('jumphp/particles', 'games/mine');
        self::assertSame("# Particles\nHello.", $docs['markdown']);
        self::assertSame('App\\Extensions\\Particles\\FireSample', $docs['samples'][0]['class']);
        self::assertSame('games/mine/extensions/particles/doc/index.md', $docs['docFile']);
        self::assertNull((new InstalledExtensions(self::workspace()))->docs('acme/none'));
    }
}
