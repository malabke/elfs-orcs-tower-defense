<?php

namespace JumPHP\Ide\Php;

use JumPHP\Ide\Workspace\Paths;
use JumPHP\Ide\Workspace\Workspace;

/**
 * Every PHP symbol of a project. Its own files (vendor/ left out) are all analyzed, little by little
 * (refresh() takes a time budget: the desktop app gives it a few ms per frame) and kept in a cache file.
 * Libraries (Composer's vendor/ folders of the project) are found through their PSR-4 prefixes and analyzed
 * when a class of theirs is needed, so a big vendor/ costs nothing until used.
 *
 * A file's entry: Analyzer::analyze() + 'path' + 'mtime'. Library paths start with "@lib:" when the file is
 * outside the project (else they are project paths: a path repository such as vendor/jumphp/engine ->
 * ../../engine leads back into the project).
 */
final class Index
{
    public const VERSION = 4;

    /** @var array<string, array<string, mixed>> path => file entry (project files) */
    private array $files = [];

    /** @var array<string, array<string, mixed>> path => file entry (library files, analyzed on demand) */
    private array $libraries = [];

    /** @var array<string, string> lower fqn => path (project + libraries analyzed) */
    private array $classes = [];

    /** @var array<string, string> lower fqn => path */
    private array $functions = [];

    /** @var array<string, list<string>> PSR-4 prefix => folders (absolute, or project paths) */
    private array $psr4 = [];

    /** @var array<string, array{0: string, 1: string}> Composer's classmap: lower fqn => [fqn, path] */
    private array $classmap = [];

    /** @var list<string> Composer's "files" (functions of the libraries), analyzed at the first need */
    private array $autoloadFiles = [];

    private bool $autoloadFilesLoaded = false;

    /** @var list<string>|null project PHP files still to (re)analyze */
    private ?array $pending = null;

    private bool $dirty = false;

    /** @var array<string, list<string>> PSR-4 prefixes given by the host (a library outside the project) */
    private array $extraPsr4 = [];

    /** @var list<string> stub files (functions / classes of extensions), analyzed at the first miss */
    private array $stubs = [];

    private bool $stubsLoaded = false;

    public function __construct(private Workspace $workspace, private ?string $cacheFile = null)
    {
        $this->load();
    }

    /** A library outside the project: its PSR-4 prefix and folder (absolute, or relative to the project). */
    public function addLibrary(string $prefix, string $dir): void
    {
        $this->extraPsr4[$prefix][] = rtrim(str_replace('\\', '/', $dir), '/');
        $this->findLibraries();
    }

    /** Stub files (absolute paths) declaring functions, classes and constants of extensions the IDE does not have. */
    public function addStubs(string ...$files): void
    {
        array_push($this->stubs, ...$files);
        $this->stubsLoaded = false;
    }

    private function loadStubs(): bool
    {
        if ($this->stubsLoaded) {
            return false;
        }
        $this->stubsLoaded = true;
        foreach ($this->stubs as $file) {
            if (isset($this->libraries[$file]) || !is_file($file)) {
                continue;
            }
            $entry = Analyzer::analyze((string)file_get_contents($file)) + ['path' => $file, 'mtime' => 0.0];
            $entry['refs'] = [];
            $this->libraries[$file] = $entry;
            $this->register($entry);
        }
        return true;
    }

    public function workspace(): Workspace
    {
        return $this->workspace;
    }

    /**
     * Analyzes what changed, for at most $budget seconds (null: everything). Returns true when the index is
     * complete.
     */
    public function refresh(?float $budget = null): bool
    {
        $start = microtime(true);
        if ($this->pending === null) {
            $this->pending = [];
            $seen = [];
            foreach ($this->workspace->files() as $path => $meta) {
                if (Paths::extension($path) !== 'php' || $meta['size'] > 2_000_000) {
                    continue;
                }
                $seen[$path] = true;
                if (($this->files[$path]['mtime'] ?? null) !== $meta['mtime']) {
                    $this->pending[] = $path;
                }
            }
            foreach (array_keys($this->files) as $path) {
                if (!isset($seen[$path])) {
                    $this->forget($path);
                }
            }
            $this->findLibraries();
        }
        while ($this->pending) {
            $path = array_shift($this->pending);
            $this->update($path);
            if ($budget !== null && microtime(true) - $start > $budget) {
                break;
            }
        }
        if (!$this->pending && $this->dirty) {
            $this->save();
        }
        return !$this->pending;
    }

