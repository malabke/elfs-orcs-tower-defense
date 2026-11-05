# Dialogue

`jumphp/dialogue`: dialogues and quests for JumPHP games. **Branching conversations** with conditions and variables written as JSON files
(checked when loaded: an error says the dialogue, the node, the choice), a **quest log** with objectives, and a **text box** that writes the
text letter by letter and shows the choices. Pure PHP, 32-bit safe: the same on the desktop and in the browser.

## A file: `assets/dialogue/village.dialogue.json`

```json
{
  "variables": { "gold": 5, "name": "traveller" },
  "quests": { "rescue": { "title": "The lost key", "objectives": [
      { "id": "find-key", "text": "Find the key" },
      { "id": "coins", "text": "Pick up the coins", "count": 3 } ] } },
  "dialogues": { "elder": { "start": "router", "nodes": {
      "router": { "branch": [ { "if": "quest.rescue == 'done'", "next": "thanks" }, { "next": "hello" } ] },
      "hello":  { "speaker": "Elder", "text": ["Welcome, {name}.", "I lost my key."], "next": "ask" },
      "ask":    { "speaker": "Elder", "text": "Will you help?", "choices": [
          { "text": "Of course", "next": "yes", "action": "quest.start:rescue" },
          { "text": "What do you pay? ({gold} gold)", "if": "gold < 10 && !asked_pay", "next": "pay" },
          { "text": "Not now", "next": "no" } ] },
      "pay":    { "text": "Ten gold.", "set": { "asked_pay": true }, "next": "ask" },
      "yes":    { "text": "Thank you!", "end": true },
      "no":     { "text": "Another time.", "end": true },
      "thanks": { "text": "You found it!", "set": { "gold": "+10" }, "action": "give:reward", "end": true } } } }
}
```

| in a node | |
|---|---|
| `speaker`, `text` | `text` is a string, or a list said one after the other; `{name}` is a variable |
| `next` | the node that follows (none, or `end: true`: the conversation ends) |
| `choices` | `[{text, next, if, set, action}]`: the player picks; `if` hides a choice |
| `set` | variables: `{"met": true, "gold": "+5"}` (`"+5"` / `"-2"` add to a counter) |
| `action` | what happens (a string or a list): see below |
| `branch` | `[{if, next}, {next}]`: a router, the first condition that holds (no `if` = default) |

**Conditions**: names (dots allowed), numbers, `'strings'`, `true false null`, `== != < <= > >=`, `! && ||`, parentheses. A small parser reads
them (never `eval`); an unknown variable is `null`. The variables `quest.<id>` (inactive / active / done / failed), `quest.<id>.<objective>` (how many)
and `seen.<dialogue>.<node>` (true once the node was reached) are kept for you.

**Actions**: `quest.start:<id>`, `quest.progress:<id>/<objective>[/<n>]`, `quest.complete:<id>`, `quest.fail:<id>` are done by the extension; any other
(`give:key`, `open:door`) goes to `$runner->onAction(fn (string $action) => ...)`.

## In a scene

```php
public function __construct(private DialogueRunner $runner, private DialogueBox $box, private QuestLog $quests) {}

public function enter(array $params = []): void
{
    $this->runner->clearListeners();                          // the services live as long as the game
    $this->runner->load($this->engine->extensions()->load('assets/dialogue/village.dialogue.json'));
    $this->runner->onAction(fn (string $action) => $this->give($action));
}

// to talk:  $this->runner->start('elder');
public function update(float $dt): void
{
    $this->box->update($dt, $this->runner->isActive() ? $this->actions() : null);   // confirm: reveal, go on, take the choice; up / down: the cursor
    $this->quests->progress('rescue', 'find-key');                                   // the game moves the quests too
}

public function render(Renderer $r): void { $this->box->render($r, $this->font()); }   // last
```

Careful: the key that ends a conversation (confirm) must not start the next one in the same frame (see `games/dialogue-demo`).

## Save

`$runner->variables()->toArray()`, `$quests->toArray()`, `$runner->toArray()` and their `restore()` (even in the middle of a conversation).

## Sample

`DialogueSample` (a shopkeeper with choices, from a PHP array) comes into your game with `bin/jumphp extension:add <game> jumphp/dialogue`.
