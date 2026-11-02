<?php

namespace JumPHP\Ide\Agent;

use JumPHP\Ide\Async\Deferred;

/**
 * A small MCP server (Model Context Protocol, "Streamable HTTP" transport with JSON answers) on 127.0.0.1, random
 * port, for the AI the IDE starts: claude --mcp-config gives it the URL and a token. JSON-RPC over POST: initialize,
 * tools/list, tools/call, ping; notifications get 202. A tools/call waits for the interface (a Deferred) while the
 * connection stays open: polled every frame, it never blocks.
 */
final class McpServer
{
    public const PROTOCOL = '2025-06-18';

    /** @var resource|null */
    private $server = null;

    /** @var array<int, array{socket: resource, buffer: string, busy: bool}> */
    private array $clients = [];
    private int $nextClient = 1;
    private int $port = 0;
    private string $token;

    /**
     * @param callable(string $tool, array $args): mixed $call a value or a Deferred of it (an array with `content`
     *        is passed as is; anything else becomes JSON text)
     * @param list<array{name: string, description: string, inputSchema: array<string, mixed>}>|\Closure(): list<array{name: string, description: string, inputSchema: array<string, mixed>}> $tools
     *        a closure: asked at each tools/list (the plugins' tools)
     */
    public function __construct(private $call, private array|\Closure $tools, private string $instructions = '', private string $name = AgentTools::SERVER)
    {
        $this->token = bin2hex(random_bytes(24));
    }

    public function start(): void
    {
        if ($this->server !== null) {
            return;
        }
        $server = @stream_socket_server('tcp://127.0.0.1:0', $errno, $error);
        if ($server === false) {
            throw new \RuntimeException("the IDE cannot listen on 127.0.0.1: $error");
        }
        stream_set_blocking($server, false);
        $this->server = $server;
        $name = (string)stream_socket_get_name($server, false);
        $this->port = (int)substr($name, strrpos($name, ':') + 1);
    }

    public function isRunning(): bool
    {
        return $this->server !== null;
    }

    public function url(): string
    {
        return "http://127.0.0.1:$this->port/mcp";
    }

    public function token(): string
    {
        return $this->token;
    }

    public function poll(): void
    {
        if ($this->server === null) {
            return;
        }
        while (($socket = @stream_socket_accept($this->server, 0)) !== false) {
            stream_set_blocking($socket, false);
            $this->clients[$this->nextClient++] = ['socket' => $socket, 'buffer' => '', 'busy' => false];
        }
        foreach ($this->clients as $id => $client) {
            if ($client['busy']) {
                continue;
            }
            $chunk = @fread($client['socket'], 65536);
            if ($chunk !== false && $chunk !== '') {
                $this->clients[$id]['buffer'] .= $chunk;
            } elseif (feof($client['socket'])) {
                @fclose($client['socket']);
                unset($this->clients[$id]);
                continue;
            }
            $request = $this->parse($this->clients[$id]['buffer']);
            if ($request !== null) {
                $this->clients[$id]['busy'] = true;
                $this->serve($id, $request);
            }
        }
    }

    public function close(): void
    {
        foreach ($this->clients as $client) {
            if (is_resource($client['socket'])) {
                @fclose($client['socket']);
            }
        }
        $this->clients = [];
        if ($this->server !== null) {
            @fclose($this->server);
            $this->server = null;
        }
    }

    /** @return array{method: string, path: string, headers: array<string, string>, body: string}|null a whole request, or null while it comes */
    private function parse(string $buffer): ?array
    {
        $end = strpos($buffer, "\r\n\r\n");
        if ($end === false) {
            return null;
        }
        $lines = explode("\r\n", substr($buffer, 0, $end));
        $first = explode(' ', (string)array_shift($lines));
        $headers = [];
        foreach ($lines as $line) {
            $colon = strpos($line, ':');
            if ($colon !== false) {
                $headers[strtolower(trim(substr($line, 0, $colon)))] = trim(substr($line, $colon + 1));
            }
        }
        $length = (int)($headers['content-length'] ?? 0);
        $body = substr($buffer, $end + 4);
        if (strlen($body) < $length) {
            return null;
        }
        return ['method' => strtoupper($first[0]), 'path' => (string)parse_url($first[1] ?? '/', PHP_URL_PATH),
            'headers' => $headers, 'body' => substr($body, 0, $length)];
    }

