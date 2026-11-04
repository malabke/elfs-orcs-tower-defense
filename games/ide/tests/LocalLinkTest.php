<?php

namespace Tests;

use App\LocalLink;
use PHPUnit\Framework\TestCase;

/** jumphp.net's "Install in my IDE" buttons come in through LocalLink: only its pages, only plain names, the IDE asks. */
final class LocalLinkTest extends TestCase
{
    private ?LocalLink $link = null;

    /** @var list<array{string, string}> */
    private array $asked = [];

    protected function setUp(): void
    {
        $this->link = new LocalLink(['https://jumphp.net'], '9.9.9', function (string $kind, string $id): void { $this->asked[] = [$kind, $id]; });
        if ($this->link->port === 0) {
            self::markTestSkipped('no free port among LocalLink::PORTS');
        }
    }

    protected function tearDown(): void
    {
        $this->link?->close();
    }

    /** One request against the listener, polled until the answer is complete. */
    private function request(string $method, string $path, ?string $origin, ?string $body = null): array
    {
        $client = stream_socket_client('tcp://127.0.0.1:' . $this->link->port, $errno, $error, 2);
        self::assertNotFalse($client, (string)$error);
        $head = "$method $path HTTP/1.1\r\nHost: 127.0.0.1\r\n" . ($origin !== null ? "Origin: $origin\r\n" : '')
            . ($body !== null ? "Content-Type: application/json\r\nContent-Length: " . strlen($body) . "\r\n" : '') . "\r\n";
        fwrite($client, $head . ($body ?? ''));
        stream_set_blocking($client, false);
        $response = '';
        for ($i = 0; $i < 200 && !feof($client); $i++) {
            $this->link->poll();
            $response .= (string)fread($client, 8192);
            usleep(5000);
        }
        fclose($client);
        [$headers, $payload] = array_pad(explode("\r\n\r\n", $response, 2), 2, '');
        preg_match('#^HTTP/1.1 (\d+)#', $headers, $m);
        return ['status' => (int)($m[1] ?? 0), 'headers' => $headers, 'body' => (string)json_decode($payload, true)];
    }

    public function testThePageOfTheSiteFindsTheIde(): void
    {
        $r = $this->request('GET', '/hello', 'https://jumphp.net');
        self::assertSame(200, $r['status']);
        self::assertStringContainsString('Access-Control-Allow-Origin: https://jumphp.net', $r['headers']);
        self::assertStringContainsString('Access-Control-Allow-Private-Network: true', $r['headers']);
    }

    public function testAnotherPageIsRefused(): void
    {
        self::assertSame(403, $this->request('GET', '/hello', 'https://evil.example')['status']);
        self::assertSame(403, $this->request('POST', '/install', null, '{"kind":"plugin","id":"todo"}')['status']);
        self::assertSame([], $this->asked);
    }

    public function testThePreflightIsAnswered(): void
    {
        $r = $this->request('OPTIONS', '/install', 'https://jumphp.net');
        self::assertSame(204, $r['status']);
        self::assertStringContainsString('Access-Control-Allow-Methods', $r['headers']);
    }

    public function testAPluginAndAnExtensionAreAskedForNeverInstalled(): void
    {
        self::assertSame(200, $this->request('POST', '/install', 'https://jumphp.net', '{"kind":"plugin","id":"todo-finder"}')['status']);
        self::assertSame(200, $this->request('POST', '/install', 'https://jumphp.net', '{"kind":"extension","id":"jumphp/particles"}')['status']);
        self::assertSame([['plugin', 'todo-finder'], ['extension', 'jumphp/particles']], $this->asked);
    }

    public function testOnlyPlainNamesPass(): void
    {
        foreach (['{"kind":"plugin","id":"../../etc"}', '{"kind":"extension","id":"a/b; rm -rf /"}', '{"kind":"shell","id":"ls"}', '{"kind":"extension","id":"nopackage"}', 'not json'] as $body) {
            self::assertSame(400, $this->request('POST', '/install', 'https://jumphp.net', $body)['status'], $body);
        }
        self::assertSame([], $this->asked);
    }
}
