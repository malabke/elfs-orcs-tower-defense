<?php

namespace Tests;

use App\Scene\ShortScene;
use JumPHP\Renderer\Captions;
use JumPHP\Testing\GameTestCase;

final class CaptionsTest extends GameTestCase
{
    public function testACaptionShowsBetweenItsTwoTimesOnly(): void
    {
        $c = (new Captions())->add('Wait for it', 2, 5);
        self::assertSame([], $c->active(1.9));
        self::assertSame('Wait for it', $c->active(2.0)[0]['text']);
        self::assertSame('Wait for it', $c->active(4.99)[0]['text']);
        self::assertSame([], $c->active(5.0));
    }

    public function testATypedCaptionIsWrittenLetterByLetter(): void
    {
        $c = (new Captions())->add('Hello', 0, 10, 'middle', 2, '#fff', 'type');
        self::assertSame('H', $c->active(0.0)[0]['text']);
        self::assertSame('Hel', $c->active(0.09)[0]['text']);
        self::assertSame('Hello', $c->active(1.0)[0]['text']);
    }

    public function testAPopGrowsPastItsSizeThenSettles(): void
    {
        $c = (new Captions())->add('Boom', 0, 10, 'top', 2, '#fff', 'pop');
        self::assertLessThan(2.0, $c->active(0.02)[0]['scale']);
        self::assertGreaterThan(2.0, $c->active(0.13)[0]['scale']);
        self::assertSame(2.0, $c->active(0.5)[0]['scale']);
    }

    public function testABoxFadesInAndOut(): void
    {
        $c = (new Captions())->add('Fade', 0, 3, 'bottom', 2, '#fff', 'box');
        self::assertSame(0, $c->active(0.0)[0]['alpha']);
        self::assertSame(170, $c->active(1.5)[0]['alpha']);
        self::assertLessThan(170, $c->active(2.9)[0]['alpha']);
    }

    public function testASpecIsReadFromTheCommandLine(): void
    {
        self::assertSame(['text' => 'Wait for it', 'from' => 2.0, 'to' => 5.5, 'at' => 'middle', 'style' => 'pop'], Captions::parseSpec('Wait for it@2-5.5@middle@pop'));
        self::assertSame(['text' => 'Follow!', 'from' => 10.0, 'to' => 1.0E9, 'at' => 300], Captions::parseSpec('Follow!@10-@300'));
        self::assertSame(0.0, Captions::parseSpec('From the start@-3')['from']);
    }

    public function testAnUnreadableSpecSaysHowItShouldBe(): void
    {
        $this->expectExceptionMessage('TEXT@FROM-TO');
        Captions::parseSpec('no times here');
    }

    public function testALongTextIsCutIntoLinesThatFit(): void
    {
        $width = fn (string $t): int => mb_strlen($t) * 6;
        self::assertSame(['Will it', 'escape?'], Captions::wrap('Will it escape?', 50, $width));
        self::assertSame(['abcdefgh', 'ij'], Captions::wrap('abcdefghij', 50, $width), 'a word longer than a line is cut');
        self::assertSame(['one', 'two'], Captions::wrap("one\ntwo", 200, $width));
    }

    public function testTheSceneShowsTheCaptionsItWasGiven(): void
    {
        $this->game->start(ShortScene::class, ['template' => 'rings', 'seed' => 3, 'captions' => [['text' => 'Follow for more', 'from' => 0.5, 'to' => 2, 'at' => 'bottom', 'style' => 'box']]]);
        self::assertSame(1, $this->game->peek('captions')->count());
        $this->game->run(60);                       // one second in
        self::assertSame('Follow for more', $this->game->peek('captions')->active(1.0)[0]['text']);
        $this->game->shot('captions');
    }
}
