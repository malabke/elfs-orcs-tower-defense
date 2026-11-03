<?php

namespace Tests;

use App\Td\Battle;
use App\Td\Bot;
use App\Td\GameData;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * The five sides (milestone 2): who attacks whom, the bot wins with each, and the mechanics of their towers
 * and of their enemies (soldiers, walls, traps, auras, gold, hidden / tunnelling / dodging / healing enemies).
 */
final class SidesTest extends TestCase
{
    private static function data(): GameData
    {
        return new GameData(dirname(__DIR__) . '/assets/data');
    }

    private static function quiet(string $faction, int $gold = 3000): Battle
    {
        $battle = new Battle(self::data(), 'thornvale', $faction);
        $battle->countdown = 9999.0;
        $battle->gold = $gold;
        $battle->hero = null;
        return $battle;
    }

    public static function sides(): array
    {
        return [['elves'], ['dwarves'], ['horsefolk'], ['littlefolk'], ['orcs']];
    }

    #[DataProvider('sides')]
    public function testEverySideHasFourTowersWithThreeLevelsAndTwoSpecializations(string $faction): void
    {
        $data = self::data();
        $towers = $data->faction($faction)['towers'];
        self::assertCount(4, $towers);
        foreach ($towers as $id) {
            $tower = $data->tower($id);
            self::assertSame($faction, $tower['faction']);
            self::assertCount(3, $tower['levels'], $id);
            self::assertCount(2, $tower['specs'], $id);
        }
    }

    #[DataProvider('sides')]
    public function testOnlyTheOrcsFaceTheAlliance(string $faction): void
    {
        $battle = new Battle(self::data(), 'thornvale', $faction);
        $expected = $faction === 'orcs' ? 'alliance' : 'horde';
        self::assertSame($expected, $battle->enemySide);
        $battle->callNextWave();
        $battle->run(4.0);
        self::assertNotEmpty($battle->enemies);
        foreach ($battle->enemies as $enemy) {
            self::assertSame($expected, $enemy->def['side']);
        }
    }

    #[DataProvider('sides')]
    public function testTheBotWinsTheFirstMapWithEverySide(string $faction): void
    {
        $battle = new Battle(self::data(), 'thornvale', $faction);
        $bot = new Bot($battle);
        for ($t = 0; $t < 60 * 60 * 12 && !$battle->isOver(); $t++) {
            if ($t % 30 === 0) {
                $bot->play();
            }
            $battle->step();
            $battle->events = [];
        }
        self::assertSame('won', $battle->state, "$faction lost at wave {$battle->waveIndex}");
    }

    #[DataProvider('sides')]
    public function testEverySideLosesWithoutAnyTower(string $faction): void
    {
        $battle = new Battle(self::data(), 'thornvale', $faction);
        $battle->run(60 * 12);
        self::assertSame('lost', $battle->state);
    }

    public function testSoldiersStopAnEnemyAndFightIt(): void
    {
        $battle = self::quiet('orcs');
        self::assertTrue($battle->build(0, 'orc_barracks'));
        $tower = $battle->tower(0);
        self::assertCount(3, $tower->soldiers);
        $rider = $battle->spawnEnemy('swift_rider');
        $battle->run(4.0);
        self::assertTrue($rider->dead || $rider->isHeld(), 'a soldier holds the rider');
        $battle->run(8.0);
        self::assertTrue($rider->dead, 'the soldiers kill it');
        self::assertFalse($rider->escaped);
    }

    public function testFlyingEnemiesAreNotStoppedBySoldiers(): void
    {
        $battle = self::quiet('orcs');
        $battle->build(0, 'orc_barracks');
        $eagle = $battle->spawnEnemy('giant_eagle');
        $battle->run(5.0);
        self::assertNull($eagle->blocker);
    }

    public function testAPalisadeStopsEnemiesUntilItBreaks(): void
    {
        $battle = self::quiet('horsefolk');
        $battle->build(0, 'horse_palisade');
        $wall = $battle->tower(0)->road;
        self::assertNotNull($wall);
        $grunt = $battle->spawnEnemy('grunt');
        $battle->run(8.0);
        self::assertEqualsWithDelta($wall['distance'], $grunt->distance, 0.5, 'the grunt waits at the wall');
        self::assertLessThan(220, $battle->tower(0)->road['hp']);
        $battle->run(40.0);
        self::assertGreaterThan($wall['distance'] + 1, $grunt->distance, 'the wall broke: the grunt walks on');
    }

