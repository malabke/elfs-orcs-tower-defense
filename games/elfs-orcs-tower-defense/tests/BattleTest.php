<?php

namespace Tests;

use App\Td\Battle;
use App\Td\Bot;
use App\Td\GameData;
use PHPUnit\Framework\TestCase;

/**
 * The rules of the battle, without SDL: what towers, enemies, waves and gold do.
 */
final class BattleTest extends TestCase
{
    private static function data(): GameData
    {
        return new GameData(dirname(__DIR__) . '/assets/data');
    }

    /** A battle where the first wave never comes by itself, without the hero (it would stop the enemies). */
    private static function quietBattle(int $gold = 1000): Battle
    {
        $battle = new Battle(self::data(), 'thornvale', 'elves');
        $battle->countdown = 9999.0;
        $battle->gold = $gold;
        $battle->hero = null;
        return $battle;
    }

    /** The slot nearest to a point of the road. */
    private static function slotNear(Battle $battle, float $x, float $y): int
    {
        $best = 0;
        foreach ($battle->slots as $i => $slot) {
            if (($slot['x'] - $x) ** 2 + ($slot['y'] - $y) ** 2 < ($battle->slots[$best]['x'] - $x) ** 2 + ($battle->slots[$best]['y'] - $y) ** 2) {
                $best = $i;
            }
        }
        return $best;
    }

    public function testAnEnemyFollowsThePathAndTakesALifeAtTheBastion(): void
    {
        $battle = self::quietBattle();
        $enemy = $battle->spawnEnemy('goblin_runner');
        $path = $battle->paths[0];
        $battle->run(3.0);
        // still on the road, 3 s further
        self::assertEqualsWithDelta(48 * 3, $enemy->distance, 1.0);
        self::assertLessThan(1.0, $path->distanceTo($enemy->x, $enemy->y));
        $battle->run($path->length / 48);
        self::assertTrue($enemy->escaped);
        self::assertSame($battle->maxLives - 1, $battle->lives);
        self::assertSame([], $battle->enemies);
    }

    public function testATowerKillsAnEnemyInRangeAndGoldGoesUp(): void
    {
        $battle = self::quietBattle(200);
        $slot = self::slotNear($battle, 96, 112);
        self::assertTrue($battle->build($slot, 'elf_archer'));
        self::assertSame(200 - 70, $battle->gold);
        $grunt = $battle->spawnEnemy('grunt');
        $battle->run(20.0);
        self::assertTrue($grunt->dead, 'the archers kill the grunt');
        self::assertSame(200 - 70 + 4, $battle->gold, 'the grunt gives 4 gold');
        self::assertSame(1, $battle->kills);
    }

    public function testArmorResistsPhysicalDamageButNotPiercing(): void
    {
        $battle = self::quietBattle();
        $orc = $battle->spawnEnemy('iron_orc');
        $grunt = $battle->spawnEnemy('grunt');
        self::assertEqualsWithDelta(0.35, $battle->damageFactor($orc, 'physical'), 0.001);
        self::assertEqualsWithDelta(1.0, $battle->damageFactor($orc, 'pierce'), 0.001);
        self::assertEqualsWithDelta(1.0, $battle->damageFactor($grunt, 'physical'), 0.001);
        // a shred effect takes part of the armor away
        $orc->applyEffects(['shred' => ['amount' => 0.6, 'time' => 4.0]], static fn () => 0.0);
        self::assertEqualsWithDelta(0.35 + 0.65 * 0.6, $battle->damageFactor($orc, 'physical'), 0.001);
    }

    public function testSlowAndBurnActForTheirTimeThenStop(): void
    {
        $battle = self::quietBattle();
        $grunt = $battle->spawnEnemy('grunt');
        $grunt->applyEffects(['slow' => ['factor' => 0.5, 'time' => 1.0], 'burn' => ['dps' => 6, 'time' => 2.0]], static fn () => 0.0);
        $battle->run(0.5);
        self::assertTrue($grunt->isSlowed());
        self::assertEqualsWithDelta(13 * 0.5, $grunt->distance, 0.5, 'half speed while slowed');
        $battle->run(1.0);
        self::assertFalse($grunt->isSlowed());
        self::assertEqualsWithDelta(26.0, $grunt->currentSpeed(), 0.001);
        self::assertTrue($grunt->isBurning());
        $battle->run(1.0);
        self::assertFalse($grunt->isBurning());
        $hp = $grunt->hp;
        self::assertEqualsWithDelta(34 - 12, $hp, 0.5, '6 fire damage per second for 2 s');
        $battle->run(1.0);
        self::assertSame($hp, $grunt->hp, 'no more burning');
    }

    public function testAGroundOnlyTowerDoesNotHitFlyingEnemies(): void
    {
        $battle = self::quietBattle();
        $slot = self::slotNear($battle, 0, 48);
        $battle->build($slot, 'elf_sentinel');
        $bat = $battle->spawnEnemy('giant_bat');
        $battle->run(4.0);
        self::assertSame($bat->maxHp, $bat->hp);
        self::assertFalse($battle->tower($slot)->canHit($bat));
        // the archers do
        $battle->sell($slot);
        $battle->build($slot, 'elf_archer');
        self::assertTrue($battle->tower($slot)->canHit($bat));
    }