    /** Files changed: the next refresh() looks at the folder again. */
    public function invalidate(): void
    {
        $this->pending = null;
    }

    /** @return array{done: int, total: int} */
    public function progress(): array
    {
        $pending = $this->pending === null ? 0 : count($this->pending);
        return ['done' => count($this->files), 'total' => count($this->files) + $pending];
    }

    /** @var list<string>|null the library files still to analyze in the background (null: not listed yet) */
    private ?array $libraryQueue = null;
    private int $libraryTotal = 0;
    /** @var array<string, array<string, mixed>> the libraries' entries of the last run (path => entry), by mtime */
    private array $libraryCache = [];

    /**
     * The whole libraries (vendor/, the engine...) analyzed in the background, after the project: implementations
     * and subclasses of their classes, classmap classes, members without opening them first. For at most $budget
     * seconds; true when every file is done. A cache (<cache>.libraries) keeps them for the next start.
     */
    public function indexLibraries(float $budget): bool
    {
        $start = microtime(true);
        if ($this->libraryQueue === null) {
            $this->libraryCache = $this->loadLibraryCache();
            $queue = [];
            foreach ($this->libraries() as $library) {
                foreach ($this->libraryFiles($library['dir']) as $relative) {
                    $queue[$library['dir'] . '/' . $relative] = true;
                }
            }
            foreach ($this->classmap as [, $path]) {
                $queue[$path] = true;
            }
            $this->libraryQueue = array_keys($queue);
            $this->libraryTotal = count($this->libraryQueue);
        }
        while ($this->libraryQueue) {
            $path = array_pop($this->libraryQueue);
            if (!isset($this->files[$path]) && !isset($this->libraries[$path])) {
                $mtime = $this->libraryMtime($path);
                $cached = $this->libraryCache[$path] ?? null;
                if ($cached !== null && $mtime !== null && (float)($cached['mtime'] ?? -1) === $mtime) {     // JSON: 12.0 comes back as 12
                    $this->libraries[$path] = $cached;
                    $this->register($cached);
                } elseif ($this->loadLibraryFile($path) && $mtime !== null) {
                    $this->libraries[$path]['mtime'] = $mtime;
                }
            }
            if (microtime(true) - $start > $budget) {
                return false;
            }
        }
        if ($this->libraryCache !== [] || $this->libraryTotal > 0) {
            $this->libraryCache = [];
            $this->saveLibraryCache();
        }
        return true;
    }

    /** @return array{done: int, total: int} */
    public function libraryProgress(): array
    {
        return ['done' => $this->libraryTotal - count($this->libraryQueue ?? []), 'total' => $this->libraryTotal];
    }

    /** The libraries changed (composer install): listed and analyzed again. */
    public function invalidateLibraries(): void
    {
        foreach ([&$this->classes, &$this->functions] as &$names) {
            $names = array_filter($names, fn (string $path): bool => !isset($this->libraries[$path]));
        }
        unset($names);
        $this->libraryQueue = null;
        $this->libraryListing = [];
        $this->libraries = [];
        $this->psr4 = [];
        $this->stubsLoaded = false;
        $this->autoloadFilesLoaded = false;
    }

    private function libraryMtime(string $path): ?float
    {
        if (str_starts_with($path, '/')) {
            $t = @filemtime($path);
            return $t === false ? null : (float)$t;
        }
        return $this->workspace->mtime($path);
    }

    /** @return array<string, array<string, mixed>> */
    private function loadLibraryCache(): array
    {
        if ($this->cacheFile === null || !is_file($this->cacheFile . '.libraries')) {
            return [];
        }
        $data = json_decode((string)@file_get_contents($this->cacheFile . '.libraries'), true);
        return is_array($data) && ($data['version'] ?? 0) === self::VERSION && is_array($data['files'] ?? null) ? $data['files'] : [];
    }

    private function saveLibraryCache(): void
    {
        if ($this->cacheFile === null) {
            return;
        }
        @mkdir(dirname($this->cacheFile), 0777, true);
        @file_put_contents($this->cacheFile . '.libraries', json_encode(['version' => self::VERSION, 'files' => $this->libraries],
            JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE));
    }

