<?php

namespace Tests;

use JumPHP\Effects\Ease;
use JumPHP\Effects\Transition;
use JumPHP\Effects\Vignette;
use PHPUnit\Framework\TestCase;

/** The extension's maths, with no window: what covers the screen, when, and the curves. */
final class TransitionTest extends TestCase
{
    private static function covered(array $shapes, int $w, int $h): int
    {
        $area = 0;
        foreach ($shapes as [, , $sw, $sh, $alpha]) {
            $area += $sw * $sh * $alpha / 255;      // a translucent shape covers less
        }
        return (int)round(100 * $area / ($w * $h));
    }

    public function testEveryTransitionGoesFromNothingToEverything(): void
    {
        foreach (Transition::TYPES as $type) {
            $t = new Transition();
            $t->cover($type, 1.0);
            self::assertSame([], $t->shapes(320, 180), "$type: nothing at the start");
            $t->update(0.5);
            $half = self::covered($t->shapes(320, 180), 320, 180);
            self::assertGreaterThan(5, $half, "$type: something at half");
            self::assertLessThan(95, $half, "$type: not everything at half");
            $t->update(0.6);
            self::assertSame([[0, 0, 320, 180, 255]], $t->shapes(320, 180), "$type: everything at the end");
            self::assertTrue($t->isCovered());
        }
    }

    public function testRevealingEndsWithNothingAndTheCallbackRunsOnce(): void
    {
        $t = new Transition();
        $calls = 0;
        $t->cover('wipe-left', 0.2, '#000000', function () use (&$calls, $t): void {
            $calls++;
            $t->reveal('wipe-left', 0.2, '#000000', function () use (&$calls): void { $calls += 10; });
        });
        for ($i = 0; $i < 60; $i++) {
            $t->update(0.02);
        }
        self::assertSame(11, $calls, 'covered once, revealed once');
        self::assertFalse($t->isActive());
        self::assertSame([], $t->shapes(320, 180));
    }

    public function testACoverStaysUntilItIsRevealed(): void
    {
        $t = new Transition();
        $t->cover('fade', 0.1);
        $t->update(5);
        $t->update(5);
        self::assertTrue($t->isCovered());
        self::assertTrue($t->isActive());
    }

    public function testTheIrisHoleClosesOnItsFocus(): void
    {
        $t = new Transition();
        $t->focus(0.5, 0.5);
        $t->cover('iris', 1.0);
        $t->update(0.5);
        $shapes = $t->shapes(320, 180);
        // the row through the focus: the hole is around x = 160, so the left rectangle ends before it and the right one starts after it
        $middle = array_values(array_filter($shapes, fn ($s) => $s[1] <= 90 && $s[1] + $s[3] > 90));
        self::assertCount(2, $middle);
        self::assertLessThan(160, $middle[0][0] + $middle[0][2]);
        self::assertGreaterThan(160, $middle[1][0]);
        // the hole is smaller later
        $t->update(0.3);
        $later = array_values(array_filter($t->shapes(320, 180), fn ($s) => $s[1] <= 90 && $s[1] + $s[3] > 90));
        self::assertGreaterThan($middle[0][0] + $middle[0][2], $later[0][0] + $later[0][2], 'the left rectangle reaches further');
    }

    public function testTheDissolveIsTheSameForTheSameSeed(): void
    {
        $shapes = function (int $seed): array {
            $t = new Transition();
            $t->seed($seed);
            $t->cover('dissolve', 1.0);
            $t->update(0.5);
            return $t->shapes(320, 180);
        };
        self::assertSame($shapes(5), $shapes(5));
        self::assertNotSame($shapes(5), $shapes(6));
    }

    public function testAnUnknownTransitionSaysWhichExist(): void
    {
        $this->expectExceptionMessage('fade, wipe-left');
        (new Transition())->cover('spiral');
    }

    public function testTheCurvesStartAtZeroAndEndAtOne(): void
    {
        foreach (Ease::NAMES as $name) {
            $f = Ease::get($name);
            self::assertEqualsWithDelta(0.0, $f(0.0), 1e-9, $name);
            self::assertEqualsWithDelta(1.0, $f(1.0), 1e-9, $name);
        }
        self::assertGreaterThan(1.0, Ease::outBack(0.8), 'back goes past the end');
        self::assertEqualsWithDelta(0.5, Ease::inOutQuad(0.5), 1e-9);
        self::assertSame(15.0, Ease::lerp(10, 20, 0.5));
    }

    public function testTheVignetteIsDarkestAtTheEdge(): void
    {
        $shapes = Vignette::shapes(320, 180, 0.8, 6);
        self::assertNotEmpty($shapes);
        self::assertGreaterThan($shapes[count($shapes) - 1][4], $shapes[0][4]);
        self::assertSame([], Vignette::shapes(320, 180, 0.0));
    }
}
