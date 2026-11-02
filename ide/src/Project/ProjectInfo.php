<?php

namespace JumPHP\Ide\Project;

use JumPHP\Ide\Workspace\Workspace;

/**
 * What kind of project this is and what can be run in it. A JumPHP project (bin/jumphp + games/) holds games and
 * desktop apps (games/<id>/config/game.yaml: `ui: webview` = an app, `ui: hybrid` = a game with HTML windows); a
 * game's BRIEF.md tells how it was made (an original game, the remake of an Amstrad CPC game, a port).
 */
final class ProjectInfo
{
    public const KINDS = [
        'game' => 'Game',
        'app' => 'Desktop app (HTML + PHP)',
        'gui' => 'Graphic app (forms, no HTML)',
        'hybrid' => 'Hybrid game (SDL + HTML windows)',
        'cpc' => 'Amstrad CPC remake',
        'port' => 'Port of an open-source game',
        'php' => 'PHP project',
    ];

    public function __construct(private Workspace $workspace)
    {
    }

    /** @return array<string, mixed> */
    public function describe(): array
    {
        $jumphp = $this->workspace->exists('bin/jumphp');
        $modules = [];
        $files = $this->workspace->files();
        foreach ($files as $path => $meta) {
            if (preg_match('#^games/([a-z0-9][a-z0-9-]*)/config/game\.yaml$#', $path, $m)) {
                $modules[] = $this->module($m[1], "games/{$m[1]}");
            }
        }
        $linked = !$jumphp && $this->workspace->exists('config/game.yaml');
        if ($linked) {
            // a game in its own repository (composer.json -> ../../engine): run through the IDE's copy of JumPHP
            $id = preg_match('/^\s*id:\s*["\']?([a-z0-9][a-z0-9-]*)/m', $this->workspace->read('config/game.yaml'), $m) ? $m[1]
                : (trim((string)preg_replace('/[^a-z0-9]+/', '-', strtolower($this->workspace->name())), '-') ?: 'game');
            $modules[] = $this->module($id, '');
        }
        $version = $this->workspace->exists('VERSION') ? trim($this->workspace->read('VERSION')) : null;
        return [
            'name' => $this->workspace->name(),
            'jumphp' => $jumphp,
            'version' => $version,
            'modules' => $modules,
            'kind' => $modules[0]['kind'] ?? ($this->workspace->exists('composer.json') ? 'php' : 'php'),
            'composer' => $this->workspace->exists('composer.json'),
            'linked' => $linked,
            'run' => $linked ? self::linkedConfigurations($this->runConfigurations(true, $modules)) : $this->runConfigurations($jumphp, $modules),
        ];
    }

    /** @return array<string, mixed> a game or app folder */
    private function module(string $id, string $dir): array
    {
        $prefix = $dir === '' ? '' : "$dir/";
        $yaml = $this->workspace->read($prefix . 'config/game.yaml');
        $get = fn (string $key) => preg_match('/^\s*' . $key . ':\s*["\']?([^"\'#\n]*?)["\']?\s*(?:#.*)?$/m', $yaml, $m) ? trim($m[1]) : null;
        $ui = $get('ui');
        $kind = $ui === 'webview' ? 'app' : ($ui === 'hybrid' ? 'hybrid' : ($get('kind') === 'gui' ? 'gui' : 'game'));
        $brief = $this->workspace->exists($prefix . 'BRIEF.md') ? $this->workspace->read($prefix . 'BRIEF.md') : '';
        if ($kind === 'game' && preg_match('/\b(amstrad|cpc|\.dsk|dsk2jumphp)\b/i', $brief)) {
            $kind = 'cpc';
        } elseif ($kind === 'game' && preg_match('/\b(kind|type)\s*:\s*port\b|\bport of\b/i', $brief)) {
            $kind = 'port';
        }
        $pages = null;
        if ($kind === 'app' || $kind === 'hybrid') {
            $pages = $prefix . (preg_match('/^\s*pages:\s*(\S+)/m', $yaml, $m) ? trim($m[1], '"\'') : 'ui');
        }
        return [
            'id' => $id, 'dir' => $dir, 'title' => $get('title') ?? $id, 'kind' => $kind, 'kindLabel' => self::KINDS[$kind],
            'startScene' => $get('start_scene'), 'width' => (int)($get('width') ?? 320), 'height' => (int)($get('height') ?? 180),
            'forms' => $kind === 'gui' ? array_values(array_filter(array_keys($this->workspace->files()), fn ($p) => str_starts_with($p, $prefix . 'forms/') && str_ends_with($p, '.form.yaml'))) : null,
            'pages' => $pages, 'site' => $this->workspace->exists($prefix . 'site/index.html') ? $prefix . 'site' : null,
            'hasTests' => (bool)array_filter(array_keys($this->workspace->files()), fn ($p) => str_starts_with($p, $prefix . 'tests/') && str_ends_with($p, 'Test.php')),
        ];
    }

