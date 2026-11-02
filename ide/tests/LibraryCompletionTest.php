<?php

namespace JumPHP\Ide\Tests;

use JumPHP\Ide\Php\Index;
use JumPHP\Ide\Php\Intelligence;
use JumPHP\Ide\Workspace\MemoryWorkspace;
use PHPUnit\Framework\TestCase;

final class LibraryCompletionTest extends TestCase
{
    private Intelligence $php;

    protected function setUp(): void
    {
        $ws = new MemoryWorkspace([
            'composer.json' => '{"autoload": {"psr-4": {"App\\\\": "src/"}}}',
            'src/Hero.php' => "<?php\nnamespace App;\n\nclass Hero\n{\n    public function jump(): void {}\n}\n",
            'src/Box.php' => <<<'PHP'
                <?php
                namespace App;

                /**
                 * @template T
                 * @method T first()
                 * @property-read int $size
                 */
                class Box implements \IteratorAggregate
                {
                    /** @var list<T> */
                    private array $items = [];
                    /** @return T|null */
                    public function get(int $i): mixed { return $this->items[$i] ?? null; }
                    /** @return \ArrayIterator<int, T> */
                    public function getIterator(): \Iterator { return new \ArrayIterator($this->items); }
                    /** @return array{hero: Hero, count: int} */
                    public function shape(): array { return []; }
                }
                PHP,
            'vendor/composer/autoload_psr4.php' => "<?php\n\$vendorDir = dirname(__DIR__);\n\$baseDir = dirname(\$vendorDir);\nreturn array(\n    'Lib\\\\' => array(\$vendorDir . '/acme/lib/src'),\n    'App\\\\' => array(\$baseDir . '/src'),\n);\n",
            'vendor/composer/autoload_files.php' => "<?php\n\$vendorDir = dirname(__DIR__);\n\$baseDir = dirname(\$vendorDir);\nreturn array(\n    'abc' => \$vendorDir . '/acme/lib/src/functions.php',\n);\n",
            'vendor/composer/autoload_classmap.php' => "<?php\n\$vendorDir = dirname(__DIR__);\n\$baseDir = dirname(\$vendorDir);\nreturn array(\n    'Legacy_Thing' => \$vendorDir . '/acme/old/Thing.php',\n);\n",
            'vendor/acme/lib/src/functions.php' => "<?php\nnamespace Lib;\n\n/** Makes a tween. */\nfunction tween_to(float \$to): float { return \$to; }\n",
            'vendor/acme/lib/src/Tween.php' => "<?php\nnamespace Lib;\n\nclass Tween\n{\n    public function play(): void {}\n}\n",
            'vendor/acme/old/Thing.php' => "<?php\nclass Legacy_Thing\n{\n    public function old(): void {}\n}\n",
        ]);
        $index = new Index($ws);
        $index->refresh();
        $this->php = new Intelligence($index);
    }

    /** @return list<string> */
    private function labels(string $text): array
    {
        $offset = strpos($text, '|');
        $before = substr($text, 0, $offset);
        $line = substr_count($before, "\n") + 1;
        $col = strlen($before) - (strrpos($before, "\n") === false ? 0 : strrpos($before, "\n") + 1) + 1;
        return array_map(fn ($i) => $i['label'], $this->php->complete('src/Play.php', str_replace('|', '', $text), $line, $col)['items']);
    }

    private const HEAD = "<?php\nnamespace App;\n\n";

    public function testGenericsAreFollowed(): void
    {
        self::assertContains('jump', $this->labels(self::HEAD . "/** @param Box<Hero> \$box */\nfunction f(Box \$box) { \$box->get(0)->| }"));
    }

    public function testForeachOverAGenericCollection(): void
    {
        self::assertContains('jump', $this->labels(self::HEAD . "/** @param Box<Hero> \$box */\nfunction f(Box \$box) { foreach (\$box as \$h) { \$h->| } }"));
    }

    public function testForeachOverATypedArray(): void
    {
        self::assertContains('jump', $this->labels(self::HEAD . "/** @param list<Hero> \$heroes */\nfunction f(array \$heroes) { foreach (\$heroes as \$h) { \$h->| } }"));
    }

    public function testArrayShapes(): void
    {
        self::assertContains('jump', $this->labels(self::HEAD . "function f(Box \$box) { \$box->shape()['hero']->| }"));
    }

    public function testArrayShapeKeys(): void
    {
        self::assertContains('hero', $this->labels(self::HEAD . "function f(Box \$box) { \$box->shape()['| }"));
    }