    /** @param array{method: string, path: string, headers: array<string, string>, body: string} $request */
    private function serve(int $client, array $request): void
    {
        if ($request['path'] !== '/mcp') {
            $this->respond($client, 404, ['error' => 'not found']);
            return;
        }
        if (!hash_equals('Bearer ' . $this->token, $request['headers']['authorization'] ?? '')) {
            $this->respond($client, 401, ['error' => 'unauthorized']);
            return;
        }
        // a web page must not reach it (DNS rebinding): only clients without an Origin, or the loopback's own
        $origin = $request['headers']['origin'] ?? '';
        if ($origin !== '' && !preg_match('#^https?://(127\.0\.0\.1|localhost)(:\d+)?$#', $origin)) {
            $this->respond($client, 403, ['error' => 'forbidden origin']);
            return;
        }
        if ($request['method'] === 'DELETE') {
            $this->respond($client, 200, null);
            return;
        }
        if ($request['method'] !== 'POST') {
            $this->respond($client, 405, null, ['Allow: POST, DELETE']);     // no server-sent stream: answers come in the POSTs
            return;
        }
        $message = json_decode($request['body'], true);
        if (!is_array($message)) {
            $this->respond($client, 400, self::error(null, -32700, 'parse error'));
            return;
        }
        $batch = array_is_list($message);
        $messages = $batch ? $message : [$message];
        $answers = [];
        foreach ($messages as $m) {
            $answers[] = is_array($m) ? $this->handle($m) : Deferred::resolved(self::error(null, -32600, 'invalid request'));
        }
        // every answer settled (now or later), then the HTTP response
        $results = [];
        $left = count($answers);
        foreach ($answers as $i => $answer) {
            $answer->then(function (mixed $value) use (&$results, &$left, $i, $client, $batch): void {
                $results[$i] = $value;
                if (--$left === 0) {
                    ksort($results);
                    $list = array_values(array_filter($results, fn ($r) => $r !== null));
                    if (!$list) {
                        $this->respond($client, 202, null);
                    } else {
                        $this->respond($client, 200, $batch ? $list : $list[0]);
                    }
                }
            });
        }
    }

    /** One JSON-RPC message: a Deferred of its answer (null for a notification). */
    private function handle(array $m): Deferred
    {
        $id = $m['id'] ?? null;
        $method = (string)($m['method'] ?? '');
        $params = is_array($m['params'] ?? null) ? $m['params'] : [];
        if (!array_key_exists('id', $m)) {
            return Deferred::resolved(null);      // notifications/initialized, cancelled...
        }
        switch ($method) {
            case 'initialize':
                $asked = (string)($params['protocolVersion'] ?? self::PROTOCOL);
                return Deferred::resolved(self::result($id, [
                    'protocolVersion' => in_array($asked, ['2024-11-05', '2025-03-26', '2025-06-18'], true) ? $asked : self::PROTOCOL,
                    'capabilities' => ['tools' => ['listChanged' => false]],
                    'serverInfo' => ['name' => $this->name, 'title' => 'JumPHP IDE', 'version' => '1.0'],
                    'instructions' => $this->instructions,
                ]));
            case 'ping':
                return Deferred::resolved(self::result($id, new \stdClass()));
            case 'tools/list':
                return Deferred::resolved(self::result($id, ['tools' => array_map(fn (array $t) => [
                    'name' => $t['name'], 'description' => $t['description'], 'inputSchema' => $t['inputSchema'],
                ], $this->tools instanceof \Closure ? ($this->tools)() : $this->tools)]));
            case 'tools/call':
                $name = (string)($params['name'] ?? '');
                $args = is_array($params['arguments'] ?? null) ? $params['arguments'] : [];
                try {
                    $value = ($this->call)($name, $args);
                } catch (\Throwable $e) {
                    return Deferred::resolved(self::result($id, self::toolError($e->getMessage())));
                }
                $deferred = $value instanceof Deferred ? $value : Deferred::resolved($value);
                return $deferred->then(
                    fn (mixed $result) => self::result($id, self::toolResult($result)),
                    fn (\Throwable $e) => self::result($id, self::toolError($e->getMessage())),
                );
            default:
                return Deferred::resolved(self::error($id, -32601, "method not found: $method"));
        }
    }

    /** @return array<string, mixed> */
    private static function toolResult(mixed $result): array
    {
        if (is_array($result) && isset($result['content']) && is_array($result['content'])) {
            return $result;
        }
        $text = is_string($result) ? $result : (string)json_encode($result ?? 'ok', JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
        return ['content' => [['type' => 'text', 'text' => $text]]];
    }

    /** @return array<string, mixed> */
    private static function toolError(string $message): array
    {
        return ['content' => [['type' => 'text', 'text' => $message]], 'isError' => true];
    }

    /** @return array<string, mixed> */
    private static function result(mixed $id, mixed $result): array
    {
        return ['jsonrpc' => '2.0', 'id' => $id, 'result' => $result];
    }

    /** @return array<string, mixed> */
    private static function error(mixed $id, int $code, string $message): array
    {
        return ['jsonrpc' => '2.0', 'id' => $id, 'error' => ['code' => $code, 'message' => $message]];
    }

    /** @param list<string> $extra */
    private function respond(int $client, int $status, mixed $body, array $extra = []): void
    {
        $socket = $this->clients[$client]['socket'] ?? null;
        unset($this->clients[$client]);
        if (!is_resource($socket)) {
            return;     // the AI gave up waiting (closed the connection)
        }
        $text = $body === null ? '' : (string)json_encode($body, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        $reason = [200 => 'OK', 202 => 'Accepted', 400 => 'Bad Request', 401 => 'Unauthorized', 403 => 'Forbidden', 404 => 'Not Found', 405 => 'Method Not Allowed'][$status] ?? 'Error';
        $head = "HTTP/1.1 $status $reason\r\n" . ($text !== '' ? "Content-Type: application/json\r\n" : '')
            . 'Content-Length: ' . strlen($text) . "\r\nConnection: close\r\n" . implode('', array_map(fn ($h) => "$h\r\n", $extra)) . "\r\n";
        stream_set_blocking($socket, true);      // a few KB to the loopback: written at once
        @fwrite($socket, $head . $text);
        @fclose($socket);
    }
}
