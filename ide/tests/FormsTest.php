<?php

namespace JumPHP\Ide\Tests;

use JumPHP\Ide\Gui\FormCode;
use JumPHP\Ide\Gui\FormFile;
use JumPHP\Ide\Ide;
use JumPHP\Ide\Workspace\MemoryWorkspace;
use PHPUnit\Framework\TestCase;

final class FormsTest extends TestCase
{
    private const FORM = "# The main window.\n# Made with the visual editor.\nform:\n  title: Contacts\n  children:\n    - {type: label, id: heading, text: Contacts}\n"
        . "    - type: row\n      children:\n        - {type: button, id: save, text: Save, events: {click: onSaveClick}}\n";

    private const CLASS_SOURCE = "<?php\n\nnamespace App\\Form;\n\nuse JumPHP\\Gui\\Form;\n\n/**\n * The main window.\n *\n * @property Label \$old\n */\nfinal class MainForm extends Form\n{\n"
        . "    public function onSaveClick(Button \$sender): void\n    {\n    }\n}\n";

    public function testAFormFileIsReadAndWrittenBackTheSame(): void
    {
        $parsed = FormFile::parse(self::FORM);
        self::assertSame('Contacts', $parsed['form']['title']);
        self::assertSame('save', $parsed['form']['children'][1]['children'][0]['id']);
        self::assertSame(self::FORM, FormFile::dump($parsed['form'], $parsed['header']));
    }

    public function testALongWidgetIsWrittenAsABlock(): void
    {
        $text = FormFile::dump(['children' => [['type' => 'label', 'id' => 'long', 'text' => str_repeat('A long sentence. ', 10)]]]);
        self::assertStringContainsString("    - type: label\n      id: long\n", $text);
        self::assertSame('long', FormFile::parse($text)['form']['children'][0]['id']);
    }

    public function testADoubleClickAddsTheEventMethodWithItsArguments(): void
    {
        $r = FormCode::wire(self::CLASS_SOURCE, 'onNameChange', 'TextBox', 'change');
        self::assertTrue($r['added']);
        self::assertStringContainsString("    public function onNameChange(TextBox \$sender, string \$text): void\n    {\n        \n    }\n}\n", $r['content']);
        self::assertStringContainsString("use JumPHP\\Gui\\Form;\nuse JumPHP\\Gui\\TextBox;\n", $r['content']);
        $lines = explode("\n", $r['content']);
        self::assertSame('        ', $lines[$r['line'] - 1], 'the cursor goes into the new method');
        self::assertStringNotContainsString('Parse error', (string)shell_exec('echo ' . escapeshellarg($r['content']) . ' | php -l 2>&1'));
    }

    public function testAMethodThatExistsIsOnlyFound(): void
    {
        $r = FormCode::wire(self::CLASS_SOURCE, 'onSaveClick', 'Button', 'click');
        self::assertFalse($r['added']);
        self::assertSame(self::CLASS_SOURCE, $r['content']);
        self::assertSame(16, $r['line']);
    }

    public function testTheDocblockListsTheWidgets(): void
    {
        $code = FormCode::properties(self::CLASS_SOURCE, ['heading' => 'Label', 'save' => 'Button']);
        self::assertStringContainsString("/**\n * The main window.\n *\n * @property Label \$heading\n * @property Button \$save\n */\nfinal class MainForm", $code);
        self::assertStringNotContainsString('$old', $code);
        self::assertStringContainsString("use JumPHP\\Gui\\Button;\nuse JumPHP\\Gui\\Form;\nuse JumPHP\\Gui\\Label;\n", $code);
        self::assertSame($code, FormCode::properties($code, ['heading' => 'Label', 'save' => 'Button']), 'twice: the same');
    }

    public function testTheIdeFindsOrMakesTheClassOfAForm(): void
    {
        $ws = new MemoryWorkspace([
            'games/x/composer.json' => '{"autoload": {"psr-4": {"App\\\\": "src/"}}}',
            'games/x/forms/MainForm.form.yaml' => self::FORM,
            'games/x/src/Form/MainForm.php' => self::CLASS_SOURCE,
        ]);
        $ide = new Ide($ws);
        $r = $ide->handle('gui.code', ['path' => 'games/x/forms/MainForm.form.yaml', 'wire' => ['id' => 'heading', 'type' => 'label', 'event' => 'click']]);
        self::assertSame('games/x/src/Form/MainForm.php', $r['path']);
        self::assertStringContainsString('public function onHeadingClick(Label $sender): void', $r['content']);

        $new = $ide->handle('gui.code', ['path' => 'games/x/forms/Settings.form.yaml', 'widgets' => ['ok' => 'button']]);
        self::assertTrue($new['created']);
        self::assertSame('games/x/src/Form/Settings.php', $new['path']);
        self::assertStringContainsString("namespace App\\Form;", $new['content']);
        self::assertStringContainsString(' * @property Button $ok', $new['content']);
        self::assertStringContainsString('final class Settings extends Form', $new['content']);
    }

    public function testAGraphicAppIsKnownWithItsForms(): void
    {
        $ws = new MemoryWorkspace([
            'bin/jumphp' => '#!/bin/bash',
            'games/contacts/config/game.yaml' => "game:\n  id: contacts\n  kind: gui                     # forms\n  view: window\n",
            'games/contacts/forms/MainForm.form.yaml' => self::FORM,
        ]);
        $info = (new Ide($ws))->handle('project.info', []);
        self::assertSame('gui', $info['modules'][0]['kind']);
        self::assertSame(['games/contacts/forms/MainForm.form.yaml'], $info['modules'][0]['forms']);
        self::assertContains('run:contacts', array_column($info['run'], 'id'));
    }

    public function testANewFormFileStartsWithATitle(): void
    {
        $ide = new Ide(new MemoryWorkspace([]));
        $ide->handle('file.create', ['path' => 'games/x/forms/SettingsForm.form.yaml']);
        $form = $ide->handle('gui.read', ['path' => 'games/x/forms/SettingsForm.form.yaml'])['form'];
        self::assertSame('Settings', $form['title']);
        self::assertSame('label', $form['children'][0]['type']);
    }

    public function testTheFormTravelsAsJsonInTheOrderOfTheFile(): void
    {
        $ide = new Ide(new MemoryWorkspace([]));
        $read = $ide->handle('gui.read', ['text' => self::FORM]);
        self::assertStringStartsWith('{"title":"Contacts","children":[{"type":"label"', $read['json']);
        self::assertSame(self::FORM, $ide->handle('gui.dump', ['json' => $read['json'], 'header' => $read['header']])['content']);
    }
}
