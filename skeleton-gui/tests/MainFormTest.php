<?php

namespace Tests;

use App\Form\MainForm;
use JumPHP\Gui\Pointer;
use JumPHP\Kernel\Engine;
use JumPHP\Testing\GameTestCase;

final class MainFormTest extends GameTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        Engine::get()->storage->delete('contacts.json');      // each test starts with no contact
    }

    public function testAContactTypedThenAddedShowsInTheList(): void
    {
        $this->game->start(MainForm::class);
        /** @var MainForm $form */
        $form = $this->game->scene();
        $form->click($form->widget('name'));
        $form->type('Grace Hopper');
        $form->click($form->widget('add'));
        $this->game->run(2);
        self::assertSame(['Grace Hopper'], $form->widget('list')->props['items']);
        self::assertSame('', $form->widget('name')->props['text'], 'the field is cleared');
        $this->game->shot('added');
    }

    public function testAnEmptyNameIsRefused(): void
    {
        $this->game->start(MainForm::class);
        /** @var MainForm $form */
        $form = $this->game->scene();
        $form->key(Pointer::RETURN);         // Return clicks the default button: Add
        self::assertSame([], $form->widget('list')->props['items']);
        self::assertSame('A name, please', $form->widget('status')->props['text']);
    }

    public function testAContactCanBeRemoved(): void
    {
        $this->game->start(MainForm::class, ['demo' => true]);
        /** @var MainForm $form */
        $form = $this->game->scene();
        $this->game->run(2);
        $form->click($form->widget('list'));
        $form->widget('list')->select(0);
        $form->click($form->widget('remove'));
        self::assertSame([], $form->widget('list')->props['items']);
    }
}
