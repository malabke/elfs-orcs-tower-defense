<?php

namespace JumPHP\Ide;

use JumPHP\Ide\Workspace\Paths;
use JumPHP\Ide\Workspace\Workspace;

/** What a new file starts with: a PHP class in the namespace its folder maps to (Composer PSR-4), a page... */
final class Templates
{
    public static function forFile(string $path, Workspace $workspace): string
    {
        $ext = Paths::extension($path);
        $name = pathinfo($path, PATHINFO_FILENAME);
        return match ($ext) {
            'php' => self::php($path, $name, $workspace),
            'html', 'htm' => "<!doctype html>\n<html lang=\"en\">\n<head>\n  <meta charset=\"utf-8\">\n  <meta name=\"viewport\" content=\"width=device-width, initial-scale=1\">\n  <title>$name</title>\n  <link rel=\"stylesheet\" href=\"style.css\">\n</head>\n<body>\n  <main>\n    <h1>$name</h1>\n  </main>\n</body>\n</html>\n",
            'md' => "# $name\n",
            'yaml', 'yml' => '',
            'json' => "{\n}\n",
            default => '',
        };
    }

    /** The namespace of a PHP file from the nearest composer.json's PSR-4 autoload (autoload-dev included). */
    public static function namespaceOf(string $path, Workspace $workspace): ?string
    {
        $dir = dirname($path);
        while (true) {
            $manifest = ($dir === '.' || $dir === '' ? '' : "$dir/") . 'composer.json';
            if ($workspace->exists($manifest)) {
                $json = json_decode($workspace->read($manifest), true);
                $base = $dir === '.' || $dir === '' ? '' : "$dir/";
                $relative = substr($path, strlen($base));
                $best = null;
                foreach (['autoload', 'autoload-dev'] as $section) {
                    foreach ((array)($json[$section]['psr-4'] ?? []) as $prefix => $dirs) {
                        foreach ((array)$dirs as $folder) {
                            $folder = trim((string)$folder, '/');
                            $folder = $folder === '' ? '' : "$folder/";
                            if (str_starts_with($relative, $folder) && ($best === null || strlen($folder) > strlen($best[1]))) {
                                $best = [$prefix, $folder];
                            }
                        }
                    }
                }
                if ($best !== null) {
                    $sub = trim(dirname(substr($relative, strlen($best[1]))), '.');
                    $namespace = rtrim($best[0], '\\') . ($sub !== '' && $sub !== '/' ? '\\' . str_replace('/', '\\', trim($sub, '/')) : '');
                    return trim($namespace, '\\');
                }
                return null;
            }
            if ($dir === '.' || $dir === '' || $dir === '/') {
                return null;
            }
            $dir = dirname($dir);
        }
    }

    private static function php(string $path, string $name, Workspace $workspace): string
    {
        $namespace = self::namespaceOf($path, $workspace);
        $head = "<?php\n\n" . ($namespace ? "namespace $namespace;\n\n" : '');
        if (!preg_match('/^[A-Z]\w*$/', $name)) {
            return $head;
        }
        if (str_ends_with($name, 'Test') && str_contains($path, 'tests/')) {
            $scene = 'App\\Scene\\PlayScene';
            $method = 'test' . substr($name, 0, -4) . 'Works';
            return $head . "use $scene;\nuse JumPHP\\Testing\\GameTestCase;\n\nfinal class $name extends GameTestCase\n{\n    public function $method(): void\n    {\n        \$this->game->start(PlayScene::class);\n        \$this->game->run(60);\n        self::assertInstanceOf(PlayScene::class, \$this->game->scene());\n    }\n}\n";
        }
        if (str_contains($path, '/Scene/') && str_ends_with($name, 'Scene')) {
            return $head . "use JumPHP\\Renderer\\Renderer;\nuse JumPHP\\Scene\\AbstractScene;\n\nfinal class $name extends AbstractScene\n{\n    public function enter(array \$params = []): void\n    {\n    }\n\n    public function update(float \$dt): void\n    {\n    }\n\n    public function render(Renderer \$r): void\n    {\n        \$r->clear([0, 0, 0]);\n    }\n}\n";
        }
        if (str_contains($path, '/Entity/')) {
            return $head . "use JumPHP\\Entity\\Entity;\nuse JumPHP\\Renderer\\Renderer;\n\nfinal class $name extends Entity\n{\n    public function __construct(float \$x, float \$y)\n    {\n        \$this->x = \$x;\n        \$this->y = \$y;\n        \$this->width = 16;\n        \$this->height = 16;\n    }\n\n    public function update(float \$dt): void\n    {\n        parent::update(\$dt);\n    }\n\n    public function render(Renderer \$r, float \$camX = 0, float \$camY = 0): void\n    {\n        \$r->fillRect(\$this->x - \$camX, \$this->y - \$camY, \$this->width, \$this->height, '#ffcc00');\n    }\n}\n";
        }
        $kind = str_ends_with($name, 'Interface') ? 'interface' : (str_ends_with($name, 'Trait') ? 'trait' : 'final class');
        return $head . "$kind $name\n{\n}\n";
    }
}
