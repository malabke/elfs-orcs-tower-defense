<?php

namespace JumPHP\Ide\Agent;

use JumPHP\Ide\Async\Deferred;

/**
 * The IDE's side of the AI's tools: a call (from McpServer) goes to the interface as the event agent.call
 * {call, tool, args}; the interface does it where the user sees it and answers with the command agent.reply
 * {call, result | error}. A call the interface does not answer in the tool's time fails (no project window...).
 */
final class IdeAgent
{
    /** @var array<int, array{0: Deferred, 1: float, 2: string}> call id => its result, its deadline, the tool */
    private array $pending = [];
    private int $next = 1;

    private ?McpServer $server = null;

    /** @param callable(string $event, array $payload): void $emit */
    public function __construct(private $emit)
    {
    }

    /** The MCP server the AI connects to, started the first time it is needed. */
    public function server(): McpServer
    {
        if ($this->server === null) {
            $this->server = new McpServer(fn (string $tool, array $args) => $this->call($tool, $args), AgentTools::all(), AgentTools::INSTRUCTIONS);
        }
        $this->server->start();
        return $this->server;
    }

    /**
     * claude --mcp-config: the IDE's server, in a file only this user reads (it holds the server's token).
     *
     * @return string the file's path
     */
    public function mcpConfigFile(): string
    {
        $server = $this->server();
        $config = ['mcpServers' => [AgentTools::SERVER => ['type' => 'http', 'url' => $server->url(),
            'headers' => ['Authorization' => 'Bearer ' . $server->token()]]]];
        $file = rtrim(sys_get_temp_dir(), '/') . '/jumphp-ide-mcp-' . getmypid() . '.json';
        $old = umask(0077);
        file_put_contents($file, json_encode($config, JSON_UNESCAPED_SLASHES));
        umask($old);
        @chmod($file, 0600);
        return $file;
    }

    /** A tool for the interface: its answer later. */
    public function call(string $tool, array $args): Deferred
    {
        $definition = AgentTools::find($tool);
        if ($definition === null) {
            return Deferred::rejected(new \InvalidArgumentException("no tool '$tool'"));
        }
        $id = $this->next++;
        $deferred = new Deferred();
        $this->pending[$id] = [$deferred, microtime(true) + $definition['timeout'], $tool];
        ($this->emit)('agent.call', ['call' => $id, 'tool' => $tool, 'args' => $args]);
        return $deferred;
    }

    /** The interface's answer to a call. */
    public function reply(int $call, mixed $result = null, ?string $error = null): bool
    {
        if (!isset($this->pending[$call])) {
            return false;
        }
        [$deferred] = $this->pending[$call];
        unset($this->pending[$call]);
        if ($error !== null) {
            $deferred->reject(new \RuntimeException($error));
        } else {
            $deferred->resolve($result);
        }
        return true;
    }

    /** Every frame: the HTTP server, the calls past their time. */
    public function tick(): void
    {
        $this->server?->poll();
        $now = microtime(true);
        foreach ($this->pending as $id => [$deferred, $deadline, $tool]) {
            if ($now > $deadline) {
                unset($this->pending[$id]);
                $deferred->reject(new \RuntimeException("the IDE did not answer '$tool' in time (is the project window open?)"));
            }
        }
    }

    public function close(): void
    {
        $this->server?->close();
        foreach ($this->pending as [$deferred]) {
            $deferred->reject(new \RuntimeException('the IDE closed the project'));
        }
        $this->pending = [];
    }
}