    /** Analyzes one project file now (saved or opened). */
    public function update(string $path, ?string $content = null): void
    {
        if (!$this->workspace->exists($path)) {
            $this->forget($path);
            return;
        }
        $content ??= $this->workspace->read($path);
        $this->forget($path);
        $entry = Analyzer::analyze($content) + ['path' => $path, 'mtime' => $this->workspace->mtime($path) ?? 0.0];
        $this->files[$path] = $entry;
        $this->register($entry);
        $this->dirty = true;
    }

    public function forget(string $path): void
    {
        $entry = $this->files[$path] ?? null;
        if ($entry === null) {
            return;
        }
        unset($this->files[$path]);
        foreach (array_keys($entry['classes']) as $key) {
            if (($this->classes[$key] ?? null) === $path) {
                unset($this->classes[$key]);
            }
        }
        foreach (array_keys($entry['functions']) as $key) {
            if (($this->functions[$key] ?? null) === $path) {
                unset($this->functions[$key]);
            }
        }
        $this->dirty = true;
    }

    /** @param array<string, mixed> $entry */
    private function register(array $entry): void
    {
        foreach (array_keys($entry['classes']) as $key) {
            $this->classes[$key] = $entry['path'];
        }
        foreach (array_keys($entry['functions']) as $key) {
            $this->functions[$key] = $entry['path'];
        }
    }

    /** @return array<string, array<string, mixed>> */
    public function files(): array
    {
        return $this->files;
    }

    /** @return array<string, mixed>|null a project or library file's entry */
    public function file(string $path): ?array
    {
        return $this->files[$path] ?? $this->libraries[$path] ?? null;
    }

    /** @return array{0: string, 1: array<string, mixed>}|null [path, class] */
    public function findClass(string $fqn): ?array
    {
        $key = strtolower(ltrim($fqn, '\\'));
        if ($key === '') {
            return null;
        }
        if (!isset($this->classes[$key])) {
            $this->loadLibraryClass($fqn);
        }
        if (!isset($this->classes[$key]) && Builtins::classInfo($key) === null) {
            $this->loadStubs();
        }
        $path = $this->classes[$key] ?? null;
        $entry = $path !== null ? $this->file($path) : null;
        if ($entry === null || !isset($entry['classes'][$key])) {
            $builtin = Builtins::classInfo($key);
            return $builtin !== null ? ['', $builtin] : null;
        }
        return [$path, $entry['classes'][$key]];
    }

    /** @return array{0: string, 1: array<string, mixed>}|null [path, function] */
    public function findFunction(string $fqn): ?array
    {
        $key = strtolower(ltrim($fqn, '\\'));
        if (!isset($this->functions[$key]) && Builtins::functionInfo($key) === null) {
            $this->loadAutoloadFiles();
        }
        if (!isset($this->functions[$key]) && Builtins::functionInfo($key) === null) {
            $this->loadStubs();
        }
        $path = $this->functions[$key] ?? null;
        $entry = $path !== null ? $this->file($path) : null;
        if ($entry !== null && isset($entry['functions'][$key])) {
            return [$path, $entry['functions'][$key]];
        }
        $builtin = Builtins::functionInfo($key);
        return $builtin !== null ? ['', $builtin] : null;
    }

    /**
     * The class and its ancestors (parents, interfaces, traits), nearest first.
     *
     * @return list<array{0: string, 1: array<string, mixed>}>
     */
    public function hierarchy(string $fqn): array
    {
        $out = [];
        $queue = [$fqn];
        $seen = [];
        while ($queue && count($out) < 64) {
            $name = array_shift($queue);
            $key = strtolower($name);
            if (isset($seen[$key])) {
                continue;
            }
            $seen[$key] = true;
            $found = $this->findClass($name);
            if ($found === null) {
                continue;
            }
            $out[] = $found;
            array_push($queue, ...$found[1]['traits'], ...$found[1]['extends'], ...$found[1]['implements']);
        }
        return $out;
    }

    /** Whether $class is $ancestor or extends / implements it. */
    public function isA(string $class, string $ancestor): bool
    {
        $ancestor = strtolower($ancestor);
        foreach ($this->hierarchy($class) as [, $info]) {
            if (strtolower($info['name']) === $ancestor) {
                return true;
            }
        }
        return strtolower($class) === $ancestor;
    }

