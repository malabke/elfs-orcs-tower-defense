<?php

namespace JumPHP\Ide\Plugin;

/**
 * A plugin's folder as its composer.json describes it:
 *
 *     {
 *         "name": "acme/todos",
 *         "type": "jumphp-ide-plugin",
 *         "description": "Lists the TODOs of the project",
 *         "autoload": {"psr-4": {"Acme\\Todos\\": "src/"}},
 *         "extra": {"jumphp-ide": {"id": "todos", "name": "TODOs", "version": "0.1.0", "class": "Acme\\Todos\\TodoPlugin"}}
 *     }
 *
 * The IDE loads its classes with that autoload (psr-4, classmap, files); a plugin has no packages of its own at
 * run time (the IDE's are there: nikic/php-parser, symfony/yaml).
 */
final class PluginInfo
{
    /**
     * @param array<string, list<string>> $psr4 prefix => folders (absolute)
     * @param list<string> $files
     * @param list<string> $classmap
     */
    public function __construct(
        public readonly string $id,
        public readonly string $name,
        public readonly string $version,
        public readonly string $description,
        public readonly string $class,
        public readonly string $dir,
        public readonly string $source,
        public readonly array $psr4 = [],
        public readonly array $files = [],
        public readonly array $classmap = [],
    ) {
    }

    /** @throws \InvalidArgumentException when the folder is not a plugin */
    public static function read(string $dir, string $source = 'user'): self
    {
        $dir = rtrim($dir, '/\\');
        $json = @file_get_contents("$dir/composer.json");
        $c = $json === false ? null : json_decode($json, true);
        if (!is_array($c)) {
            throw new \InvalidArgumentException("$dir: no composer.json");
        }
        $extra = $c['extra']['jumphp-ide'] ?? null;
        if (!is_array($extra) || !isset($extra['class']) || !is_string($extra['class'])) {
            throw new \InvalidArgumentException("$dir: composer.json has no extra.jumphp-ide.class (the plugin's class)");
        }
        $id = (string)($extra['id'] ?? basename($dir));
        if (!preg_match('/^[a-z0-9][a-z0-9-]*$/', $id)) {
            throw new \InvalidArgumentException("$dir: plugin id '$id': lower case letters, digits and dashes");
        }
        $psr4 = [];
        foreach ((array)($c['autoload']['psr-4'] ?? []) as $prefix => $paths) {
            $psr4[(string)$prefix] = array_map(fn ($p) => $dir . '/' . trim((string)$p, '/'), (array)$paths);
        }
        $abs = fn (array $list) => array_values(array_map(fn ($p) => $dir . '/' . ltrim((string)$p, '/'), $list));
        return new self($id, (string)($extra['name'] ?? $id), (string)($extra['version'] ?? $c['version'] ?? '0.0.0'),
            (string)($c['description'] ?? ''), ltrim($extra['class'], '\\'), $dir, $source, $psr4,
            $abs((array)($c['autoload']['files'] ?? [])), $abs((array)($c['autoload']['classmap'] ?? [])));
    }

    /** Its classes become loadable (once). */
    public function registerAutoload(): void
    {
        $psr4 = $this->psr4;
        spl_autoload_register(static function (string $class) use ($psr4): void {
            foreach ($psr4 as $prefix => $dirs) {
                if ($prefix !== '' && !str_starts_with($class, $prefix)) {
                    continue;
                }
                $relative = str_replace('\\', '/', substr($class, strlen($prefix))) . '.php';
                foreach ($dirs as $d) {
                    if (is_file("$d/$relative")) {
                        require_once "$d/$relative";
                        return;
                    }
                }
            }
        });
        foreach ($this->classmap as $path) {
            foreach (is_dir($path) ? new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($path, \FilesystemIterator::SKIP_DOTS)) : [new \SplFileInfo($path)] as $f) {
                if ($f instanceof \SplFileInfo && $f->getExtension() === 'php') {
                    require_once $f->getPathname();
                }
            }
        }
        foreach ($this->files as $file) {
            require_once $file;
        }
    }

    /** @return array<string, string> */
    public function describe(): array
    {
        return ['id' => $this->id, 'name' => $this->name, 'version' => $this->version, 'description' => $this->description,
            'dir' => $this->dir, 'source' => $this->source];
    }
}
