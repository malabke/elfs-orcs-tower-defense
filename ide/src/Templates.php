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
        if (preg_match('/\.form\.ya?ml$/', $path)) {
            // a window of a graphic app: open it in the visual editor; its class is written at the first double click
            $title = trim((string)preg_replace('/(?<!^)[A-Z]/', ' $0', preg_replace('/Form$/', '', basename($path, '.form.yaml')) ?: 'Form'));
            return "# A window of the app (JumPHP\\Gui): open it in Design view. The code behind it: src/Form/" . basename($path, '.form.yaml') . ".php\nform:\n  title: '$title'\n  children:\n    - {type: label, text: '$title', style: title}\n";
        }
        if (str_ends_with($path, '.sfx.json')) {
            // a sound effect of the resource editor (it writes the .wav next to it at each save)
            return json_encode(['wave' => 'square', 'start' => 300, 'end' => 900, 'attack' => 0, 'sustain' => 60, 'decay' => 120,
                'volume' => 6, 'vibrato' => 0, 'speed' => 0, 'repeat' => 1, 'gap' => 0], JSON_PRETTY_PRINT) . "\n";
        }
        if (str_ends_with($path, '.music.json')) {
            // a tune of the resource editor: 4 voices, notes [step, MIDI pitch, length in steps], 16 steps a bar
            $lead = [[0, 72, 2], [4, 76, 2], [8, 79, 2], [12, 76, 2], [16, 74, 2], [20, 77, 2], [24, 81, 4]];
            return json_encode(['tempo' => 120, 'bars' => 2, 'voices' => [
                ['volume' => 7, 'notes' => $lead], ['volume' => 5, 'notes' => []],
                ['volume' => 7, 'notes' => [[0, 48, 4], [8, 43, 4], [16, 50, 4], [24, 45, 4]]],
                ['volume' => 6, 'notes' => [[0, 0, 1], [8, 1, 1], [16, 0, 1], [24, 1, 1], [28, 2, 1]]],
            ]]) . "\n";
        }
        if ($ext === 'tsj') {
            // a tileset of the resource editor (Tiled's JSON): its picture and each tile's collision are set in the editor
            return json_encode(['type' => 'tileset', 'version' => '1.10', 'tiledversion' => '1.10.2', 'name' => $name, 'tilewidth' => 16,
                'tileheight' => 16, 'margin' => 0, 'spacing' => 0, 'columns' => 0, 'tilecount' => 0, 'image' => '', 'imagewidth' => 0,
                'imageheight' => 0, 'tiles' => []], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n";
        }
        if ($ext === 'tmj') {
            // a level of the resource editor (a Tiled map): a decor layer, the collision layer, an object layer
            [$w, $h] = [40, 12];
            $tiles = fn (int $id, string $layer) => ['id' => $id, 'name' => $layer, 'type' => 'tilelayer', 'width' => $w, 'height' => $h,
                'opacity' => 1, 'visible' => true, 'x' => 0, 'y' => 0, 'data' => array_fill(0, $w * $h, 0)];
            $json = json_encode(['type' => 'map', 'version' => '1.10', 'tiledversion' => '1.10.2', 'orientation' => 'orthogonal',
                'renderorder' => 'right-down', 'infinite' => false, 'width' => $w, 'height' => $h, 'tilewidth' => 16, 'tileheight' => 16,
                'nextlayerid' => 4, 'nextobjectid' => 1, 'tilesets' => [], 'layers' => [$tiles(1, 'background'), $tiles(2, 'collision'),
                ['id' => 3, 'name' => 'objects', 'type' => 'objectgroup', 'draworder' => 'topdown', 'opacity' => 1, 'visible' => true,
                    'x' => 0, 'y' => 0, 'objects' => []]]], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
            return preg_replace_callback('/\[\s*(-?\d+(?:\s*,\s*-?\d+)*)\s*\]/', fn ($m) => '[' . preg_replace('/\s+/', '', $m[1]) . ']', $json) . "\n";
        }
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
