<?php

namespace JumPHP\Ide\Tests;

use JumPHP\Ide\Php\Index;
use JumPHP\Ide\Php\Intelligence;
use JumPHP\Ide\Workspace\MemoryWorkspace;
use PHPUnit\Framework\TestCase;

final class IntelligenceTest extends TestCase
{
    private MemoryWorkspace $ws;
    private Intelligence $php;

    protected function setUp(): void
    {
        $this->ws = new MemoryWorkspace([
            'src/Entity/Hero.php' => <<<'PHP'
                <?php
                namespace App\Entity;

                /** The player. */
                class Hero extends Actor
                {
                    public int $lives = 3;
                    private ?Weapon $weapon = null;

                    public function __construct(public string $name) {}

                    /** Jumps if on the ground. */
                    public function jump(float $force = 1.0): static { return $this; }
                    public function weapon(): ?Weapon { return $this->weapon; }
                    public static function create(string $name): self { return new self($name); }
                    private function secret(): void {}
                }
                PHP,
            'src/Entity/Actor.php' => "<?php\nnamespace App\\Entity;\n\nabstract class Actor\n{\n    protected float \$x = 0;\n    public function moveTo(float \$x): void { \$this->x = \$x; }\n}\n",
            'src/Entity/Weapon.php' => "<?php\nnamespace App\\Entity;\n\nfinal class Weapon\n{\n    public const DAMAGE = 3;\n    public function fire(): int { return self::DAMAGE; }\n}\n",
            'src/Scene/Play.php' => <<<'PHP'
                <?php
                namespace App\Scene;

                use App\Entity\Hero;

                final class Play
                {
                    private Hero $hero;

                    public function enter(): void
                    {
                        $this->hero = Hero::create('ada');
                        $this->hero->jump(2.0);
                        $other = new Hero('bob');
                        $other->moveTo(4);
                    }
                }
                PHP,
        ]);
        $index = new Index($this->ws);
        $index->refresh();
        $this->php = new Intelligence($index);
    }

    /** @return array{0: string, 1: int, 2: int} the text with "|" removed, and the cursor's line / column */
    private function cursor(string $text): array
    {
        $offset = strpos($text, '|');
        $before = substr($text, 0, $offset);
        $line = substr_count($before, "\n") + 1;
        $col = strlen($before) - (strrpos($before, "\n") === false ? 0 : strrpos($before, "\n") + 1) + 1;
        return [str_replace('|', '', $text), $line, $col];
    }

    /** @return list<string> */
    private function labels(string $text): array
    {
        [$text, $line, $col] = $this->cursor($text);
        return array_map(fn ($i) => $i['label'], $this->php->complete('src/Scene/Play.php', $text, $line, $col)['items']);
    }

    public function testTheGamesTextsAndActionsAreCompletedInsideTheirStrings(): void
    {
        $this->ws->write('games/demo/config/game.yaml', "game:\n  id: demo\n");
        $this->ws->write('games/demo/config/lang.yaml', "fr:\n  PLAY NOW: JOUER\n  QUIT: QUITTER\nde:\n  QUIT: BEENDEN\n");
        $this->ws->write('games/demo/config/input.yaml', "actions:\n  jump: [Space, pad0:a]\n");
        $complete = function (string $code): array {
            [$text, $line, $col] = $this->cursor("<?php\nclass Play {\n function f() {\n  $code\n }\n}");
            return $this->php->complete('games/demo/src/Scene/Play.php', $text, $line, $col);
        };
        $texts = $complete("Lang::t('PLAY N|");
        self::assertSame(['PLAY NOW', 'QUIT'], array_column($texts['items'], 'label'));
        self::assertSame('PLAY N', $texts['typed']);
        self::assertSame('fr: QUITTER · de: BEENDEN', $texts['items'][1]['detail']);
        $actions = array_column($complete('$this->actions()->isPressed("|')['items'], 'label');
        self::assertContains('jump', $actions);
        self::assertContains('confirm', $actions);
        // outside a game: nothing
        [$text, $line, $col] = $this->cursor("<?php\n\$a->isHeld('|");
        self::assertSame([], $this->php->complete('src/Scene/Play.php', $text, $line, $col)['items']);
    }