    public function testATrapSnapsOnTheEnemyWalkingOnIt(): void
    {
        $battle = self::quiet('littlefolk');
        $battle->build(0, 'little_traps');
        $trap = $battle->tower(0)->road;
        $grunt = $battle->spawnEnemy('grunt');
        $battle->run(($trap['distance'] + 4) / 26);
        self::assertEqualsWithDelta(34 - 25, $grunt->hp, 0.01);
        self::assertTrue($grunt->isStunned());
        self::assertGreaterThan(0, $battle->tower(0)->road['timer'], 'it re-arms');
    }

    public function testHiddenScoutsAreOnlyShotNearTheWarDrums(): void
    {
        $battle = self::quiet('orcs');
        $battle->build(0, 'orc_pitch');
        $scout = $battle->spawnEnemy('shadow_scout');
        $battle->run(3.0);
        self::assertSame($scout->maxHp, $scout->hp, 'nobody sees it');
        $battle->build(1, 'orc_drums');
        $battle->run(4.0);
        self::assertTrue($scout->dead || $scout->hp < $scout->maxHp, 'the drums show it to the pitch pit');
    }

    public function testTunnelMinersAreOutOfReachUnderground(): void
    {
        $battle = self::quiet('orcs');
        $miner = $battle->spawnEnemy('tunnel_miner');
        $miner->distance = $miner->path->length * 0.3;
        $battle->run(1 / 60);
        self::assertTrue($miner->isUnderground());
        self::assertFalse($miner->isTargetable());
        $miner->distance = $miner->path->length * 0.7;
        $battle->run(1 / 60);
        self::assertTrue($miner->isTargetable());
    }

    public function testDodgingEnemiesAvoidSomeArrows(): void
    {
        $battle = self::quiet('elves');
        $battle->build(0, 'elf_archer');
        $battle->spawnEnemy('blade_dancer');
        $dodges = 0;
        for ($i = 0; $i < 60 * 8; $i++) {
            $battle->step();
            $dodges += count(array_filter($battle->events, static fn (array $e) => $e['type'] === 'dodge'));
            $battle->events = [];
        }
        self::assertGreaterThan(0, $dodges);
    }

    public function testRuneBearersHealTheirFriends(): void
    {
        $battle = self::quiet('orcs');
        $bearer = $battle->spawnEnemy('rune_bearer');
        $shield = $battle->spawnEnemy('iron_shield');
        $shield->hp = 50;
        $battle->run(2.1);
        self::assertGreaterThan(50, $shield->hp);
        self::assertFalse($bearer->dead);
    }

    public function testAMineGivesGoldOverTime(): void
    {
        $battle = self::quiet('dwarves', 500);
        $battle->build(0, 'dwarf_mine');
        $gold = $battle->gold;
        $battle->run(10.05);
        self::assertSame($gold + 12, $battle->gold, '6 gold every 5 seconds');
    }

    public function testTheForgeAndTheHornHelpTheirNeighbours(): void
    {
        $battle = self::quiet('dwarves');
        // slots 1 and 2 are 45 px apart
        $battle->build(1, 'dwarf_crossbow');
        $battle->build(2, 'dwarf_forge');
        $battle->step();
        self::assertEqualsWithDelta(1.2, $battle->tower(1)->buffDamage, 0.001);
        self::assertEqualsWithDelta(1.0, $battle->tower(2)->buffDamage, 0.001, 'not on itself');
        $battle = self::quiet('horsefolk');
        $battle->build(1, 'horse_javelin');
        $battle->build(2, 'horse_horn');
        $battle->step();
        self::assertEqualsWithDelta(0.85, $battle->tower(1)->buffRate, 0.001);
    }

    public function testTheHiddenGardensFirstHitOnAnEnemyIsCritical(): void
    {
        $battle = self::quiet('littlefolk');
        $battle->build(0, 'little_garden');
        $grunt = $battle->spawnEnemy('iron_orc');
        $crits = [];
        for ($i = 0; $i < 60 * 6; $i++) {
            $battle->step();
            foreach ($battle->events as $e) {
                if ($e['type'] === 'hit') {
                    $crits[] = $e['crit'];
                }
            }
            $battle->events = [];
        }
        self::assertTrue($crits[0] ?? false);
        self::assertContains(false, $crits, 'only the first one');
        self::assertFalse($grunt->escaped);
    }
}
