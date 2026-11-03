<?php

namespace Tests;

use App\Save\Progress;
use JumPHP\Save\Storage;
use PHPUnit\Framework\TestCase;

final class ProgressTest extends TestCase
{
    public function testTheBestStarsAreSavedAndLoadedAgain(): void
    {
        $dir = sys_get_temp_dir() . '/eotd-progress-' . getmypid();
        @mkdir($dir, 0777, true);
        @unlink($dir . '/' . Progress::FILE);
        $storage = new Storage($dir);

        $progress = (new Progress())->load($storage);
        self::assertSame(0, $progress->stars('elves', 'thornvale'));
        $progress->record('elves', 'thornvale', 2);
        $progress->record('elves', 'thornvale', 1);   // a worse result does not replace it

        $again = (new Progress())->load(new Storage($dir));
        self::assertSame(2, $again->stars('elves', 'thornvale'));
        self::assertSame(2, $again->totalStars());
        $again->record('elves', 'thornvale', 3);
        self::assertSame(3, (new Progress())->load(new Storage($dir))->stars('elves', 'thornvale'));
        @unlink($dir . '/' . Progress::FILE);
    }
}