    public function testMembersOfThisPropertyChainAreCompleted(): void
    {
        $labels = $this->labels("<?php\nnamespace App\\Scene;\nuse App\\Entity\\Hero;\nclass Play {\n private Hero \$hero;\n function f() {\n  \$this->hero->|\n }\n}");
        self::assertContains('jump', $labels);
        self::assertContains('lives', $labels);
        self::assertContains('moveTo', $labels, 'inherited from Actor');
        self::assertNotContains('secret', $labels, 'private elsewhere');
        self::assertNotContains('x', $labels, 'protected elsewhere');
        self::assertNotContains('create', $labels, 'static');
    }

    public function testReturnTypesAndVariablesAreFollowed(): void
    {
        $labels = $this->labels("<?php\nnamespace App\\Scene;\nuse App\\Entity\\Hero;\nfunction f() {\n \$h = Hero::create('x');\n \$h->jump()->weapon()->|\n}");
        self::assertSame(['fire'], $labels);
    }

    public function testStaticAccessListsStaticMembersAndConstants(): void
    {
        $labels = $this->labels("<?php\nnamespace App\\Scene;\nuse App\\Entity\\Weapon;\nuse App\\Entity\\Hero;\nfunction f() { Hero::|; Weapon::DAMAGE; }");
        self::assertContains('create', $labels);
        self::assertContains('class', $labels);
        self::assertNotContains('jump', $labels);
    }

    public function testAClassFromAnotherNamespaceIsImported(): void
    {
        [$text, $line, $col] = $this->cursor("<?php\nnamespace App\\Scene;\n\nuse App\\Entity\\Hero;\n\nfunction f() { new Wea| }");
        $items = $this->php->complete('src/Scene/Play.php', $text, $line, $col)['items'];
        $weapon = array_values(array_filter($items, fn ($i) => $i['label'] === 'Weapon'))[0];
        self::assertSame("\nuse App\\Entity\\Weapon;", $weapon['additionalTextEdits'][0]['text']);
        self::assertSame(4, $weapon['additionalTextEdits'][0]['range']['startLine']);
    }

    public function testParameterHintsInsideAnUnfinishedCall(): void
    {
        [$text, $line, $col] = $this->cursor("<?php\nnamespace App\\Scene;\nuse App\\Entity\\Hero;\nfunction f(Hero \$h) {\n \$h->jump(|");
        $help = $this->php->signatureHelp('x.php', $text, $line, $col);
        self::assertSame('jump(float $force = 1.0): static', $help['label']);
        self::assertSame(0, $help['active']);
    }

    public function testGoToDefinitionOfAMethodThroughAProperty(): void
    {
        $text = $this->ws->read('src/Scene/Play.php');
        [$line, $col] = $this->find($text, 'jump(2.0)');
        self::assertSame([['path' => 'src/Entity/Hero.php', 'line' => 13, 'col' => 21]], $this->php->definition('src/Scene/Play.php', $text, $line, $col + 1));
    }

    public function testGoToDefinitionOfAnInheritedMethodAndOfAClass(): void
    {
        $text = $this->ws->read('src/Scene/Play.php');
        [$line, $col] = $this->find($text, 'moveTo');
        self::assertSame('src/Entity/Actor.php', $this->php->definition('src/Scene/Play.php', $text, $line, $col)[0]['path']);
        [$line, $col] = $this->find($text, 'Hero(\'bob\')');
        self::assertSame([['path' => 'src/Entity/Hero.php', 'line' => 5, 'col' => 7]], $this->php->definition('src/Scene/Play.php', $text, $line, $col));
    }

    public function testFindUsagesOfAClass(): void
    {
        $text = $this->ws->read('src/Entity/Hero.php');
        [$line, $col] = $this->find($text, 'Hero extends');
        $refs = $this->php->references('src/Entity/Hero.php', $text, $line, $col);
        $where = array_map(fn ($r) => $r['path'] . ':' . $r['line'], $refs);
        self::assertSame(['src/Entity/Hero.php:5', 'src/Scene/Play.php:4', 'src/Scene/Play.php:8', 'src/Scene/Play.php:12', 'src/Scene/Play.php:14'], $where);
    }

