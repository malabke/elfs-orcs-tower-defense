<?php

namespace App;

use JumPHP\Async\Task;

/**
 * The sign-in's way back (as native apps do OAuth): a one-shot HTTP listener on 127.0.0.1, random port. The browser
 * comes back to http://127.0.0.1:<port>/callback?token=...&state=... after the user authorized the IDE on jumphp.net.
 * Polled every frame: never blocks.
 */
final class Loopback
{
    /** @var resource|null */
    private $server = null;

    /** @var list<array{0: resource, 1: string}> connections being read */
    private array $clients = [];

    public readonly string $state;
    public readonly Task $done;
    private int $port = 0;

    public function __construct(private float $timeout = 600.0)
    {
        $this->state = bin2hex(random_bytes(16));
        $this->done = new Task();
        $server = @stream_socket_server('tcp://127.0.0.1:0', $errno, $error);
        if ($server === false) {
            $this->done->settle(null, new \RuntimeException("cannot listen on 127.0.0.1: $error"));
            return;
        }
        stream_set_blocking($server, false);
        $this->server = $server;
        $name = (string)stream_socket_get_name($server, false);
        $this->port = (int)substr($name, strrpos($name, ':') + 1);
        $this->deadline = microtime(true) + $timeout;
    }

    private float $deadline = 0;

    public function redirectUri(): string
    {
        return "http://127.0.0.1:$this->port/callback";
    }

    public function poll(): void
    {
        if ($this->server === null || $this->done->isDone()) {
            return;
        }
        if (microtime(true) > $this->deadline) {
            $this->fail('sign-in timed out');
            return;
        }
        while (($client = @stream_socket_accept($this->server, 0)) !== false) {
            stream_set_blocking($client, false);
            $this->clients[] = [$client, ''];
        }
        foreach ($this->clients as $i => [$client, $buffer]) {
            $chunk = @fread($client, 8192);
            if ($chunk !== false && $chunk !== '') {
                $buffer .= $chunk;
                $this->clients[$i][1] = $buffer;
            }
            if (!str_contains($buffer, "\r\n\r\n") && !feof($client)) {
                continue;
            }
            $this->answer($client, $buffer);
            unset($this->clients[$i]);
        }
        $this->clients = array_values($this->clients);
    }

    /** @param resource $client */
    private function answer($client, string $request): void
    {
        $line = strtok($request, "\r\n") ?: '';
        $target = explode(' ', $line)[1] ?? '/';
        $query = [];
        parse_str((string)parse_url($target, PHP_URL_QUERY), $query);
        $path = (string)parse_url($target, PHP_URL_PATH);
        if ($path !== '/callback') {
            $this->respond($client, 404, 'Not found.');
            return;
        }
        if (!hash_equals($this->state, (string)($query['state'] ?? ''))) {
            $this->respond($client, 400, 'This sign-in link is not the one the IDE is waiting for. Start again from the IDE.');
            return;
        }
        if (isset($query['error']) || empty($query['token'])) {
            $this->respond($client, 200, 'Sign-in cancelled. You can close this tab.');
            $this->fail('sign-in cancelled');
            return;
        }
        $this->respond($client, 200, 'You are signed in to JumPHP IDE. You can close this tab and go back to the IDE.');
        $this->close();
        $this->done->settle((string)$query['token']);
    }

    /** @param resource $client */
    private function respond($client, int $status, string $text): void
    {
        $html = '<!doctype html><meta charset="utf-8"><title>JumPHP IDE</title><body style="font:16px system-ui;background:#1e1f22;color:#dfe1e5;display:grid;place-items:center;height:90vh">'
            . '<p>' . htmlspecialchars($text) . '</p>';
        @fwrite($client, "HTTP/1.1 $status " . ($status === 200 ? 'OK' : 'Error') . "\r\nContent-Type: text/html; charset=utf-8\r\nContent-Length: " . strlen($html) . "\r\nConnection: close\r\n\r\n" . $html);
        @fclose($client);
    }

    public function fail(string $message): void
    {
        $this->close();
        if (!$this->done->isDone()) {
            $this->done->settle(null, new \RuntimeException($message));
        }
    }

    private function close(): void
    {
        foreach ($this->clients as [$client]) {
            @fclose($client);
        }
        $this->clients = [];
        if ($this->server !== null) {
            @fclose($this->server);
            $this->server = null;
        }
    }
}
