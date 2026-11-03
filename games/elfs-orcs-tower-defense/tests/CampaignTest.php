<?php

namespace Tests;

use App\Save\Progress;
use App\Td\Battle;
use App\Td\Bot;
use App\Td\GameData;
use App\Td\Path;
use JumPHP\Save\Storage;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Milestone 3: six maps, their waves, hard mode, the heroes, the sides' powers, the campaign's progress.
 */
final class CampaignTest extends TestCase
{
    private static function data(): GameData
    {
        return new GameData(dirname(__DIR__) . '/assets/data');
    }

    public static function maps(): array
    {
        return array_map(static fn (string $m) => [$m], self::data()->mapIds());
    }

    public static function mapsAndSides(): array
    {
        $cases = [];
        foreach (self::data()->mapIds() as $map) {
            foreach (self::data()->factionIds() as $side) {
                $cases["$map $side"] = [$map, $side];
            }
        }
        return $cases;
    }

    public function testThereAreSixMaps(): void
    {
        self::assertCount(6, self::data()->mapIds());
        self::assertSame('thornvale', self::data()->mapIds()[0]);
    }

    #[DataProvider('maps')]
    public function testEveryPlatformIsOffTheRoadAndInsideTheMap(string $id): void
    {
        $map = self::data()->map($id);
        $paths = array_map(static fn (array $p) => Path::fromTiles($p), $map['paths']);
        $taken = [];
        [$bx, $by] = $map['bastion'];
        foreach ($map['slots'] as [$tx, $ty]) {
            self::assertTrue($tx >= 0 && $ty >= 0 && $tx + 1 < GameData::COLS && $ty + 1 < GameData::ROWS, "$id slot $tx,$ty outside");
            $cx = ($tx + 1) * GameData::TILE;
            $cy = ($ty + 1) * GameData::TILE;
            foreach ($paths as $path) {
                self::assertGreaterThanOrEqual(30, $path->distanceTo($cx, $cy), "$id slot $tx,$ty on the road");
            }
            for ($dy = 0; $dy < 2; $dy++) {
                for ($dx = 0; $dx < 2; $dx++) {
                    $key = ($tx + $dx) . ',' . ($ty + $dy);
                    self::assertArrayNotHasKey($key, $taken, "$id slots overlap at $key");
                    $taken[$key] = true;
                    $inCastle = $tx + $dx >= $bx && $tx + $dx < $bx + 3 && $ty + $dy >= $by && $ty + $dy < $by + 6;
                    self::assertFalse($inCastle, "$id slot $tx,$ty on the castle");
                }
            }
        }
        // every road ends at the castle's gate
        foreach ($map['paths'] as $points) {
            $end = $points[count($points) - 1];
            self::assertSame([$bx + 1, $by + 3], $end, "$id: the road does not end at the gate");
        }
    }

    public function testLaterMapsTakeTheFirstMapsWavesStrongerWithTheirOwnGroups(): void
    {
        $data = self::data();
        $first = $data->wavesFor('thornvale', 'horde');
        $marsh = $data->wavesFor('mistfen', 'horde');
        self::assertCount(count($first), $marsh);
        self::assertSame((int)round($first[0]['groups'][0]['count'] * 1.1), $marsh[0]['groups'][0]['count']);
        self::assertEqualsWithDelta(1.1, $marsh[0]['groups'][0]['hp'], 0.001);
        self::assertCount(count($first[2]['groups']) + 1, $marsh[2]['groups'], 'the marsh adds bats to wave 3');
        foreach ($data->mapIds() as $map) {
            foreach (['horde', 'alliance'] as $side) {
                self::assertNotEmpty($data->wavesFor($map, $side));
            }
        }
    }

    #[DataProvider('mapsAndSides')]
    public function testTheBotWinsEveryMapWithEverySide(string $map, string $side): void
    {
        $battle = new Battle(self::data(), $map, $side);
        $bot = new Bot($battle, false, true);
        for ($t = 0; $t < 60 * 60 * 14 && !$battle->isOver(); $t++) {
            if ($t % 30 === 0) {
                $bot->play();
            }
            $battle->step();
            $battle->events = [];
        }
        self::assertSame('won', $battle->state, "$side lost $map at wave {$battle->waveIndex}");
    }

