<?php

namespace JumPHP\Ide\Run;

use JumPHP\Ide\Async\Deferred;
use JumPHP\Ide\Async\Environment;
use JumPHP\Ide\Async\Processes;

/**
 * Installs a program the IDE needs and the computer lacks (git, Docker), from the button of the error notification:
 * Windows: winget (silent), macOS: Homebrew, Linux: the package manager through pkexec (a password dialog) when there is
 * one. When no way exists here, the program's download page is given instead (the page opens).
 */
final class ToolInstaller
{
    /** tool => [winget id, brew formula, apt/dnf package, download page] */
    private const TOOLS = [
        'git' => ['Git.Git', 'git', 'git', 'https://git-scm.com/downloads'],
        'docker' => ['Docker.DockerDesktop', '--cask docker', 'docker.io', 'https://docs.docker.com/get-started/get-docker/'],
        'claude' => ['Anthropic.ClaudeCode', 'claude-code', '', 'https://claude.com/claude-code'],
        'opencode' => ['sst.opencode', 'sst/tap/opencode', '', 'https://opencode.ai'],
    ];

    public function __construct(private Processes $processes, private string $root)
    {
    }

    /** @return list<string> the tools that can be installed */
    public static function tools(): array
    {
        return array_keys(self::TOOLS);
    }

    /** @return array{command: ?list<string>, page: string} how it would be installed here */
    public function plan(string $tool): array
    {
        [$winget, $brew, $package, $page] = self::TOOLS[$tool] ?? throw new \InvalidArgumentException("Unknown program: $tool");
        $command = null;
        if (PHP_OS_FAMILY === 'Windows' && Environment::which('winget') !== null) {
            $command = ['winget', 'install', '--id', $winget, '-e', '--silent', '--accept-package-agreements', '--accept-source-agreements'];
        } elseif (PHP_OS_FAMILY === 'Darwin' && Environment::which('brew') !== null) {
            $command = ['brew', 'install', ...explode(' ', $brew)];
        } elseif (PHP_OS_FAMILY === 'Linux' && $package !== '' && Environment::which('pkexec') !== null) {
            foreach ([['apt-get', ['apt-get', 'install', '-y']], ['dnf', ['dnf', 'install', '-y']], ['pacman', ['pacman', '-S', '--noconfirm']]] as [$manager, $args]) {
                if (Environment::which($manager) !== null) {
                    $command = ['pkexec', ...$args, $package];
                    break;
                }
            }
        }
        return ['command' => $command, 'page' => $page];
    }

    /** @return Deferred resolves with {installed: bool, page: string, output: string} */
    public function install(string $tool): Deferred
    {
        $plan = $this->plan($tool);
        if ($plan['command'] === null) {
            return Deferred::resolved(['installed' => false, 'page' => $plan['page'], 'output' => '']);
        }
        $started = $this->processes->start($plan['command'], $this->root);
        return $started['done']->then(fn (array $r) => ['installed' => $r['code'] === 0,
            'page' => $plan['page'], 'output' => mb_substr(trim((string)$r['output']), -1500)]);
    }
}
