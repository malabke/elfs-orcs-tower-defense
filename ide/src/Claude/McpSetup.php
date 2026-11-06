<?php

namespace JumPHP\Ide\Claude;

use JumPHP\Ide\Async\Deferred;
use JumPHP\Ide\Async\Environment;
use JumPHP\Ide\Async\Processes;

/**
 * jumphp.net's MCP server (`jumphp`: the user's projects, publishing, tests, notes) in the Claude Code of this computer.
 * status() reads Claude Code's own configuration (no process: it is fast): the server in the user's scope (~/.claude.json
 * mcpServers), the local scope of the project (projects[<root>].mcpServers) or the project's .mcp.json. install() runs
 * `claude mcp add --transport http --scope user jumphp <url>/mcp --header "Authorization: Bearer <token>"`, the token
 * being the IDE's own sign-in to jumphp.net (jumphp.net accepts it for MCP).
 */
final class McpSetup
{
    public const NAME = 'jumphp';

    /** @param string|null $configFile Claude Code's configuration (default: $CLAUDE_CONFIG_DIR/.claude.json or ~/.claude.json) */
    public function __construct(private Processes $processes, private string $root, private ?string $configFile = null)
    {
    }

    /**
     * @return array{claude: bool, installed: bool, scope: ?string, url: ?string, matches: bool}
     *   matches: its address is this server's (a server for another jumphp.net, e.g. a local one, does not count)
     */
    public function status(string $server): array
    {
        $want = rtrim($server, '/') . '/mcp';
        $found = null;
        foreach ($this->servers() as $scope => $servers) {
            foreach ($servers as $name => $config) {
                $url = is_array($config) ? (string)($config['url'] ?? '') : '';
                if ($name === self::NAME || ($url !== '' && rtrim($url, '/') === $want)) {
                    $entry = ['scope' => $scope, 'url' => $url, 'matches' => rtrim($url, '/') === $want];
                    if ($found === null || ($entry['matches'] && !$found['matches'])) {
                        $found = $entry;
                    }
                }
            }
        }
        return ['claude' => Environment::which('claude') !== null, 'installed' => $found !== null && $found['matches'],
            'scope' => $found['scope'] ?? null, 'url' => $found['url'] ?? null, 'matches' => (bool)($found['matches'] ?? false)];
    }

    /** Adds (or replaces) the server for the user (every project of this computer). Resolves with status(). */
    public function install(string $server, string $token): Deferred
    {
        if (Environment::which('claude') === null) {
            return Deferred::rejected(new \RuntimeException('Claude Code is not installed on this computer (https://claude.com/claude-code)'));
        }
        if (!preg_match('/^[A-Za-z0-9_\-]+$/', $token)) {
            return Deferred::rejected(new \InvalidArgumentException('not a jumphp.net token'));
        }
        $url = rtrim($server, '/') . '/mcp';
        $current = $this->status($server);
        // an older one in the user's scope (another address, an old token) is replaced; other scopes are left alone
        $remove = $current['scope'] === 'user' || isset(($this->servers()['user'] ?? [])[self::NAME])
            ? $this->claude(['mcp', 'remove', '--scope', 'user', self::NAME])->then(fn () => null, fn () => null)
            : Deferred::resolved(null);
        return $remove
            ->then(fn () => $this->claude(['mcp', 'add', '--transport', 'http', '--scope', 'user', self::NAME, $url, '--header', "Authorization: Bearer $token"]))
            ->then(function () use ($server) {
                $status = $this->status($server);
                if (!$status['installed']) {
                    throw new \RuntimeException('Claude Code did not keep the jumphp server (claude mcp add): try `claude mcp list` in a terminal');
                }
                return $status;
            });
    }

    /** @return array<string, array<string, mixed>> scope => name => config */
    private function servers(): array
    {
        $out = [];
        $config = json_decode((string)@file_get_contents($this->configFile()), true);
        if (is_array($config)) {
            $out['user'] = is_array($config['mcpServers'] ?? null) ? $config['mcpServers'] : [];
            foreach ((array)($config['projects'] ?? []) as $path => $project) {
                if (self::samePath((string)$path, $this->root) && is_array($project['mcpServers'] ?? null)) {
                    $out['local'] = $project['mcpServers'];
                }
            }
        }
        $project = json_decode((string)@file_get_contents($this->root . '/.mcp.json'), true);
        if (is_array($project['mcpServers'] ?? null)) {
            $out['project'] = $project['mcpServers'];
        }
        return $out;
    }

    private function configFile(): string
    {
        if ($this->configFile !== null) {
            return $this->configFile;
        }
        $dir = getenv('CLAUDE_CONFIG_DIR');
        if (is_string($dir) && $dir !== '') {
            return rtrim($dir, '/\\') . '/.claude.json';
        }
        $home = getenv('HOME') ?: getenv('USERPROFILE') ?: '';
        return rtrim((string)$home, '/\\') . '/.claude.json';
    }

    private static function samePath(string $a, string $b): bool
    {
        $norm = fn (string $p) => rtrim(strtolower(str_replace('\\', '/', $p)), '/');
        return PHP_OS_FAMILY === 'Windows' ? $norm($a) === $norm($b) : rtrim($a, '/') === rtrim($b, '/');
    }

    private function claude(array $args): Deferred
    {
        return $this->processes->start(['claude', ...$args], $this->root)['done']->then(function (array $r) use ($args) {
            if ($r['code'] !== 0) {
                throw new \RuntimeException('claude ' . implode(' ', array_slice($args, 0, 2)) . ': ' . trim($r['stderr'] ?: $r['stdout']));
            }
            return $r;
        });
    }
}
