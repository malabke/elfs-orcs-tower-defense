<?php

namespace JumPHP\Ide\Async;

/**
 * Docker for a JumPHP project (everything runs in it through bin/jumphp), made ready in the background: when the
 * project opens, `docker info` is asked; when Docker does not answer, Docker Desktop is started (Windows, macOS, and
 * Linux when it is installed) and waited for, without blocking anything. The interface shows it in the status bar
 * (event docker.state) and the runs that need Docker wait for ensure().
 *
 * States: unknown, checking, starting (Docker Desktop launched, waiting for it), ready, wsl (Windows: Docker Engine
 * inside WSL answers, bin/jumphp runs there: Wsl, docs/windows-wsl.md), missing (no docker command), failed (it did not
 * answer in time: message says what to do).
 */
final class Docker
{
    private const CHECK_TIMEOUT = 20.0;     // one `docker info` at most
    private const START_TIMEOUT = 240.0;    // Docker Desktop's start at most
    private const POLL = 2.0;

    private string $state = 'unknown';
    private string $message = '';
    private ?Deferred $ready = null;
    private ?int $check = null;
    private float $checkStarted = 0.0;
    private float $startedAt = 0.0;
    private float $nextCheck = 0.0;
    private bool $launched = false;
    private bool $triedWsl = false;

    /**
     * @param callable(string $event, array $payload): void $emit
     * @param list<string>|null $launch the command that starts Docker Desktop (null: this system's; tests give one)
     */
    public function __construct(private Processes $processes, private string $root, private $emit, private string $os = PHP_OS_FAMILY,
        private ?array $launch = null)
    {
    }

    public function state(): array
    {
        return ['state' => $this->state, 'message' => $this->message];
    }

    /** How bin/jumphp reaches Docker: 'wsl' when only WSL's Docker answers, else 'native'. */
    public function via(): string
    {
        return $this->state === 'wsl' ? 'wsl' : 'native';
    }

    /** Resolves with state() once Docker answers, or once it is known that it will not (missing, failed). */
    public function ensure(): Deferred
    {
        if ($this->state === 'ready' || $this->state === 'wsl') {
            return Deferred::resolved($this->state());
        }
        if ($this->ready !== null) {
            return $this->ready;
        }
        $ready = $this->ready = new Deferred();
        $this->launched = false;
        $this->triedWsl = false;
        $this->startedAt = 0.0;
        $this->set('checking', 'Checking that Docker is running…');
        $this->ask();            // may settle it at once (no docker command)
        return $ready;
    }

    /** The background work: the running `docker info`, the next one while Docker Desktop starts. */
    public function tick(): void
    {
        $now = microtime(true);
        if ($this->check !== null && $now - $this->checkStarted > self::CHECK_TIMEOUT) {
            $this->processes->stop($this->check, true);     // a Docker that hangs: counted as not answering
        }
        if ($this->check === null && $this->state === 'starting' && $now >= $this->nextCheck) {
            if ($now - $this->startedAt > self::START_TIMEOUT) {
                $this->finish('failed', 'Docker Desktop did not start in time: open it yourself, wait until it says it is running, then run again.');
                return;
            }
            $this->ask();
        }
    }

    private function ask(): void
    {
        // Windows: Docker Desktop's own docker.exe when its folder is not in the PATH the IDE got
        $docker = Environment::which('docker') ?? (($exe = self::windowsDesktop()) !== null && is_file($cli = dirname($exe) . '\\resources\\bin\\docker.exe') ? $cli : null);
        if ($docker === null) {
            if ($this->askWsl()) {
                return;
            }
            $this->missing();
            return;
        }
        $started = $this->processes->start([$docker, 'info', '--format', '{{.ServerVersion}}'], $this->root);
        $this->check = $started['id'];
        $this->checkStarted = microtime(true);
        $started['done']->then(fn (array $r) => $this->answered($r['code'] === 0 ? trim($r['stdout']) : null, $r['stderr'] ?? ''),
            fn () => $this->answered(null, ''));
    }