    /**
     * A member found in the class or its ancestors.
     *
     * @param 'methods'|'props'|'consts' $kind
     * @return array{0: string, 1: array<string, mixed>, 2: array<string, mixed>}|null [path, class, member]
     */
    public function findMember(string $class, string $kind, string $name): ?array
    {
        $key = $kind === 'methods' ? strtolower($name) : $name;
        foreach ($this->hierarchy($class) as [$path, $info]) {
            if (isset($info[$kind][$key])) {
                return [$path, $info, $info[$kind][$key]];
            }
        }
        return null;
    }

    /**
     * Every member of a class and its ancestors (the nearest wins).
     *
     * @param 'methods'|'props'|'consts' $kind
     * @return array<string, array{0: array<string, mixed>, 1: array<string, mixed>}> name => [member, its class]
     */
    public function members(string $class, string $kind): array
    {
        $out = [];
        foreach ($this->hierarchy($class) as [, $info]) {
            foreach ($info[$kind] as $name => $member) {
                $out[$name] ??= [$member, $info];
            }
        }
        return $out;
    }

    /** Classes whose ancestors include $fqn (implementations, subclasses), project and loaded libraries. */
    public function subclasses(string $fqn): array
    {
        $out = [];
        foreach ([$this->files, $this->libraries] as $entries) {
            foreach ($entries as $path => $entry) {
                foreach ($entry['classes'] as $class) {
                    if (strtolower($class['name']) !== strtolower($fqn) && $this->isA($class['name'], $fqn)) {
                        $out[] = [$path, $class];
                    }
                }
            }
        }
        return $out;
    }

    /**
     * Class names for completion and "go to class": project classes, then library classes found by their
     * file names under PSR-4 folders (not analyzed).
     *
     * @return list<array{name: string, path: ?string, kind: string}>
     */
    public function classNames(string $query = '', int $limit = 200): array
    {
        $out = [];
        $seen = [];
        $match = fn (string $fqn) => $query === '' || self::fuzzy($query, self::short($fqn)) !== null || stripos($fqn, $query) !== false;
        foreach ($this->files as $path => $entry) {
            foreach ($entry['classes'] as $key => $class) {
                if ($match($class['name'])) {
                    $out[] = ['name' => $class['name'], 'path' => $path, 'kind' => $class['kind']];
                    $seen[$key] = true;
                }
            }
        }
        foreach ($this->classmap as $key => [$fqn]) {
            if (!isset($seen[$key]) && $match($fqn)) {
                $seen[$key] = true;
                $out[] = ['name' => $fqn, 'path' => null, 'kind' => 'class'];
                if (count($out) >= $limit * 4) {
                    return $out;
                }
            }
        }
        foreach ($this->psr4 as $prefix => $dirs) {
            foreach ($dirs as $dir) {
                foreach ($this->libraryFiles($dir) as $relative) {
                    $fqn = $prefix . str_replace('/', '\\', substr($relative, 0, -4));
                    if (!isset($seen[strtolower($fqn)]) && $match($fqn)) {
                        $seen[strtolower($fqn)] = true;
                        $out[] = ['name' => $fqn, 'path' => null, 'kind' => 'class'];
                        if (count($out) >= $limit * 4) {
                            break 3;
                        }
                    }
                }
            }
        }
        return $out;
    }

    /** @return list<array<string, mixed>> the functions of the project, of its libraries (Composer "files") and of the stubs */
    public function functionList(): array
    {
        $this->loadAutoloadFiles();
        $this->loadStubs();
        $out = [];
        foreach ([$this->files, $this->libraries] as $entries) {
            foreach ($entries as $path => $entry) {
                foreach ($entry['functions'] as $function) {
                    $out[] = $function + ['path' => $path];
                }
            }
        }
        return $out;
    }

    public static function short(string $fqn): string
    {
        $pos = strrpos($fqn, '\\');
        return $pos === false ? $fqn : substr($fqn, $pos + 1);
    }