    /**
     * What the Run menu offers: id, label, group, the command (relative to the project root) and what its output
     * means (a test report, a screenshot path on the last line...).
     *
     * @param list<array<string, mixed>> $modules
     * @return list<array<string, mixed>>
     */
    public function runConfigurations(bool $jumphp, array $modules): array
    {
        $configs = [];
        if ($jumphp) {
            foreach ($modules as $m) {
                $id = $m['id'];
                $app = $m['kind'] === 'app';
                $configs[] = ['id' => "run:$id", 'label' => "Run $id", 'group' => $id, 'icon' => 'play', 'command' => ['bin/jumphp', $app ? 'app:run' : 'run', $id], 'kind' => 'run'];
                $configs[] = ['id' => "test:$id", 'label' => "Test $id", 'group' => $id, 'icon' => 'test', 'command' => ['bin/jumphp', $app ? 'app:test' : 'test', $id], 'kind' => 'test'];
                $configs[] = ['id' => "shot:$id", 'label' => "Screenshot $id", 'group' => $id, 'icon' => 'camera', 'command' => ['bin/jumphp', $app ? 'app:shot' : 'shot', $id], 'kind' => 'shot'];
                if (!$app) {
                    $configs[] = ['id' => "build:$id", 'label' => "Build $id for the browser", 'group' => $id, 'icon' => 'globe', 'command' => ['bin/jumphp', 'build', $id], 'kind' => 'build'];
                    $configs[] = ['id' => "webshot:$id", 'label' => "Browser check $id", 'group' => $id, 'icon' => 'globe', 'command' => ['bin/jumphp', 'webshot', $id, '6', '[[1,"Enter"]]'], 'kind' => 'webshot'];
                    $configs[] = ['id' => "serve:$id", 'label' => "Serve $id on localhost:8080", 'group' => $id, 'icon' => 'globe', 'command' => ['bin/jumphp', 'serve', $id], 'kind' => 'serve', 'url' => 'http://localhost:8080'];
                    $configs[] = ['id' => "standalone:$id", 'label' => "Executable of $id (Linux)", 'group' => $id, 'icon' => 'package', 'command' => ['bin/jumphp', 'export:standalone', $id], 'kind' => 'build'];
                    $configs[] = ['id' => "standalone-windows:$id", 'label' => "Executable of $id (Windows)", 'group' => $id, 'icon' => 'package', 'command' => ['bin/jumphp', 'export:standalone', $id, '--windows'], 'kind' => 'build'];
                    $configs[] = ['id' => "macos:$id", 'label' => "Mac app of $id (built on GitHub)", 'group' => $id, 'icon' => 'package', 'command' => ['bin/jumphp', 'macos', $id], 'kind' => 'build'];
                    $configs[] = ['id' => "ios:$id", 'label' => "iPhone / iPad app of $id (built on GitHub)", 'group' => $id, 'icon' => 'package', 'command' => ['bin/jumphp', 'ios', $id], 'kind' => 'build'];
                    $configs[] = ['id' => "android:$id", 'label' => "Android app of $id (built on GitHub)", 'group' => $id, 'icon' => 'package', 'command' => ['bin/jumphp', 'android', $id], 'kind' => 'build'];
                    $configs[] = ['id' => "android-local:$id", 'label' => "Android app of $id (built here)", 'group' => $id, 'icon' => 'package', 'command' => ['bin/jumphp', 'android', $id, '--local'], 'kind' => 'build'];
                    if (!$m['site']) {
                        $configs[] = ['id' => "site-new:$id", 'label' => "Make the web site of $id", 'group' => $id, 'icon' => 'globe', 'command' => ['bin/jumphp', 'site:new', $id], 'kind' => 'build'];
                    }
                } else {
                    $configs[] = ['id' => "export:$id", 'label' => "Executable of $id (Linux)", 'group' => $id, 'icon' => 'package', 'command' => ['bin/jumphp', 'app:export', $id], 'kind' => 'build'];
                    $configs[] = ['id' => "export-windows:$id", 'label' => "Executable of $id (Windows)", 'group' => $id, 'icon' => 'package', 'command' => ['bin/jumphp', 'app:export', $id, '--windows'], 'kind' => 'build'];
                    $configs[] = ['id' => "export-macos:$id", 'label' => "Mac app of $id", 'group' => $id, 'icon' => 'package', 'command' => ['bin/jumphp', 'app:export', $id, '--macos'], 'kind' => 'build'];
                }
                $configs[] = ['id' => "install:$id", 'label' => "composer install ($id)", 'group' => $id, 'icon' => 'package', 'command' => ['bin/jumphp', 'install', $id], 'kind' => 'tool'];
                if ($m['site']) {
                    $configs[] = ['id' => "siteshot:$id", 'label' => "Check the site of $id", 'group' => $id, 'icon' => 'globe', 'command' => ['sh', '-c', "bin/jumphp build:site $id && bin/jumphp siteshot $id"], 'kind' => 'shot'];
                }
            }
            $configs[] = ['id' => 'lint', 'label' => 'Lint (php -l + forbidden words)', 'group' => 'Project', 'icon' => 'check', 'command' => ['bin/jumphp', 'lint'], 'kind' => 'tool'];
            if ($this->workspace->exists('engine/composer.json')) {
                $configs[] = ['id' => 'test:engine', 'label' => 'Engine tests', 'group' => 'Project', 'icon' => 'test', 'command' => ['bin/jumphp', 'test', 'engine'], 'kind' => 'test'];
            }
            $configs[] = ['id' => 'update', 'label' => 'Update JumPHP', 'group' => 'Project', 'icon' => 'download', 'command' => ['bin/jumphp', 'update'], 'kind' => 'tool'];
        } elseif ($this->workspace->exists('composer.json')) {
            $configs[] = ['id' => 'composer:install', 'label' => 'composer install', 'group' => 'Project', 'icon' => 'package', 'command' => ['composer', 'install'], 'kind' => 'tool'];
            if ($this->workspace->exists('vendor/bin/phpunit') || $this->workspace->exists('phpunit.xml') || $this->workspace->exists('phpunit.xml.dist')) {
                $configs[] = ['id' => 'phpunit', 'label' => 'PHPUnit', 'group' => 'Project', 'icon' => 'test', 'command' => ['vendor/bin/phpunit', '--testdox'], 'kind' => 'test'];
            }
            $configs[] = ['id' => 'server', 'label' => 'PHP server on localhost:8000', 'group' => 'Project', 'icon' => 'globe', 'command' => ['php', '-S', 'localhost:8000', '-t', $this->workspace->exists('public/index.php') ? 'public' : '.'], 'kind' => 'serve', 'url' => 'http://localhost:8000'];
        }
        foreach ($this->custom() as $i => $custom) {
            $configs[] = $custom + ['id' => "custom:$i", 'group' => 'Custom', 'icon' => 'play', 'kind' => 'tool'];
        }
        return $configs;
    }