    public function testUpgradeSpecializeAndSell(): void
    {
        $battle = self::quietBattle(1000);
        $battle->build(0, 'elf_archer');
        $tower = $battle->tower(0);
        $range = $tower->range();
        self::assertTrue($battle->upgrade(0));
        self::assertTrue($battle->upgrade(0));
        self::assertSame(3, $tower->level);
        self::assertGreaterThan($range, $tower->range());
        self::assertFalse($battle->upgrade(0), 'level 3 is the top of the plain levels');
        self::assertTrue($tower->canSpecialize());
        self::assertSame(['keen_eye', 'storm_of_shafts'], $tower->specNames());
        self::assertTrue($battle->specialize(0, 'storm_of_shafts'));
        self::assertSame(3, $tower->stats['multishot']);
        self::assertFalse($tower->canSpecialize());
        $spent = 70 + 70 + 110 + 200;
        self::assertSame(1000 - $spent, $battle->gold);
        self::assertSame((int)floor($spent * 0.7), $battle->sell(0));
        self::assertNull($battle->tower(0));
        self::assertSame(1000 - $spent + (int)floor($spent * 0.7), $battle->gold);
    }

    public function testATowerCannotBeBuiltWithoutTheGold(): void
    {
        $battle = self::quietBattle(60);
        self::assertFalse($battle->build(0, 'elf_archer'));
        self::assertNull($battle->tower(0));
        self::assertSame(60, $battle->gold);
    }

    public function testTargetingPicksTheFirstTheLastTheStrongestOrTheClosest(): void
    {
        $battle = self::quietBattle();
        $slot = self::slotNear($battle, 96, 112);
        $battle->build($slot, 'elf_archer');
        $tower = $battle->tower($slot);
        $a = $battle->spawnEnemy('grunt');
        $b = $battle->spawnEnemy('iron_orc');
        $a->distance = 150.0;
        $b->distance = 110.0;
        $battle->run(1 / 60);
        self::assertSame($a, $tower->pickTargets($battle->enemies)[0]);
        self::assertSame('last', $tower->cycleTargeting());
        self::assertSame($b, $tower->pickTargets($battle->enemies)[0]);
        self::assertSame('strongest', $tower->cycleTargeting());
        self::assertSame($b, $tower->pickTargets($battle->enemies)[0]);
        self::assertSame('closest', $tower->cycleTargeting());
        self::assertSame('first', $tower->cycleTargeting());
    }

    public function testCallingTheWaveEarlyGivesTheBonus(): void
    {
        $battle = new Battle(self::data(), 'thornvale', 'elves');
        $gold = $battle->gold;
        $battle->run(10.0);
        // 30 s before the first wave, 10 s gone: 20 s left x 1.5 gold
        self::assertSame(30, $battle->earlyBonus());
        self::assertSame(30, $battle->callNextWave());
        self::assertSame($gold + 30, $battle->gold);
        self::assertSame(1, $battle->waveIndex);
        self::assertFalse($battle->canCallWave(), 'not while the wave is arriving');
        $battle->run(15.0);
        self::assertTrue($battle->canCallWave(), 'the next one can be called once the wave has arrived');
    }

    public function testAClearedWaveGivesItsBonus(): void
    {
        $battle = self::quietBattle(5000);
        $bot = new Bot($battle);
        for ($i = 0; $i < 6; $i++) {
            $bot->play();
        }
        $battle->callNextWave();
        $gold = $battle->gold;
        $battle->run(10.0);
        $battle->countdown = 9999.0;   // the whole first wave has arrived: no second one
        $battle->run(32.0);
        $kills = array_filter($battle->events, static fn (array $e) => $e['type'] === 'kill');
        $cleared = array_values(array_filter($battle->events, static fn (array $e) => $e['type'] === 'wave_cleared'));
        self::assertCount(8, $kills);
        self::assertSame(1, $cleared[0]['wave'] ?? null);
        self::assertSame($gold + 8 * 4 + 20, $battle->gold);
    }

    public function testTheBotWinsTheFirstMap(): void
    {
        $battle = new Battle(self::data(), 'thornvale', 'elves');
        $bot = new Bot($battle);
        for ($t = 0; $t < 60 * 60 * 12 && !$battle->isOver(); $t++) {
            if ($t % 30 === 0) {
                $bot->play();
            }
            $battle->step();
            $battle->events = [];
        }
        self::assertSame('won', $battle->state, "lost at wave {$battle->waveIndex}");
        self::assertGreaterThan(0, $battle->stars());
    }

    public function testTheMapIsLostWithoutAnyTower(): void
    {
        $battle = new Battle(self::data(), 'thornvale', 'elves');
        $battle->run(60 * 12);
        self::assertSame('lost', $battle->state);
        self::assertSame(0, $battle->lives);
        self::assertSame(0, $battle->stars());
    }

    public function testTheHordeAttacksTheElves(): void
    {
        $battle = new Battle(self::data(), 'thornvale', 'elves');
        self::assertSame('horde', $battle->enemySide);
        $battle->callNextWave();
        $battle->run(2.0);
        foreach ($battle->enemies as $enemy) {
            self::assertSame('horde', $enemy->def['side']);
        }
        self::assertNotEmpty($battle->enemies);
    }

    public function testFlyingEnemiesGoStraightToTheBastion(): void
    {
        $battle = self::quietBattle();
        $bat = $battle->spawnEnemy('giant_bat');
        $road = $battle->paths[0];
        self::assertCount(2, $bat->path->points);
        self::assertLessThan($road->length, $bat->path->length);
        self::assertSame($road->points[count($road->points) - 1], $bat->path->points[1]);
    }

    public function testTheSameSeedPlaysTheSameGame(): void
    {
        $play = static function (): array {
            $battle = new Battle(self::data(), 'thornvale', 'elves', 42);
            $bot = new Bot($battle);
            for ($t = 0; $t < 60 * 90; $t++) {
                $t % 30 === 0 && $bot->play();
                $battle->step();
            }
            return [$battle->gold, $battle->lives, $battle->kills];
        };
        self::assertSame($play(), $play());
    }
}
