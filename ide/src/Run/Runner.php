<?php

namespace JumPHP\Ide\Run;

use JumPHP\Ide\Async\Environment;
use JumPHP\Ide\Async\Wsl;
use JumPHP\Ide\Async\Processes;
use JumPHP\Ide\Project\ProjectInfo;

/**
 * Runs the project's run configurations (bin/jumphp run / test / shot...) and streams their output to the
 * interface: events run.output {id, chunk}, run.exit {id, code, seconds, tests?, image?}.
 */
final class Runner
{
    /** @var array<int, array<string, mixed>> */
    private array $runs = [];

    /** where the IDE keeps its copy of JumPHP (games kept in their own repository run through it), and its repository */
    private string $toolkit;
    private string $toolkitRepo = 'https://github.com/davidnurdin/jumPhp.git';

    /** @param callable(string $event, array $payload): void $emit */
    /** @var (\Closure(): string)|null how bin/jumphp reaches Docker ('native', or 'wsl': Docker::via()) */
    public ?\Closure $dockerVia = null;

    public function __construct(private Processes $processes, private string $root, private $emit, private string $os = PHP_OS_FAMILY)
    {
        $this->toolkit = (getenv('HOME') ?: sys_get_temp_dir()) . '/.jumphp/toolkit';
    }

    public function setToolkit(string $dir, ?string $repo = null): void
    {
        $this->toolkit = rtrim($dir, '/');
        $this->toolkitRepo = $repo ?? $this->toolkitRepo;
    }

    /**
     * A command of a game kept in its own repository (`@jumphp` = bin/jumphp): through the IDE's copy of JumPHP, fetched
     * the first time, the game linked as its games/<id> (bin/jumphp mounts it in the containers).
     *
     * @param list<string> $command
     * @return list<string>
     */
    public function linkedCommand(array $command, string $game): array
    {
        $tk = escapeshellarg($this->toolkit);
        $fetch = 'if [ ! -x "$tk/bin/jumphp" ]; then echo "Getting JumPHP for this game (once): ' . $this->toolkitRepo . '"; mkdir -p "$(dirname "$tk")"; '
            . 'git clone --depth 1 ' . escapeshellarg($this->toolkitRepo) . ' "$tk"; fi';
        if ($command === ['@jumphp-update']) {
            return ['sh', '-c', "set -e; tk=$tk; $fetch; git -C \"\$tk\" pull --ff-only"];
        }
        $args = ($command[0] ?? '') === 'sh' && ($command[1] ?? '') === '-c'
            ? str_replace('@jumphp', '"$tk/bin/jumphp"', (string)($command[2] ?? ''))      // a script of several commands
            : 'exec ' . implode(' ', array_map(fn (string $a) => $a === '@jumphp' ? '"$tk/bin/jumphp"' : escapeshellarg($a), $command));
        $link = 'mkdir -p "$tk/games"; ln -sfn ' . escapeshellarg($this->root) . ' "$tk/games/' . $game . '"';
        return ['sh', '-c', "set -e; tk=$tk; $fetch; $link; $args"];
    }

    /**
     * Windows: bin/jumphp and shell scripts through Git for Windows' bash (bin/jumphp then hops into WSL itself when
     * Docker is there); without Git for Windows but with Docker in WSL, the command runs inside WSL directly.
     *
     * @param list<string> $command
     * @return list<string>
     */
    public static function windowsCommand(array $command, string $root, string $os, bool $wsl, ?string $bash): array
    {
        if ($os === 'Windows' && $wsl && $bash === null) {
            return Wsl::command($command, $root, Environment::which('wsl') ?? 'wsl.exe');
        }
        return Environment::runnable($command, $root, $os, $bash);
    }

