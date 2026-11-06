<?php

namespace JumPHP\Ide\Tests;

use JumPHP\Ide\Agent\AgentTools;
use JumPHP\Ide\Agent\IdeAgent;
use PHPUnit\Framework\TestCase;

/** The IDE's tools for the AI: the MCP server on the loopback, the calls handed to the interface and answered. */
final class AgentTest extends TestCase
{
    /** @var list<array{0: string, 1: array}> */
    private array $events = [];
    private IdeAgent $agent;

    protected function setUp(): void
    {
        $this->events = [];
        $this->agent = new IdeAgent(function (string $event, array $payload): void {
            $this->events[] = [$event, $payload];
        });
    }

    protected function tearDown(): void
    {
        $this->agent->close();
    }

    public function testTheServerIntroducesItselfAndListsTheTools(): void
    {
        $init = $this->rpc(['jsonrpc' => '2.0', 'id' => 1, 'method' => 'initialize', 'params' => ['protocolVersion' => '2025-06-18', 'capabilities' => new \stdClass(), 'clientInfo' => ['name' => 'test', 'version' => '1']]]);
        self::assertSame(200, $init['status']);
        self::assertSame('2025-06-18', $init['body']['result']['protocolVersion']);
        self::assertSame(AgentTools::SERVER, $init['body']['result']['serverInfo']['name']);

        $note = $this->rpc(['jsonrpc' => '2.0', 'method' => 'notifications/initialized']);
        self::assertSame(202, $note['status']);

        $list = $this->rpc(['jsonrpc' => '2.0', 'id' => 2, 'method' => 'tools/list']);
        $names = array_column($list['body']['result']['tools'], 'name');
        self::assertContains('open_file', $names);
        self::assertContains('run', $names);
        self::assertContains('ask_user', $names);
    }

    public function testACallGoesToTheInterfaceAndItsAnswerBackToTheAi(): void
    {
        $response = $this->rpc(['jsonrpc' => '2.0', 'id' => 7, 'method' => 'tools/call', 'params' => ['name' => 'open_file', 'arguments' => ['path' => 'src/A.php', 'line' => 3]]],
            function () {
                $calls = array_values(array_filter($this->events, fn ($e) => $e[0] === 'agent.call'));
                if ($calls) {
                    self::assertSame('open_file', $calls[0][1]['tool']);
                    self::assertSame(['path' => 'src/A.php', 'line' => 3], $calls[0][1]['args']);
                    $this->agent->reply($calls[0][1]['call'], ['opened' => 'src/A.php']);
                    $this->events = [];
                }
            });
        self::assertSame(7, $response['body']['id']);
        self::assertStringContainsString('src/A.php', $response['body']['result']['content'][0]['text']);
        self::assertArrayNotHasKey('isError', $response['body']['result']);
    }

    public function testAnErrorOfTheInterfaceIsAToolError(): void
    {
        $response = $this->rpc(['jsonrpc' => '2.0', 'id' => 8, 'method' => 'tools/call', 'params' => ['name' => 'open_file', 'arguments' => ['path' => 'nope.php']]],
            function () {
                foreach ($this->events as [$event, $payload]) {
                    if ($event === 'agent.call') {
                        $this->agent->reply($payload['call'], null, 'nope.php does not exist');
                    }
                }
                $this->events = [];
            });
        self::assertTrue($response['body']['result']['isError']);
        self::assertSame('nope.php does not exist', $response['body']['result']['content'][0]['text']);
    }

    public function testThePluginsToolsAreOfferedBesideTheIdesAndRunInPhp(): void
    {
        $this->agent->setExtraTools(fn () => [['name' => 'plugin_todos_count', 'description' => '[Todos] count', 'inputSchema' => ['type' => 'object', 'properties' => new \stdClass()]]],
            fn (string $tool, array $args) => $args['n'] > 0 ? ['count' => $args['n'] * 2] : throw new \RuntimeException('n must be > 0'));
        $names = array_column($this->rpc(['jsonrpc' => '2.0', 'id' => 1, 'method' => 'tools/list'])['body']['result']['tools'], 'name');
        self::assertContains('open_file', $names);
        self::assertContains('plugin_todos_count', $names);
        $ok = $this->rpc(['jsonrpc' => '2.0', 'id' => 2, 'method' => 'tools/call', 'params' => ['name' => 'plugin_todos_count', 'arguments' => ['n' => 21]]]);
        self::assertSame(['count' => 42], json_decode($ok['body']['result']['content'][0]['text'], true));
        self::assertSame([], $this->events, 'a plugin\'s tool does not go through the interface');
        $error = $this->rpc(['jsonrpc' => '2.0', 'id' => 3, 'method' => 'tools/call', 'params' => ['name' => 'plugin_todos_count', 'arguments' => ['n' => 0]]]);
        self::assertTrue($error['body']['result']['isError']);
        self::assertSame('n must be > 0', $error['body']['result']['content'][0]['text']);
    }

    public function testWithoutTheTokenNothingIsAnswered(): void
    {
        $response = $this->rpc(['jsonrpc' => '2.0', 'id' => 1, 'method' => 'tools/list'], null, 'Bearer wrong');
        self::assertSame(401, $response['status']);
    }

    public function testTheConfigurationForClaudeNamesTheServerWithItsToken(): void
    {
        $file = $this->agent->mcpConfigFile();
        $config = json_decode((string)file_get_contents($file), true);
        $server = $config['mcpServers'][AgentTools::SERVER];
        self::assertSame('http', $server['type']);
        self::assertSame($this->agent->server()->url(), $server['url']);
        self::assertSame('Bearer ' . $this->agent->server()->token(), $server['headers']['Authorization']);
        self::assertSame('600', decoct(fileperms($file) & 0777));
        unlink($file);
    }

    /**
     * Sends one JSON-RPC message over HTTP and runs the IDE's frames until the answer comes.
     *
     * @return array{status: int, body: mixed}
     */
    private function rpc(array $message, ?callable $everyFrame = null, ?string $auth = null): array
    {
        $server = $this->agent->server();
        $url = parse_url($server->url());
        $socket = stream_socket_client("tcp://{$url['host']}:{$url['port']}", $errno, $error, 2);
        self::assertNotFalse($socket, $error);
        $body = (string)json_encode($message);
        fwrite($socket, "POST /mcp HTTP/1.1\r\nHost: 127.0.0.1\r\nContent-Type: application/json\r\nAccept: application/json, text/event-stream\r\n"
            . 'Authorization: ' . ($auth ?? 'Bearer ' . $server->token()) . "\r\nContent-Length: " . strlen($body) . "\r\n\r\n" . $body);
        stream_set_blocking($socket, false);
        $raw = '';
        $deadline = microtime(true) + 5;
        while (!feof($socket) && microtime(true) < $deadline) {
            $this->agent->tick();
            if ($everyFrame) {
                $everyFrame();
            }
            $raw .= (string)fread($socket, 65536);
            usleep(1000);
        }
        fclose($socket);
        [$head, $content] = explode("\r\n\r\n", $raw, 2) + ['', ''];
        preg_match('#^HTTP/1\.1 (\d+)#', $head, $m);
        return ['status' => (int)($m[1] ?? 0), 'body' => $content !== '' ? json_decode($content, true) : null];
    }
}