    public function testRenameAMethodEverywhere(): void
    {
        $text = $this->ws->read('src/Entity/Hero.php');
        [$line, $col] = $this->find($text, 'jump(float');
        $result = $this->php->rename('src/Entity/Hero.php', $text, $line, $col, 'leap');
        self::assertSame(['src/Entity/Hero.php', 'src/Scene/Play.php'], array_keys($result['edits']));
        self::assertSame(['line' => 13, 'col' => 22, 'length' => 4, 'text' => 'leap'], $result['edits']['src/Scene/Play.php'][0]);
    }

    public function testRenameAClassRenamesItsFile(): void
    {
        $text = $this->ws->read('src/Entity/Weapon.php');
        [$line, $col] = $this->find($text, 'Weapon');
        $result = $this->php->rename('src/Entity/Weapon.php', $text, $line, $col, 'Blaster');
        self::assertSame(['from' => 'src/Entity/Weapon.php', 'to' => 'src/Entity/Blaster.php'], $result['renameFile']);
        self::assertCount(2, $result['edits']['src/Entity/Hero.php'] ?? []);
    }

    public function testRenameALocalVariable(): void
    {
        $text = $this->ws->read('src/Scene/Play.php');
        [$line, $col] = $this->find($text, '$other =');
        $result = $this->php->rename('src/Scene/Play.php', $text, $line, $col + 1, 'bob');
        self::assertCount(2, $result['edits']['src/Scene/Play.php']);
    }

    public function testProblems(): void
    {
        $diagnostics = $this->php->diagnostics('x.php', "<?php\nnamespace App;\nuse App\\Entity\\Hero;\nfunction f() { \$h = new Hero('x'); \$h->fly(); new Missing(); }");
        self::assertSame(["Method 'fly' not found in Hero", "Undefined class 'App\\Missing'"], array_column($diagnostics, 'message'));
        $errors = $this->php->diagnostics('x.php', "<?php\nfunction f( {");
        self::assertSame('error', $errors[0]['severity']);
    }

    public function testHoverShowsTheSignatureAndTheDocblock(): void
    {
        $text = $this->ws->read('src/Scene/Play.php');
        [$line, $col] = $this->find($text, 'jump(2.0)');
        $hover = $this->php->hover('src/Scene/Play.php', $text, $line, $col);
        self::assertStringContainsString('public function jump(float $force = 1.0): static', $hover['contents']);
        self::assertStringContainsString('Jumps if on the ground.', $hover['contents']);
    }

    public function testOutlineAndSymbolSearch(): void
    {
        $outline = $this->php->outline($this->ws->read('src/Entity/Hero.php'));
        self::assertSame('Hero', $outline[0]['name']);
        self::assertContains('jump', array_column($outline[0]['children'], 'name'));
        self::assertContains('$name', array_column($outline[0]['children'], 'name'), 'promoted property');
        self::assertSame('Hero', $this->php->searchSymbols('hro')[0]['name']);
    }

    public function testUnicodeColumnsAreCountedLikeTheEditor(): void
    {
        $text = "<?php\n\$s = 'é😀'; \$h = new \\App\\Entity\\Hero('x'); \$h->jump();";
        [$line, $col] = $this->find($text, 'jump');
        self::assertSame('src/Entity/Hero.php', $this->php->definition('x.php', $text, $line, $col)[0]['path']);
    }

    /** @return array{0: int, 1: int} line and column (as the editor counts) of a needle */
    private function find(string $text, string $needle): array
    {
        $offset = strpos($text, $needle);
        $before = substr($text, 0, $offset);
        $line = substr_count($before, "\n") + 1;
        $lineStart = strrpos($before, "\n");
        $prefix = $lineStart === false ? $before : substr($before, $lineStart + 1);
        return [$line, intdiv(strlen(mb_convert_encoding($prefix, 'UTF-16LE', 'UTF-8')), 2) + 1];
    }
}