    public function testMagicMethodsAndProperties(): void
    {
        $labels = $this->labels(self::HEAD . "function f(Box \$box) { \$box->| }");
        self::assertContains('first', $labels);
        self::assertContains('size', $labels);
    }

    public function testMagicMethodWithGenericReturn(): void
    {
        self::assertContains('jump', $this->labels(self::HEAD . "/** @param Box<Hero> \$box */\nfunction f(Box \$box) { \$box->first()->| }"));
    }

    public function testInlineVarAnnotation(): void
    {
        self::assertContains('jump', $this->labels(self::HEAD . "function f() { /** @var Hero \$h */\n \$h = g();\n \$h->| }"));
    }

    public function testInstanceofNarrows(): void
    {
        self::assertContains('jump', $this->labels(self::HEAD . "function f(\$x) { if (\$x instanceof Hero) { \$x->| } }"));
    }

    public function testClosureParameters(): void
    {
        self::assertContains('jump', $this->labels(self::HEAD . "\$f = function (Hero \$h) { \$h->| };"));
    }

    public function testArrowFunctionParameters(): void
    {
        self::assertContains('jump', $this->labels(self::HEAD . "\$f = fn (Hero \$h) => \$h->|;"));
    }

    public function testVendorClassMembers(): void
    {
        self::assertContains('play', $this->labels(self::HEAD . "use Lib\\Tween;\nfunction f(Tween \$t) { \$t->| }"));
    }

    public function testVendorClassNames(): void
    {
        self::assertContains('Tween', $this->labels(self::HEAD . "function f() { new Twe| }"));
    }

    public function testVendorFunctions(): void
    {
        self::assertContains('tween_to', $this->labels(self::HEAD . "function f() { twe| }"));
    }

    public function testClassmapClasses(): void
    {
        self::assertContains('Legacy_Thing', $this->labels(self::HEAD . "function f() { new Legacy| }"));
        self::assertContains('old', $this->labels(self::HEAD . "function f(\\Legacy_Thing \$t) { \$t->| }"));
    }

    public function testBuiltinClassMembers(): void
    {
        self::assertContains('format', $this->labels(self::HEAD . "function f() { \$d = new \\DateTimeImmutable(); \$d->| }"));
    }

    public function testStaticFactoryOfAVendorClass(): void
    {
        self::assertContains('play', $this->labels(self::HEAD . "use Lib\\Tween;\nfunction f() { \$t = new Tween(); \$t->| }"));
    }

    public function testTheLibrariesAreIndexedInTheBackgroundAndCached(): void
    {
        $files = [
            'composer.json' => '{"autoload": {"psr-4": {"App\\\\": "src/"}}}',
            'vendor/composer/autoload_psr4.php' => "<?php\n\$vendorDir = dirname(__DIR__);\nreturn array(\n    'Lib\\\\' => array(\$vendorDir . '/acme/lib/src'),\n);\n",
            'vendor/acme/lib/src/Shape.php' => "<?php\nnamespace Lib;\n\ninterface Shape {}\n",
            'vendor/acme/lib/src/Geo/Circle.php' => "<?php\nnamespace Lib\\Geo;\n\nclass Circle implements \\Lib\\Shape\n{\n    public function radius(): float { return 1.0; }\n}\n",
            'vendor/acme/lib/tests/CircleTest.php' => "<?php\nclass CircleTest {}\n",
        ];
        $cache = sys_get_temp_dir() . '/jumphp-index-test-' . bin2hex(random_bytes(4)) . '.json';
        $index = new Index(new MemoryWorkspace($files), $cache);
        $index->refresh();
        self::assertSame([], $index->subclasses('Lib\\Shape'), 'nothing of the library is known before');
        while (!$index->indexLibraries(0.0)) {
            // a file per call with no budget: the IDE gives it a slice per frame
        }
        self::assertSame(['done' => 2, 'total' => 2], $index->libraryProgress(), 'its classes only, not its tests');
        self::assertContains('Lib\\Geo\\Circle', array_map(fn (array $s) => $s[1]['name'], $index->subclasses('Lib\\Shape')));
        self::assertFileExists("$cache.libraries");

        // the next start: from the cache
        $again = new Index(new MemoryWorkspace($files), $cache);
        $again->refresh();
        self::assertTrue($again->indexLibraries(1.0));
        self::assertContains('Lib\\Geo\\Circle', array_map(fn (array $s) => $s[1]['name'], $again->subclasses('Lib\\Shape')));
        @unlink($cache);
        @unlink("$cache.libraries");
    }
}