    /**
     * @param array<string, mixed> $config a configuration of ProjectInfo::runConfigurations()
     * @param array<string, string> $env
     * @return array{id: int, label: string}
     */
    public function start(array $config, array $env = []): array
    {
        $command = $config['command'];
        $wsl = $this->dockerVia !== null && ($this->dockerVia)() === 'wsl';
        if ($wsl) {
            // Docker Engine inside WSL: bin/jumphp goes there itself (JUMPHP_DOCKER=wsl skips its check), the JUMPHP_*
            // variables through WSLENV
            $env['JUMPHP_DOCKER'] = 'wsl';
            $env['WSLENV'] = Wsl::wslenv($env + ['JUMPHP_IDE' => '1'], (string)getenv('WSLENV'));
        }
        if (isset($config['game']) || in_array('@jumphp-update', $command, true)) {
            $command = $this->linkedCommand($command, preg_replace('/[^a-z0-9-]/', '', (string)($config['game'] ?? 'game')) ?: 'game');
            if (!$wsl && Environment::which('docker') === null) {
                throw new \RuntimeException('Docker was not found: bin/jumphp runs everything in Docker. Install Docker (https://docs.docker.com/engine/install/).');
            }
        } elseif (in_array('bin/jumphp', $command, true) || str_contains(implode(' ', $command), 'bin/jumphp ')) {
            if (!$wsl && Environment::which('docker') === null) {
                throw new \RuntimeException("Docker was not found: bin/jumphp runs everything in Docker. Install Docker (https://docs.docker.com/engine/install/), "
                    . "or if it is installed somewhere unusual, start the IDE from a terminal where `docker` works. Searched: " . Environment::path());
            }
        }
        $command = self::windowsCommand($command, $this->root, $this->os, $wsl, $this->os === 'Windows' ? Environment::gitBash() : null);
        if (is_file('/usr/bin/setsid') || is_file('/bin/setsid')) {
            // its own process group: stop() ends docker and what it started too
            array_unshift($command, 'setsid');
        }
        $id = 0;
        $started = $this->processes->start($command, $this->root, $env + ['JUMPHP_IDE' => '1', 'TERM' => 'dumb'],
            function (string $chunk) use (&$id): void {
                ($this->emit)('run.output', ['id' => $id, 'chunk' => $chunk]);
            });
        $id = $started['id'];
        $this->runs[$id] = ['config' => $config, 'started' => microtime(true)];
        $started['done']->then(function (array $result) use ($id, $config): void {
            $payload = ['id' => $id, 'code' => $result['code'], 'seconds' => $result['seconds'], 'label' => $config['label']];
            if (($config['kind'] ?? '') === 'test') {
                $payload['tests'] = ProjectInfo::parseTests($result['output']);
            }
            if (in_array($config['kind'] ?? '', ['shot', 'webshot'], true)) {
                $payload['image'] = $this->screenshot($result['output']);
            }
            unset($this->runs[$id]);
            ($this->emit)('run.exit', $payload);
        }, function (\Throwable $e) use ($id): void {
            unset($this->runs[$id]);
            ($this->emit)('run.exit', ['id' => $id, 'code' => -1, 'seconds' => 0, 'error' => $e->getMessage()]);
        });
        return ['id' => $id, 'label' => $config['label']];
    }

    public function stop(int $id): bool
    {
        $stopped = $this->processes->stop($id);
        // a second click on stop: kill
        if (isset($this->runs[$id]['stopping'])) {
            $this->processes->stop($id, true);
        }
        if (isset($this->runs[$id])) {
            $this->runs[$id]['stopping'] = true;
        }
        return $stopped;
    }

    /** @return list<array{id: int, label: string, seconds: float}> */
    public function running(): array
    {
        $out = [];
        foreach ($this->runs as $id => $run) {
            $out[] = ['id' => $id, 'label' => $run['config']['label'], 'seconds' => round(microtime(true) - $run['started'], 1)];
        }
        return $out;
    }

    /** The PNG a shot printed (its path, usually on the last line), as a project path or an absolute one. */
    private function screenshot(string $output): ?string
    {
        $output = preg_replace('/\e\[[0-9;]*m/', '', $output) ?? $output;
        if (preg_match_all('#(\S+\.png)\b#', $output, $m)) {
            foreach (array_reverse($m[1]) as $path) {
                $path = trim($path, "'\"():");
                foreach ([$path, "$this->root/$path"] as $candidate) {
                    if (is_file($candidate)) {
                        $real = (string)realpath($candidate);
                        return str_starts_with($real, $this->root . '/') ? substr($real, strlen($this->root) + 1) : $real;
                    }
                }
                if (str_starts_with($path, '/workspace/')) {
                    $local = substr($path, 11);
                    if (is_file("$this->root/$local")) {
                        return $local;
                    }
                }
            }
        }
        return null;
    }
}
