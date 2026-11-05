<?php

namespace Tests;

use App\SettingsBackup;
use JumPHP\Save\Storage;
use PHPUnit\Framework\TestCase;

/** The settings survive a reinstall: a copy in the home folder puts back what the data folder lacks. */
final class SettingsBackupTest extends TestCase
{
    private string $root;

    protected function setUp(): void
    {
        $this->root = sys_get_temp_dir() . '/jumphp-backup-' . bin2hex(random_bytes(4));
        mkdir($this->root);
    }

    protected function tearDown(): void
    {
        exec('rm -rf ' . escapeshellarg($this->root));
    }

    public function testASignedInSessionComesBackAfterTheDataFolderIsLost(): void
    {
        $old = Storage::forRoot("$this->root/old");
        $old->writeJson('session.json', ['token' => 'jpi_x', 'user' => ['login' => 'ada']]);
        $old->writeJson('options.json', ['theme' => 'dark']);
        $old->writeJson('accounts.json', []);                       // nothing in it: not copied
        self::assertSame(2, SettingsBackup::backup($old, "$this->root/copy"));
        self::assertSame(0, SettingsBackup::backup($old, "$this->root/copy"), 'nothing changed: nothing written');
        self::assertFileDoesNotExist("$this->root/copy/accounts.json");
        if (PHP_OS_FAMILY !== 'Windows') {
            self::assertSame('0600', substr(sprintf('%o', fileperms("$this->root/copy/session.json")), -4));
        }

        // the reinstall: a new, empty data folder
        $new = Storage::forRoot("$this->root/new");
        self::assertEqualsCanonicalizing(['session.json', 'options.json'], SettingsBackup::restore($new, "$this->root/copy"));
        self::assertSame('ada', $new->readJson('session.json', [])['user']['login']);
        self::assertSame(['theme' => 'dark'], $new->readJson('options.json', []));
    }

    public function testASettingThatIsThereIsNeverOverwrittenByTheCopy(): void
    {
        $a = Storage::forRoot("$this->root/a");
        $a->writeJson('options.json', ['theme' => 'dark']);
        SettingsBackup::backup($a, "$this->root/copy");
        $b = Storage::forRoot("$this->root/b");
        $b->writeJson('options.json', ['theme' => 'light']);
        self::assertSame([], SettingsBackup::restore($b, "$this->root/copy"));
        self::assertSame(['theme' => 'light'], $b->readJson('options.json', []));
    }

    public function testNoCopyFolderMeansNothingToRestore(): void
    {
        self::assertSame([], SettingsBackup::restore(Storage::forRoot("$this->root/x"), "$this->root/none"));
        putenv('JUMPHP_IDE_BACKUP=');
        self::assertNull(SettingsBackup::folder());
        putenv('JUMPHP_IDE_BACKUP');
    }
}