    /**
     * A game in its own repository: the commands of a JumPHP project, run by the IDE's copy of JumPHP (Runner: `@jumphp`
     * = its bin/jumphp, the game linked into its games/). What only a whole JumPHP project has is left out.
     *
     * @param list<array<string, mixed>> $configs
     * @return list<array<string, mixed>>
     */
    public static function linkedConfigurations(array $configs): array
    {
        $out = [];
        foreach ($configs as $c) {
            if (in_array($c['id'], ['lint', 'test:engine', 'update'], true) || str_starts_with((string)$c['id'], 'custom:')) {
                continue;
            }
            $c['command'] = array_map(fn (string $arg) => str_replace('bin/jumphp', '@jumphp', $arg), $c['command']);
            $c['game'] = $c['group'];
            $out[] = $c;
        }
        $out[] = ['id' => 'update', 'label' => 'Update JumPHP (the IDE\'s copy)', 'group' => 'Project', 'icon' => 'download', 'command' => ['@jumphp-update'], 'kind' => 'tool'];
        return $out;
    }

    /** Run configurations the user added (.jumphp/run.json: [{label, command: [...]}]). @return list<array<string, mixed>> */
    private function custom(): array
    {
        if (!$this->workspace->exists('.jumphp/run.json')) {
            return [];
        }
        $list = json_decode($this->workspace->read('.jumphp/run.json'), true);
        return is_array($list) ? array_values(array_filter($list, fn ($c) => is_array($c) && isset($c['label'], $c['command']) && is_array($c['command']))) : [];
    }

    /**
     * Test results read from a run's output (PHPUnit --testdox lines, bin/jumphp's steps): suites with their tests.
     *
     * @return array<string, mixed> {suites: [{name, tests: [{name, status, message}]}], passed, failed, skipped}
     */
    public static function parseTests(string $output): array
    {
        /** @var list<array{name: string, tests: list<array{name: string, status: string, message: string}>}> $suites */
        $suites = [];
        $current = null;
        $last = null;
        $counts = ['passed' => 0, 'failed' => 0, 'skipped' => 0];
        $output = preg_replace('/\e\[[0-9;]*m/', '', $output) ?? $output;
        foreach (explode("\n", $output) as $line) {
            $line = rtrim($line, "\r");
            if (preg_match('/^(\S.*?)\s*\((?:[\w\\\\]+)\)\s*$/u', $line, $m) || preg_match('/^([A-Z][\w ]+[a-z])\s*$/u', $line, $m)) {
                $suites[] = ['name' => $m[1], 'tests' => []];
                $current = count($suites) - 1;
                $last = null;
            } elseif ($current !== null && preg_match('/^\s+([✔✘↩∅⚠]|\[x\]|\[ \])\s+(.*)$/u', $line, $m)) {
                $status = match ($m[1]) {
                    '✔', '[x]' => 'passed', '✘', '[ ]' => 'failed', default => 'skipped',
                };
                $counts[$status]++;
                $suites[$current]['tests'][] = ['name' => $m[2], 'status' => $status, 'message' => ''];
                $last = count($suites[$current]['tests']) - 1;
            } elseif ($current !== null && $last !== null && preg_match('/^\s+│\s?(.*)$/u', $line, $m)) {
                $test = &$suites[$current]['tests'][$last];
                $test['message'] = ltrim($test['message'] . "\n" . $m[1]);
                unset($test);
            }
        }
        return ['suites' => array_values(array_filter($suites, fn (array $s): bool => $s['tests'] !== [])), ...$counts];
    }
}