    /**
     * Fuzzy match as in "go to class": the query's letters in order, camel humps and word starts score best.
     * Null: no match; else a score (higher is better).
     */
    public static function fuzzy(string $query, string $text): ?int
    {
        if ($query === '') {
            return 0;
        }
        $q = strtolower($query);
        $t = strtolower($text);
        if (str_starts_with($t, $q)) {
            return 1000 - strlen($t);
        }
        $score = 0;
        $pos = 0;
        $length = strlen($t);
        $previous = -2;
        for ($i = 0, $n = strlen($q); $i < $n; $i++) {
            $found = strpos($t, $q[$i], $pos);
            if ($found === false) {
                return null;
            }
            $boundary = $found === 0 || ctype_upper($text[$found]) || !ctype_alnum($text[$found - 1]);
            $score += ($boundary ? 10 : 1) + ($found === $previous + 1 ? 5 : 0);
            $previous = $found;
            $pos = $found + 1;
        }
        return $score - intdiv($length, 4);
    }

    // libraries --------------------------------------------------------------------------------------------

    /** The PSR-4 prefixes of every vendor/composer/autoload_psr4.php in the project (read, never run). */
    private function findLibraries(): void
    {
        $this->psr4 = [];
        $this->packages = [];
        $this->classmap = [];
        $this->autoloadFiles = [];
        $this->autoloadFilesLoaded = false;
        $root = $this->workspace->localPath();
        foreach ($this->workspace->files() as $manifest => $meta) {
            if (basename($manifest) !== 'composer.json' || substr_count($manifest, '/') > 3) {
                continue;
            }
            $vendor = ltrim(dirname($manifest) === '.' ? 'vendor' : dirname($manifest) . '/vendor', '/');
            $path = "$vendor/composer/autoload_psr4.php";
            if (!$this->workspace->exists($path)) {
                continue;
            }
            $base = dirname($vendor) === '.' ? '' : dirname($vendor);
            $this->readComposerLists($vendor, $base);
            $this->readPackages($vendor, $root);
            $text = $this->workspace->read($path);
            if (!preg_match_all("/'((?:[^'\\\\]|\\\\.)*)'\s*=>\s*array\((.*?)\),?\n/s", $text, $entries, PREG_SET_ORDER)) {
                continue;
            }
            foreach ($entries as [, $prefix, $list]) {
                $prefix = stripcslashes($prefix);
                preg_match_all("/\\$(vendorDir|baseDir)\s*\.\s*'([^']*)'/", $list, $dirs, PREG_SET_ORDER);
                foreach ($dirs as [, $var, $sub]) {
                    $dir = ltrim(($var === 'vendorDir' ? $vendor : $base) . $sub, '/');
                    if ($root !== null) {
                        $real = realpath("$root/$dir");
                        if ($real !== false) {
                            $real = str_replace('\\', '/', $real);
                            $dir = str_starts_with($real, "$root/") ? substr($real, strlen($root) + 1) : $real;
                        }
                    }
                    $this->psr4[$prefix][] = $dir;
                }
            }
        }
        // a JumPHP project whose game has no vendor/ yet: the engine of the project itself
        if (!isset($this->psr4['JumPHP\\']) && $this->workspace->exists('engine/src/Kernel/Engine.php')) {
            $this->psr4['JumPHP\\'][] = 'engine/src';
        }
        foreach ($this->extraPsr4 as $prefix => $dirs) {
            if (!isset($this->psr4[$prefix])) {
                $this->psr4[$prefix] = $dirs;
            }
        }
        foreach ($this->psr4 as $prefix => $dirs) {
            $this->psr4[$prefix] = array_values(array_unique($dirs));
        }
        uksort($this->psr4, fn ($a, $b) => strlen($b) <=> strlen($a));
    }

    /** @var array<string, array{package: string, extension: bool, version: string}> package folder => what installed.json says of it */
    private array $packages = [];

    /** The packages of a vendor/composer/installed.json by their folder (the same spelling as the PSR-4 folders), to name the libraries. */
    private function readPackages(string $vendor, ?string $root): void
    {
        $path = "$vendor/composer/installed.json";
        $data = $this->workspace->exists($path) ? json_decode($this->workspace->read($path), true) : null;
        foreach (is_array($data) ? ($data['packages'] ?? $data) : [] as $p) {
            if (!is_array($p) || !is_string($p['name'] ?? null)) {
                continue;
            }
            $dir = ltrim("$vendor/composer/" . ($p['install-path'] ?? "../{$p['name']}"), '/');
            if ($root !== null && ($real = realpath("$root/$dir")) !== false) {
                $real = str_replace('\\', '/', $real);
                $dir = str_starts_with($real, "$root/") ? substr($real, strlen($root) + 1) : $real;
            } else {
                $dir = ltrim((string)preg_replace('#[^/]+/\.\./#', '', $dir), '/');
            }
            $this->packages[rtrim($dir, '/')] = ['package' => $p['name'], 'extension' => ($p['type'] ?? '') === 'jumphp-extension', 'version' => (string)($p['version'] ?? '')];
        }
    }

