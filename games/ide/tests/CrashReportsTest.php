<?php

namespace Tests;

use App\CrashReports;
use JumPHP\Save\Storage;
use PHPUnit\Framework\TestCase;

final class CrashReportsTest extends TestCase
{
    private static function fail2(string $secret): never
    {
        throw new \TypeError('fclose(): cannot read ' . getenv('HOME') . '/projects/x.php');
    }

    public function testACrashIsSavedWithoutTheCallsArgumentsNorTheHomeFolder(): void
    {
        $dir = sys_get_temp_dir() . '/jumphp-crash-' . bin2hex(random_bytes(4));
        $crashes = new CrashReports(new Storage($dir), '0.1.1');
        try {
            self::fail2('jpi_secret_token');
        } catch (\TypeError $e) {
            $crashes->save($e);
        }
        $pending = $crashes->pending();
        self::assertCount(1, $pending);
        $report = array_values($pending)[0];
        self::assertSame(['ide', '0.1.1', 'TypeError'], [$report['app'], $report['version'], $report['class']]);
        self::assertSame('fclose(): cannot read ~/projects/x.php', $report['message']);
        self::assertStringContainsString('Tests\CrashReportsTest::fail2()', $report['trace'][0]);
        self::assertStringNotContainsString('jpi_secret_token', json_encode($report));
        self::assertStringNotContainsString((string)getenv('HOME') . '/', json_encode($report, JSON_UNESCAPED_SLASHES));
        exec('rm -rf ' . escapeshellarg($dir));
    }
}
