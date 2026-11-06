<?php

namespace App\Extensions\Shorts;

use JumPHP\Renderer\Renderer;
use JumPHP\Scene\AbstractScene;
use JumPHP\Renderer\Captions;
use JumPHP\Shorts\Melody;
use JumPHP\Shorts\Recorder;

/**
 * Sample of jumphp/shorts: each click (Enter, Space) plays the next note of a tune (Recorder: heard, and kept for a video's mix), with a
 * timed caption (Captions). JUMPHP_SCENE='App\Extensions\Shorts\MelodySample' bin/jumphp run <game>
 */
final class MelodySample extends AbstractScene
{
    public const LABEL = 'A tune played one note a click, with a caption';

    private Melody $melody;
    private Captions $captions;
    private float $time = 0;
    private int $notes = 0;

    public function __construct(private Recorder $recorder)
    {
        $this->melody = Melody::fromString('E4 E4 F4 G4 G4 F4 E4 D4 C4 C4 D4 E4 E4 D4 D4');
    }

    public function enter(array $params = []): void
    {
        $this->time = 0;
        $this->notes = 0;
        $this->recorder->reset();
        $this->melody->rewind();
        $this->captions = (new Captions())->add('Press Enter, one note a press', 0.2, 4, 'middle', 1, '#ffd23f', 'pop')
            ->add('Ode to Joy', 4, 9, 'bottom', 1, '#ffffff', 'type');
    }

    public function update(float $dt): void
    {
        $this->time += $dt;
        $mouse = $this->engine->input->getMouse();
        if ($this->actions()->isPressed('confirm') || $mouse->isPressed(1)) {
            $this->recorder->note($this->time, $this->melody->next());
            $this->notes++;
        }
    }

    public function render(Renderer $r): void
    {
        $r->clear([12, 10, 20]);
        $this->font()->draw($r, (string)$this->notes, $this->width() / 2, $this->height() / 3, 4, [255, 255, 255], 'center');
        $this->captions->render($r, $this->font(), $this->time, $this->width(), $this->height());
    }
}
