<?php

namespace App;

use JumPHP\Async\Task;
use JumPHP\Http\Http;
use JumPHP\Http\HttpResponse;

/**
 * jumphp.net's API for the desktop IDE (Bearer token made by the sign-in): the user, their projects, creating one,
 * publishing, their GitHub token for git. Every call is a Task (never blocks the frame).
 */
final class Playground
{
    public const DEFAULT_SERVER = 'https://jumphp.net';

    public function __construct(private Http $http, private string $server, private ?string $token = null)
    {
        $this->server = rtrim($server, '/');
    }

    public function server(): string
    {
        return $this->server;
    }

    public function setToken(?string $token): void
    {
        $this->token = $token;
    }

    public function connectUrl(string $redirect, string $state): string
    {
        // a generic device name: nothing that identifies the person or the computer
        return $this->server . '/ide/connect?' . http_build_query(['redirect' => $redirect, 'state' => $state, 'device' => 'JumPHP IDE (' . PHP_OS_FAMILY . ')']);
    }

    public function me(): Task
    {
        return $this->call('GET', '/api/ide/me');
    }

    public function projects(): Task
    {
        return $this->call('GET', '/api/ide/projects');
    }

    public function project(string $slug): Task
    {
        return $this->call('GET', '/api/ide/projects/' . rawurlencode($slug));
    }

    public function categories(): Task
    {
        return $this->call('GET', '/api/ide/categories');
    }

    /** @param array{title: string, description?: string, category?: ?string, kind: string, brief?: string} $project */
    public function create(array $project): Task
    {
        return $this->call('POST', '/api/ide/projects', $project);
    }

    public function sync(string $slug): Task
    {
        return $this->call('POST', '/api/ide/projects/' . rawurlencode($slug) . '/sync', []);
    }

    public function githubToken(): Task
    {
        return $this->call('GET', '/api/ide/github-token');
    }

    /** Any call of the IDE's API (the Claude bridge's long polls: a longer timeout). */
    public function request(string $method, string $path, ?array $body = null, float $timeout = 30.0): Task
    {
        return $this->call($method, $path, $body, $timeout);
    }

    /** A JSON call: resolves with the decoded body, rejects with the server's error message. */
    private function call(string $method, string $path, ?array $body = null, float $timeout = 30.0): Task
    {
        if ($this->token === null) {
            $task = new Task();
            $task->settle(null, new \RuntimeException('Sign in with jumphp.net first.'));
            return $task;
        }
        $headers = ['Authorization' => 'Bearer ' . $this->token, 'Accept' => 'application/json', 'User-Agent' => 'jumphp-ide'];
        $out = new Task();
        $this->http->request($method, $this->server . $path, $body, $headers, $timeout)->then(function (HttpResponse $response) use ($out) {
            $data = $response->json();
            if (!$response->ok()) {
                $message = is_array($data) && isset($data['error']) ? (string)$data['error'] : "jumphp.net answered HTTP $response->status";
                if ($response->status === 401) {
                    $message = 'Your jumphp.net session has ended: sign in again.';
                }
                $out->settle(null, new PlaygroundException($message, $response->status));
                return;
            }
            $out->settle($data);
        }, fn (\Throwable $e) => $out->settle(null, new \RuntimeException('jumphp.net cannot be reached: ' . $e->getMessage())));
        return $out;
    }
}