    /** @return array{package: string, extension: bool, version: string}|null the package a library folder is in (the longest folder wins) */
    private function packageOf(string $dir): ?array
    {
        $best = null;
        foreach ($this->packages as $folder => $info) {
            if (($dir === $folder || str_starts_with($dir, $folder . '/')) && ($best === null || strlen($folder) > strlen($best[0]))) {
                $best = [$folder, $info];
            }
        }
        return $best[1] ?? null;
    }

    /** Composer's autoload_classmap.php and autoload_files.php of a vendor/ folder (read, never run). */
    private function readComposerLists(string $vendor, string $base): void
    {
        $paths = function (string $file) use ($vendor, $base): array {
            $out = [];
            if (!$this->workspace->exists($file)) {
                return $out;
            }
            preg_match_all("/'((?:[^'\\\\]|\\\\.)*)'\s*=>\s*\\$(vendorDir|baseDir)\s*\.\s*'([^']*)'/", $this->workspace->read($file), $m, PREG_SET_ORDER);
            foreach ($m as [, $key, $var, $sub]) {
                $out[stripcslashes($key)] = $this->projectPath(ltrim(($var === 'vendorDir' ? $vendor : $base) . $sub, '/'));
            }
            return $out;
        };
        foreach ($paths("$vendor/composer/autoload_classmap.php") as $class => $path) {
            // an optimized autoloader lists every PSR-4 class too: those are found by their prefix anyway
            if (count($this->classmap) < 100000 && !str_starts_with($path, "$vendor/composer/")) {
                $this->classmap[strtolower($class)] ??= [$class, $path];
            }
        }
        foreach ($paths("$vendor/composer/autoload_files.php") as $path) {
            $this->autoloadFiles[] = $path;
        }
    }

    /** A path under the project, or absolute when a link leads out of it. */
    private function projectPath(string $dir): string
    {
        $root = $this->workspace->localPath();
        if ($root !== null) {
            $real = realpath("$root/$dir");
            if ($real !== false) {
                $real = str_replace('\\', '/', $real);
                return str_starts_with($real, "$root/") ? substr($real, strlen($root) + 1) : $real;
            }
        }
        return $dir;
    }

    /** Analyzes a library file once (classes and functions it declares). */
    private function loadLibraryFile(string $path): bool
    {
        if (isset($this->files[$path]) || isset($this->libraries[$path])) {
            return true;
        }
        $content = $this->libraryRead($path);
        if ($content === null) {
            return false;
        }
        $entry = Analyzer::analyze($content) + ['path' => $path, 'mtime' => 0.0];
        $entry['refs'] = [];
        $this->libraries[$path] = $entry;
        $this->register($entry);
        return true;
    }

    /** The libraries' functions (Composer "files"), analyzed when first needed. */
    private function loadAutoloadFiles(): void
    {
        if ($this->autoloadFilesLoaded) {
            return;
        }
        $this->autoloadFilesLoaded = true;
        if (!$this->psr4) {
            $this->findLibraries();
            $this->autoloadFilesLoaded = true;
        }
        foreach (array_slice($this->autoloadFiles, 0, 400) as $path) {
            $this->loadLibraryFile($path);
        }
    }

    private function loadLibraryClass(string $fqn): void
    {
        $fqn = ltrim($fqn, '\\');
        if (isset($this->classmap[strtolower($fqn)]) && $this->loadLibraryFile($this->classmap[strtolower($fqn)][1])) {
            return;
        }
        foreach ($this->psr4 as $prefix => $dirs) {
            if (!str_starts_with($fqn, $prefix)) {
                continue;
            }
            $relative = str_replace('\\', '/', substr($fqn, strlen($prefix))) . '.php';
            foreach ($dirs as $dir) {
                $path = "$dir/$relative";
                if (isset($this->files[$path]) || isset($this->libraries[$path])) {
                    continue;
                }
                $content = $this->libraryRead($path);
                if ($content === null) {
                    continue;
                }
                $entry = Analyzer::analyze($content) + ['path' => $path, 'mtime' => 0.0];
                unset($entry['refs']);
                $entry['refs'] = [];
                $this->libraries[$path] = $entry;
                $this->register($entry);
                return;
            }
        }
    }