    private function answered(?string $version, string $error): void
    {
        $this->check = null;
        if ($version !== null && $version !== '') {
            $this->finish('ready', "Docker $version is running");
            return;
        }
        if ($this->state === 'starting') {
            $this->nextCheck = microtime(true) + self::POLL;
            return;
        }
        if (stripos($error, 'permission denied') !== false) {
            $this->finish('failed', 'Docker refuses this user: add it to the docker group (sudo usermod -aG docker $USER), log in again, then reopen the project.');
            return;
        }
        if ($this->askWsl()) {
            return;
        }
        if (!$this->launched && $this->launch()) {
            $this->launched = true;
            $this->startedAt = microtime(true);
            $this->nextCheck = microtime(true) + self::POLL;
            $this->set('starting', 'Starting Docker Desktop…');
            return;
        }
        $this->finish('failed', $this->os === 'Linux'
            ? 'Docker does not answer: start it (sudo systemctl start docker, or Docker Desktop), then run again.'
            : 'Docker does not answer: start Docker Desktop, wait until it says it is running, then run again.');
    }

    /**
     * Windows: `wsl -e docker info`, once per ensure(), before Docker Desktop is started. False when there is no WSL
     * (or not on Windows): the caller goes on as before.
     */
    private function askWsl(): bool
    {
        if ($this->os !== 'Windows' || $this->triedWsl || $this->state === 'starting' || ($wsl = Environment::which('wsl')) === null) {
            return false;
        }
        $this->triedWsl = true;
        $started = $this->processes->start([$wsl, '-e', 'docker', 'info', '--format', '{{.ServerVersion}}'], $this->root);
        $this->check = $started['id'];
        $this->checkStarted = microtime(true);
        $started['done']->then(fn (array $r) => $this->answeredWsl($r['code'] === 0 ? trim($r['stdout']) : null),
            fn () => $this->answeredWsl(null));
        return true;
    }

    private function answeredWsl(?string $version): void
    {
        $this->check = null;
        if ($version !== null && $version !== '' && preg_match('/^[\w.+-]+$/', $version)) {
            $this->finish('wsl', "Docker $version is running in WSL: bin/jumphp runs there");
            return;
        }
        // no Docker in WSL either: what the Windows Docker check would have done
        if (Environment::which('docker') === null) {
            $this->missing();
            return;
        }
        $this->answered(null, '');
    }

    /** Starts Docker Desktop, detached (it outlives the IDE). False when there is none to start. */
    private function launch(): bool
    {
        $command = $this->launch ?? match ($this->os) {
            'Windows' => ($exe = self::windowsDesktop()) !== null ? ['cmd', '/c', 'start', '', '/min', $exe] : null,
            'Darwin' => is_dir('/Applications/Docker.app') ? ['open', '-g', '-a', 'Docker'] : null,
            default => Environment::which('systemctl') !== null && is_file('/usr/share/applications/docker-desktop.desktop')
                ? ['systemctl', '--user', 'start', 'docker-desktop'] : null,
        };
        if ($command === null) {
            return false;
        }
        try {
            $this->processes->start($command, $this->root);
        } catch (\Throwable) {
            return false;
        }
        return true;
    }

    private static function windowsDesktop(): ?string
    {
        foreach ([getenv('ProgramW6432'), getenv('ProgramFiles'), 'C:\\Program Files'] as $dir) {
            if (is_string($dir) && $dir !== '' && is_file($exe = $dir . '\\Docker\\Docker\\Docker Desktop.exe')) {
                return $exe;
            }
        }
        return null;
    }

    private function missing(): void
    {
        $this->finish('missing', 'Docker is not installed: JumPHP runs everything in it. Install Docker Desktop (https://docs.docker.com/get-docker/), '
            . ($this->os === 'Windows' ? 'or Docker Engine inside WSL (docs/windows-wsl.md), ' : '') . 'then reopen the project.');
    }

    private function finish(string $state, string $message): void
    {
        $this->set($state, $message);
        $ready = $this->ready;
        $this->ready = null;
        $ready?->resolve($this->state());
    }

    private function set(string $state, string $message): void
    {
        $this->state = $state;
        $this->message = $message;
        ($this->emit)('docker.state', $this->state());
    }
}
