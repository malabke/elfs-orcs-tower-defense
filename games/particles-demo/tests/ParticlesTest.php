<?php

namespace Tests;

use App\Scene\ShowScene;
use JumPHP\Particles\Emitter;
use JumPHP\Particles\ParticleSystem;
use JumPHP\Testing\GameTestCase;

final class ParticlesTest extends GameTestCase
{
    public function testTheExtensionIsFoundAndItsConfigIsRead(): void
    {
        $this->game->start(ShowScene::class);
        self::assertTrue($this->game->engine->extensions()->has('jumphp/particles'));
        self::assertSame(3000, $this->particles()->max);          // project.yaml particles.max over the extension's 4000
    }

    public function testTheEmittersFillTheSceneWithParticles(): void
    {
        $this->game->start(ShowScene::class);
        self::assertSame(5, count($this->particles()->emitters()));
        $this->game->run(60);
        self::assertGreaterThan(100, $this->particles()->count());
        $this->game->shot('show');
    }

    public function testAFireworkBurstsWhereTheCursorIsThenFades(): void
    {
        $this->game->start(ShowScene::class);
        $fx = $this->game->peek('fx');
        $before = $this->particles()->countOf($fx['firework']);
        $this->game->press('boom');
        self::assertSame($before + 70, $this->particles()->countOf($fx['firework']));
        $this->game->run(30);
        self::assertGreaterThan(0, $this->particles()->countOf($fx['firework']));
        $this->game->run(90);                                       // they live 1.3 s at most
        self::assertSame(0, $this->particles()->countOf($fx['firework']));
    }

    public function testParticlesMoveFallAndDie(): void
    {
        $system = new ParticleSystem();
        $drop = $system->add(new Emitter(['x' => 100, 'y' => 50, 'rate' => 0, 'life' => [1, 1], 'speed' => [0, 0], 'gravity' => 100]));
        $system->burst($drop, 3);
        $system->update(0.5);
        foreach ($system->particles() as $p) {
            self::assertEqualsWithDelta(50 + 50 * 0.5, $p['y'], 0.01);      // vy = 50 after 0.5 s, moved 25 px
        }
        $system->update(0.6);
        self::assertSame(0, $system->count());
    }

    public function testTheSameSeedGivesTheSameParticles(): void
    {
        $run = function (int $seed): array {
            $s = new ParticleSystem();
            $s->reset($seed);
            $s->add(new Emitter(['rate' => 50, 'speed' => [10, 90], 'spread' => 180]));
            for ($i = 0; $i < 30; $i++) {
                $s->update(1 / 60);
            }
            return $s->particles();
        };
        self::assertSame($run(42), $run(42));
        self::assertNotSame($run(42), $run(43));
    }

    public function testAStoppedEmitterGivesNoMoreParticles(): void
    {
        $system = new ParticleSystem();
        $e = $system->add(new Emitter(['rate' => 60, 'life' => [0.2, 0.2], 'duration' => 0.5]));
        for ($i = 0; $i < 30; $i++) {
            $system->update(1 / 60);
        }
        self::assertGreaterThan(0, $system->count());
        for ($i = 0; $i < 30; $i++) {
            $system->update(1 / 60);                                 // the duration is over: what is left dies
        }
        self::assertSame(0, $system->count());
        self::assertFalse($e->emitting());
    }

    private function particles(): ParticleSystem
    {
        return $this->game->peek('particles');
    }
}
