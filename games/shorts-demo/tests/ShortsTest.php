<?php

namespace Tests;

use App\Scene\ShortScene;
use JumPHP\Physics2d\Ball;
use JumPHP\Physics2d\Ring;
use JumPHP\Physics2d\World;
use JumPHP\Shorts\Melody;
use JumPHP\Shorts\Mixer;
use JumPHP\Shorts\Recorder;
use JumPHP\Testing\GameTestCase;

final class ShortsTest extends GameTestCase
{
    public function testTheSameSeedGivesTheSameBounces(): void
    {
        $run = function (int $seed): array {
            $this->game->start(ShortScene::class, ['template' => 'rings', 'seed' => $seed]);
            $this->game->run(240);
            return array_map(fn (array $n) => [$n['time'], $n['freq']], $this->recorder()->notes());
        };
        $a = $run(11);
        $b = $run(11);
        self::assertNotEmpty($a);
        self::assertSame($a, $b);
        self::assertNotSame($a, $run(12));
    }

    public function testABallStaysInsideARingWithoutAGap(): void
    {
        $this->game->start(ShortScene::class, ['template' => 'growing', 'seed' => 5]);
        $world = $this->world();
        $world->reset(5);
        $ring = $world->addRing(new Ring(135, 260, 100));
        $ball = $world->addBall(new Ball(135, 200, 300, -200, 6));
        for ($i = 0; $i < 600; $i++) {
            $world->update(1 / 60);
            $d = sqrt(($ball->x - $ring->x) ** 2 + ($ball->y - $ring->y) ** 2);
            self::assertLessThanOrEqual($ring->radius - $ball->radius + 0.01, $d);
        }
        self::assertGreaterThan(5, $ring->hits);
    }

    public function testABallEscapesThroughTheGapsAndTheRingsGoAway(): void
    {
        $this->game->start(ShortScene::class, ['template' => 'rings', 'seed' => 3]);
        $solid = fn () => count(array_filter($this->world()->rings(), fn (Ring $r) => $r->solid));
        self::assertSame(7, $solid());
        $this->game->run(600);
        self::assertLessThan(7, $solid());
        $this->game->shot('rings');
    }

    public function testEachBounceIsTheNextNoteOfTheMelody(): void
    {
        $this->game->start(ShortScene::class, ['template' => 'growing', 'seed' => 2, 'melody' => 'C4 E4 G4']);
        $this->game->run(300);
        $notes = $this->recorder()->notes();
        self::assertGreaterThan(3, count($notes));
        $expected = [Melody::frequency('C4'), Melody::frequency('E4'), Melody::frequency('G4')];
        foreach (array_slice($notes, 0, 6) as $i => $note) {
            self::assertEqualsWithDelta($expected[$i % 3], $note['freq'], 0.01);
        }
        self::assertGreaterThan(0, $this->world()->balls()[0]->hits);
    }

    public function testTheMixedSoundLastsTheVideoAndIsNotSilent(): void
    {
        $wav = Mixer::mix([['time' => 0.5, 'freq' => 440.0], ['time' => 1.0, 'freq' => 660.0]], 2.0);
        self::assertSame('RIFF', substr($wav, 0, 4));
        $samples = unpack('v*', substr($wav, 44));
        self::assertCount(44100, $samples);
        self::assertGreaterThan(1000, max(array_map(fn (int $s) => $s > 32767 ? 65536 - $s : $s, array_slice($samples, 11025, 2205))));
    }

    public function testInTheRaceAColourWins(): void
    {
        $this->game->start(ShortScene::class, ['template' => 'race', 'seed' => 4]);
        $this->game->run(900);
        self::assertNotEmpty($this->game->peek('wins'));
        $this->game->shot('race');
    }

    private function world(): World
    {
        return $this->game->peek('world');
    }

    private function recorder(): Recorder
    {
        return $this->game->peek('recorder');
    }
}
