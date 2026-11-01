<?php

namespace JumPHP\Ide\Run;

use JumPHP\Ide\Async\Environment;
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

    /** @param callable(string $event, array $payload): void $emit */
    public function __construct(private Processes $processes, private string $root, private $emit)
    {
    }

    /**
     * @param array<string, mixed> $config a configuration of ProjectInfo::runConfigurations()
     * @param array<string, string> $env
     * @return array{id: int, label: string}
     */
    public function start(array $config, array $env = []): array
    {
        $command = $config['command'];
        if (in_array('bin/jumphp', $command, true) || str_contains(implode(' ', $command), 'bin/jumphp ')) {
            if (Environment::which('docker') === null) {
                throw new \RuntimeException("Docker was not found: bin/jumphp runs everything in Docker. Install Docker (https://docs.docker.com/engine/install/), "
                    . "or if it is installed somewhere unusual, start the IDE from a terminal where `docker` works. Searched: " . Environment::path());
            }
        }
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
