<?php

namespace Tests;

use App\Scene\DemoScene;
use JumPHP\Dialogue\QuestLog;
use JumPHP\Testing\GameTestCase;

final class TalkTest extends GameTestCase
{
    public function testTheEngineFindsTheExtensionAndItsFiles(): void
    {
        $this->game->start(DemoScene::class);
        self::assertTrue($this->game->engine->extensions()->has('jumphp/dialogue'));
        self::assertTrue($this->game->peek('runner')->has('elder'), 'the .dialogue.json was read by its loader');
    }

    public function testTalkingToTheElderTakesTheQuestAndPlayingItThroughEndsItWithAReward(): void
    {
        $this->game->start(DemoScene::class);
        $scene = $this->game->scene();
        $runner = $this->game->peek('runner');
        $quests = $this->game->peek('quests');
        self::assertTrue($scene->nearElder());
        $this->game->press('confirm');                       // talk
        self::assertTrue($runner->isActive());
        $this->game->run(90);                                // the letters are written
        $this->game->shot('hello');
        for ($i = 0; $i < 2; $i++) {                         // two texts: each confirm shows it, the next goes on
            $this->game->press('confirm');
            $this->game->press('confirm');
            $this->game->run(60);
        }
        self::assertSame('ask', $runner->nodeName());
        $this->game->press('confirm');                       // "Of course"
        self::assertSame(QuestLog::ACTIVE, $quests->status('rescue'));
        $this->game->shot('quest');
        $this->game->run(90);                                // "Thank you!" is written
        $this->game->press('confirm');                       // and the conversation ends
        self::assertFalse($runner->isActive());
        // the key and the coins: walk the player over them
        foreach ([[60.0, 150.0], [250.0, 40.0], [270.0, 150.0], [30.0, 30.0]] as [$x, $y]) {
            [$scene->px, $scene->py] = [$x, $y];
            $this->game->run(2);
        }
        self::assertSame(QuestLog::DONE, $quests->status('rescue'));
        [$scene->px, $scene->py] = [150.0, 110.0];
        $this->game->run(2);
        $this->game->press('confirm');                       // talk again: "thanks", and the reward
        self::assertSame(1, $scene->rewards);
    }
}