    public function testHardModeMeansTougherEnemiesAndLessGold(): void
    {
        $normal = new Battle(self::data(), 'thornvale', 'elves');
        $hard = new Battle(self::data(), 'thornvale', 'elves', 1, true);
        self::assertLessThan($normal->gold, $hard->gold);
        $normal->callNextWave();
        $hard->callNextWave();
        $normal->run(0.1);
        $hard->run(0.1);
        self::assertEqualsWithDelta($normal->enemies[0]->maxHp * 1.5, $hard->enemies[0]->maxHp, 0.01);
    }

    public function testTheHeroGoesWhereItIsSentAndStopsAnEnemy(): void
    {
        $battle = new Battle(self::data(), 'thornvale', 'dwarves');
        $battle->countdown = 9999.0;
        $hero = $battle->hero;
        self::assertNotNull($hero);
        // sent near the start of the road
        self::assertTrue($battle->moveHero(40, 40));
        $battle->run(8.0);
        self::assertLessThan(2.0, $battle->paths[0]->distanceTo($hero->x, $hero->y), 'on the road');
        self::assertLessThan(40.0, hypot($hero->x - 40, $hero->y - 48));
        $grunt = $battle->spawnEnemy('grunt');
        $battle->run(3.0);
        self::assertTrue($grunt->dead || $grunt->isHeld(), 'the hero holds the grunt');
        $battle->run(4.0);
        self::assertTrue($grunt->dead);
    }

    public function testTheHerosSkillFiresWhenEnemiesCrowdAroundAndThenWaits(): void
    {
        $battle = new Battle(self::data(), 'thornvale', 'orcs');
        $battle->countdown = 9999.0;
        $hero = $battle->hero;
        $battle->moveHero(40, 40);
        $battle->run(8.0);
        for ($i = 0; $i < 4; $i++) {
            $battle->spawnEnemy('barefoot_band');
        }
        $skills = 0;
        for ($t = 0; $t < 60 * 4; $t++) {
            $battle->step();
            $skills += count(array_filter($battle->events, static fn (array $e) => $e['type'] === 'hero_skill'));
            $battle->events = [];
        }
        self::assertSame(1, $skills);
        self::assertGreaterThan(0, $hero->skillTimer);
    }

    public function testAFallenHeroComesBack(): void
    {
        $battle = new Battle(self::data(), 'thornvale', 'littlefolk');
        $battle->countdown = 9999.0;
        $hero = $battle->hero;
        $hero->hp = 0.0;
        $hero->respawn = 1.0;
        $battle->run(1.2);
        self::assertTrue($hero->isAlive());
        self::assertSame($hero->maxHp, $hero->hp);
    }

    public function testThePowerHitsWhereItIsCastThenWaits(): void
    {
        $battle = new Battle(self::data(), 'thornvale', 'elves');
        $battle->countdown = 9999.0;
        $battle->hero = null;
        $near = $battle->spawnEnemy('iron_orc');
        $far = $battle->spawnEnemy('iron_orc');
        $far->distance = 300;
        $battle->run(1 / 60);
        self::assertFalse($battle->castPower($near->x, $near->y), 'not ready in the first seconds');
        $battle->powerTimer = 0.0;
        self::assertTrue($battle->castPower($near->x, $near->y));
        self::assertLessThan($near->maxHp, $near->hp);
        self::assertSame($far->maxHp, $far->hp);
        self::assertFalse($battle->castPower($near->x, $near->y), 'it has to wait');
        $battle->run(41);
        self::assertTrue($battle->powerReady());
    }

    public function testWinningAMapOpensTheNextOneAndItsHardMode(): void
    {
        $dir = sys_get_temp_dir() . '/eotd-campaign-' . getmypid();
        @mkdir($dir, 0777, true);
        @unlink($dir . '/' . Progress::FILE);
        $maps = self::data()->mapIds();
        $progress = (new Progress())->load(new Storage($dir));
        self::assertTrue($progress->isOpen('elves', $maps, 'thornvale'));
        self::assertFalse($progress->isOpen('elves', $maps, 'mistfen'));
        $progress->record('elves', 'thornvale', 2);
        self::assertTrue($progress->isOpen('elves', $maps, 'mistfen'));
        self::assertFalse($progress->isOpen('orcs', $maps, 'mistfen'), 'each side has its own campaign');
        $progress->record('elves', 'thornvale', 1, true);
        $again = (new Progress())->load(new Storage($dir));
        self::assertSame(2, $again->stars('elves', 'thornvale'));
        self::assertSame(1, $again->stars('elves', 'thornvale', true));
        @unlink($dir . '/' . Progress::FILE);
    }
}
