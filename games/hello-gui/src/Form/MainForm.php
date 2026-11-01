<?php

namespace App\Form;

use JumPHP\Gui\Button;
use JumPHP\Gui\CheckBox;
use JumPHP\Gui\ComboBox;
use JumPHP\Gui\Container;
use JumPHP\Gui\Form;
use JumPHP\Gui\Image;
use JumPHP\Gui\Label;
use JumPHP\Gui\ListBox;
use JumPHP\Gui\TextBox;

/**
 * The main window: its widgets are in forms/MainForm.form.yaml (the IDE's visual editor), its code here.
 * The contacts are saved in var/contacts.json.
 *
 * @property Image $logo
 * @property Label $heading
 * @property Label $subtitle
 * @property CheckBox $dark
 * @property Container $editor
 * @property TextBox $name
 * @property TextBox $email
 * @property ComboBox $country
 * @property CheckBox $newsletter
 * @property Button $add
 * @property Button $clear
 * @property Container $people
 * @property ListBox $list
 * @property Label $status
 * @property Button $remove
 */
final class MainForm extends Form
{
    /** @var list<array{name: string, email: string, country: string, newsletter: bool}> */
    private array $contacts = [];

    protected function loaded(array $params = []): void
    {
        $this->contacts = $this->engine->storage->readJson('contacts.json', []);
        if (!empty($params['demo'])) {
            // the smoke test (bin/jumphp test): a contact typed and added
            $this->name->text = 'Ada Lovelace';
            $this->email->text = 'ada@example.com';
            $this->onAddClick($this->add);
        }
        $this->refresh();
    }

    public function onAddClick(Button $sender): void
    {
        $name = trim($this->name->text);
        if ($name === '') {
            $this->status->text = 'A name, please';
            $this->setFocus($this->name);
            return;
        }
        $this->contacts[] = ['name' => $name, 'email' => trim($this->email->text), 'country' => (string)$this->country->value(),
            'newsletter' => $this->newsletter->checked];
        $this->engine->storage->writeJson('contacts.json', $this->contacts);
        $this->onClearClick($this->clear);
        $this->refresh();
        $this->list->select(count($this->contacts) - 1);
    }

    public function onNameSubmit(TextBox $sender, string $text): void
    {
        $this->setFocus($this->email);
    }

    public function onClearClick(Button $sender): void
    {
        $this->name->text = '';
        $this->email->text = '';
        $this->setFocus($this->name);
    }

    public function onListChange(ListBox $sender, int $index, string $item): void
    {
        $this->remove->enabled = $index >= 0;
        $contact = $this->contacts[$index] ?? null;
        $this->status->text = $contact === null ? $this->countText()
            : implode(' · ', array_filter([$contact['email'], $contact['country'], $contact['newsletter'] ? 'newsletter' : '']));
    }

    public function onListActivate(ListBox $sender, int $index, string $item): void
    {
        $contact = $this->contacts[$index];
        $this->name->text = $contact['name'];
        $this->email->text = $contact['email'];
    }

    public function onRemoveClick(Button $sender): void
    {
        $index = $this->list->selected;
        array_splice($this->contacts, $index, 1);
        $this->engine->storage->writeJson('contacts.json', $this->contacts);
        $this->refresh();
    }

    public function onDarkChange(CheckBox $sender, bool $checked): void
    {
        $this->theme->colors = $checked ? \JumPHP\Gui\Theme::DARK : \JumPHP\Gui\Theme::LIGHT;
        $this->root->background = $this->theme->colors['background'];
    }

    private function refresh(): void
    {
        $this->list->items = array_map(fn (array $c) => $c['name'] . ($c['email'] !== '' ? '  <' . $c['email'] . '>' : ''), $this->contacts);
        $this->list->selected = -1;
        $this->remove->enabled = false;
        $this->status->text = $this->countText();
    }

    private function countText(): string
    {
        return match (count($this->contacts)) {
            0 => 'No contact yet',
            1 => '1 contact',
            default => count($this->contacts) . ' contacts',
        };
    }
}