    private function libraryRead(string $path): ?string
    {
        if (str_starts_with($path, '/')) {
            return is_file($path) ? (string)file_get_contents($path) : null;
        }
        try {
            return $this->workspace->exists($path) ? $this->workspace->read($path) : null;
        } catch (\Throwable) {
            return null;
        }
    }

    /**
     * The libraries the project's classes come from (PSR-4 folders outside its own sources): the engine, vendor/
     * packages, what the host added.
     *
     * @return list<array{prefix: string, dir: string, external: bool, package?: string, extension?: bool, version?: string}>
     */
    public function libraries(): array
    {
        if (!$this->psr4) {
            $this->findLibraries();
        }
        $out = [];
        foreach ($this->psr4 as $prefix => $dirs) {
            foreach ($dirs as $dir) {
                if (str_starts_with($prefix, 'App\\') || str_starts_with($prefix, 'Tests\\')) {
                    continue;
                }
                $package = $this->packageOf($dir);
                $out[] = ['prefix' => rtrim($prefix, '\\'), 'dir' => $dir, 'external' => str_starts_with($dir, '/')]
                    + ($package !== null ? ['package' => $package['package'], 'extension' => $package['extension'], 'version' => $package['version']] : []);
            }
        }
        usort($out, fn ($a, $b) => [!str_starts_with($a['prefix'], 'JumPHP'), $a['prefix']] <=> [!str_starts_with($b['prefix'], 'JumPHP'), $b['prefix']]);
        return $out;
    }

    /** @return list<string> the PHP files of a library folder (one of libraries()), relative to it */
    public function libraryListing(string $dir): array
    {
        foreach ($this->psr4 as $dirs) {
            if (in_array($dir, $dirs, true)) {
                return $this->libraryFiles($dir);
            }
        }
        return [];
    }

    /** @var array<string, list<string>> */
    private array $libraryListing = [];

    /** @return list<string> PHP files under a PSR-4 folder, relative to it */
    private function libraryFiles(string $dir): array
    {
        if (isset($this->libraryListing[$dir])) {
            return $this->libraryListing[$dir];
        }
        $key = $dir;
        $out = [];
        $root = $this->workspace->localPath();
        $full = str_starts_with($dir, '/') ? $dir : ($root !== null ? "$root/$dir" : null);
        if ($full !== null) {
            $dir = $full;
            if (is_dir($dir)) {
                $it = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($dir, \FilesystemIterator::SKIP_DOTS));
                foreach ($it as $file) {
                    if ($file->isFile() && $file->getExtension() === 'php' && count($out) < 20000) {
                        $out[] = str_replace('\\', '/', substr($file->getPathname(), strlen($dir) + 1));
                    }
                }
            }
        }
        if ($full === null) {
            // a workspace without local files (the web IDE, tests): its own listing, vendor/ included
            foreach (array_keys($this->workspace->files(true)) as $path) {
                if (str_starts_with($path, "$key/") && str_ends_with($path, '.php') && count($out) < 20000) {
                    $out[] = substr($path, strlen($key) + 1);
                }
            }
        }
        return $this->libraryListing[$key] = array_values(array_filter($out, fn ($f) => preg_match('#^[A-Z][\w/]*\.php$#', $f) === 1));
    }

    // cache --------------------------------------------------------------------------------------------------

    private function load(): void
    {
        if ($this->cacheFile === null || !is_file($this->cacheFile)) {
            return;
        }
        $data = json_decode((string)@file_get_contents($this->cacheFile), true);
        if (!is_array($data) || ($data['version'] ?? 0) !== self::VERSION || !is_array($data['files'] ?? null)) {
            return;
        }
        foreach ($data['files'] as $path => $entry) {
            $this->files[$path] = $entry;
            $this->register($entry);
        }
    }

    public function save(): void
    {
        $this->dirty = false;
        if ($this->cacheFile === null) {
            return;
        }
        @mkdir(dirname($this->cacheFile), 0777, true);
        @file_put_contents($this->cacheFile, json_encode(['version' => self::VERSION, 'files' => $this->files], JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE));
    }
}
