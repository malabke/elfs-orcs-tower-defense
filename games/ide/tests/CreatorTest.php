<?php

namespace Tests;

use App\Creator;
use JumPHP\Testing\AppTestCase;

/** A new project is made on this computer: the skeleton of its kind, named after it, nothing online. */
final class CreatorTest extends AppTestCase
{
    private string $root = '';

    protected function setUp(): void
    {
        putenv('JUMPHP_IDE_UPDATES=0');
        parent::setUp();
        $repo = dirname(__DIR__, 3);
        $this->root = sys_get_temp_dir() . '/jumphp-creator-' . bin2hex(random_bytes(4));
        mkdir("$this->root/bin", 0777, true);
        mkdir("$this->root/tools", 0777, true);
        copy("$repo/bin/jumphp", "$this->root/bin/jumphp");
        foreach (['new-game.php', 'new-gui.php', 'new-app.php', 'new-extension.php'] as $f) {
            copy("$repo/tools/$f", "$this->root/tools/$f");
        }
        foreach (['skeleton', 'skeleton-gui', 'skeleton-app', 'skeleton-cli', 'skeleton-extension'] as $dir) {
            exec('cp -r ' . escapeshellarg("$repo/$dir") . ' ' . escapeshellarg("$this->root/$dir"));
        }
        mkdir("$this->root/games/example", 0777, true);
        mkdir("$this->root/.github/workflows", 0777, true);
    }

    protected function tearDown(): void
    {
        exec('rm -rf ' . escapeshellarg($this->root));
        putenv('JUMPHP_IDE_UPDATES');
        parent::tearDown();
    }

    private function scaffold(string $kind, string $id, string $title): string
    {
        $creator = (new \ReflectionClass(Creator::class))->newInstanceWithoutConstructor();
        $method = new \ReflectionMethod(Creator::class, 'scaffold');
        return $method->invoke($creator, $this->root, $kind, $id, $title, 'A test project.', 'The brief.');
    }

    public function testAGameIsTheSkeletonNamedAfterTheProject(): void
    {
        $cwd = getcwd();
        $dir = $this->scaffold('original', 'star-hopper', 'Star "Hopper"');
        self::assertSame('games/star-hopper', $dir);
        self::assertSame($cwd, getcwd(), 'the IDE keeps its working folder');
        $yaml = (string)file_get_contents("$this->root/$dir/config/project.yaml");
        self::assertStringContainsString('id: star-hopper', $yaml);
        self::assertStringContainsString('description: "A test project."', $yaml);
        self::assertFileExists("$this->root/$dir/src/Scene/PlayScene.php");
        self::assertStringContainsString('The brief.', (string)file_get_contents("$this->root/$dir/BRIEF.md"));
        self::assertDirectoryDoesNotExist("$this->root/games/example", 'JumPHP\'s examples are not in a project');
        self::assertDirectoryDoesNotExist("$this->root/.github/workflows");
    }

    public function testAnExtensionIsAPackageAndItsExampleGame(): void
    {
        $creator = (new \ReflectionClass(Creator::class))->newInstanceWithoutConstructor();
        $copy = new \ReflectionMethod(Creator::class, 'copyTree');
        $run = new \ReflectionMethod(Creator::class, 'runScript');
        $copy->invoke(null, "$this->root/skeleton-extension/package", "$this->root/extensions/wind-effects");
        $copy->invoke(null, "$this->root/skeleton-extension/example", "$this->root/games/wind-effects-demo");
        $run->invoke(null, "$this->root/tools/new-extension.php", $this->root, ['wind-effects', 'Wind Effects']);
        $composer = json_decode((string)file_get_contents("$this->root/extensions/wind-effects/composer.json"), true);
        self::assertSame('jumphp-extension', $composer['type']);
        self::assertSame('jumphp-extensions/wind-effects', $composer['name']);
        self::assertFileExists("$this->root/extensions/wind-effects/src/WindEffects.php");
        self::assertFileExists("$this->root/games/wind-effects-demo/composer.json");
        self::assertNotNull($creator);
    }

    public function testAnApplicationGoesToAppsWhenThisJumphpKnowsThatFolder(): void
    {
        $dir = $this->scaffold('gui', 'contacts', 'Contacts');
        self::assertSame('apps/contacts', $dir);
        self::assertFileExists("$this->root/$dir/forms/MainForm.form.yaml");
        $mobile = $this->scaffold('mobile', 'phone-notes', 'Phone Notes');
        self::assertStringContainsString('width: 390', (string)file_get_contents("$this->root/$mobile/config/project.yaml"));
        self::assertDirectoryExists("$this->root/" . $this->scaffold('cli', 'todo', 'Todo'));
    }
}
