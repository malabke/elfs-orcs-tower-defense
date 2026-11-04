<?php

namespace App;

/**
 * The way from jumphp.net to the IDE on this computer: a small HTTP listener on 127.0.0.1 (a fixed port among PORTS, the
 * first free one), polled every frame, never blocking. The "Install in my IDE" button of the site's plugin and extension
 * pages talks to it from the visitor's browser:
 *
 *   GET  /hello    -> {"ide": "jumphp", "version": "..."}                  (is an IDE here?)
 *   POST /install  {"kind": "plugin"|"extension", "id": "<plugin id>"|"<vendor>/<package>"}
 *                  -> {"ok": true}: the IDE then asks its user to confirm (nothing is installed by this request)
 *
 * Only the pages of $origins (jumphp.net) may use it (CORS, the Origin header is checked too: a local program can say
 * anything, but any other web page cannot), a request is a plain name, never a path or a command, and the IDE always
 * asks before it installs. Chrome's private network access: Access-Control-Allow-Private-Network.
 */
final class LocalLink
{
    public const PORTS = [47321, 47322, 47323, 47324, 47325];

    /** @var resource|null */
    private $server = null;

    /** @var array<int, array{0: resource, 1: string, 2: float}> */
    private array $clients = [];

    public int $port = 0;

    /**
     * @param list<string>                 $origins   e.g. ['https://jumphp.net']
     * @param callable(string, string): void $onInstall (kind, id): the IDE asks its user
     */
    public function __construct(private array $origins, private string $version, private $onInstall)
    {
        foreach (self::PORTS as $port) {
            $server = @stream_socket_server("tcp://127.0.0.1:$port", $errno, $error);
            if ($server !== false) {
                stream_set_blocking($server, false);
                $this->server = $server;
                $this->port = $port;
                return;
            }
        }
    }

    public function poll(): void
    {
        if ($this->server === null) {
            return;
        }
        while (($client = @stream_socket_accept($this->server, 0)) !== false) {
            stream_set_blocking($client, false);
            $this->clients[] = [$client, '', microtime(true)];
        }
        foreach ($this->clients as $i => [$client, $buffer, $since]) {
            $chunk = @fread($client, 16384);
            if ($chunk !== false && $chunk !== '') {
                $buffer .= $chunk;
                $this->clients[$i][1] = $buffer;
            }
            $complete = false;
            $headerEnd = strpos($buffer, "\r\n\r\n");
            if ($headerEnd !== false) {
                $length = preg_match('/^content-length:\s*(\d+)/mi', substr($buffer, 0, $headerEnd), $m) ? (int)$m[1] : 0;
                $complete = strlen($buffer) - $headerEnd - 4 >= min($length, 4096);
            }
            if (!$complete && !feof($client) && microtime(true) - $since < 5 && strlen($buffer) < 20000) {
                continue;
            }
            unset($this->clients[$i]);
            $complete ? $this->answer($client, $buffer) : @fclose($client);
        }
        $this->clients = array_values($this->clients);
    }

    /** @param resource $client */
    private function answer($client, string $request): void
    {
        [$head, $body] = array_pad(explode("\r\n\r\n", $request, 2), 2, '');
        $lines = explode("\r\n", $head);
        [$method, $target] = array_pad(explode(' ', $lines[0]), 2, '');
        $headers = [];
        foreach (array_slice($lines, 1) as $line) {
            if (str_contains($line, ':')) {
                [$k, $v] = explode(':', $line, 2);
                $headers[strtolower(trim($k))] = trim($v);
            }
        }
        $origin = $headers['origin'] ?? '';
        $allowed = in_array($origin, $this->origins, true);
        $path = (string)parse_url($target, PHP_URL_PATH);
        if ($method === 'OPTIONS') {
            $this->respond($client, 204, '', $allowed ? $origin : null);
            return;
        }
        if (!$allowed) {
            $this->respond($client, 403, json_encode(['ok' => false, 'error' => 'This page may not talk to the IDE.']), null);
            return;
        }
        if ($method === 'GET' && $path === '/hello') {
            $this->respond($client, 200, json_encode(['ide' => 'jumphp', 'version' => $this->version]), $origin);
            return;
        }
        if ($method === 'POST' && $path === '/install') {
            $data = json_decode($body, true);
            $kind = is_array($data) ? (string)($data['kind'] ?? '') : '';
            $id = is_array($data) ? (string)($data['id'] ?? '') : '';
            $valid = ($kind === 'plugin' && preg_match('/^[a-z0-9][a-z0-9-]{0,63}$/', $id))
                || ($kind === 'extension' && preg_match('#^[a-z0-9][a-z0-9_.-]{0,63}/[a-z0-9][a-z0-9_.-]{0,63}$#', $id));
            if (!$valid) {
                $this->respond($client, 400, json_encode(['ok' => false, 'error' => 'Unknown kind or name.']), $origin);
                return;
            }
            ($this->onInstall)($kind, $id);
            $this->respond($client, 200, json_encode(['ok' => true, 'asked' => true]), $origin);
            return;
        }
        $this->respond($client, 404, json_encode(['ok' => false, 'error' => 'Not found.']), $origin);
    }

    /** @param resource $client */
    private function respond($client, int $status, string $body, ?string $origin): void
    {
        $cors = $origin !== null
            ? "Access-Control-Allow-Origin: $origin\r\nVary: Origin\r\nAccess-Control-Allow-Methods: GET, POST, OPTIONS\r\nAccess-Control-Allow-Headers: Content-Type\r\nAccess-Control-Allow-Private-Network: true\r\nAccess-Control-Max-Age: 600\r\n"
            : '';
        $text = [200 => 'OK', 204 => 'No Content', 400 => 'Bad Request', 403 => 'Forbidden', 404 => 'Not Found'][$status] ?? 'Error';
        @fwrite($client, "HTTP/1.1 $status $text\r\nContent-Type: application/json\r\nContent-Length: " . strlen($body) . "\r\n{$cors}Connection: close\r\n\r\n$body");
        @fclose($client);
    }

    public function close(): void
    {
        foreach ($this->clients as [$client]) {
            if (is_resource($client)) {
                @fclose($client);
            }
        }
        $this->clients = [];
        if ($this->server !== null) {
            @fclose($this->server);
            $this->server = null;
        }
    }
}
