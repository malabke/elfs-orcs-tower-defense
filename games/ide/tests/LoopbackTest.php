<?php

namespace Tests;

use App\Loopback;
use PHPUnit\Framework\TestCase;

final class LoopbackTest extends TestCase
{
    /** The browser comes back from jumphp.net: the request, then what the page says. */
    private function browse(Loopback $loopback, string $query): string
    {
        $port = (int)parse_url($loopback->redirectUri(), PHP_URL_PORT);
        $browser = stream_socket_client("tcp://127.0.0.1:$port", $errno, $error, 2);
        self::assertNotFalse($browser, $error);
        fwrite($browser, "GET /callback?$query HTTP/1.1\r\nHost: 127.0.0.1\r\n\r\n");
        for ($i = 0; $i < 50 && !$loopback->done->isDone(); $i++) {
            $loopback->poll();
            usleep(10_000);
        }
        stream_set_timeout($browser, 2);
        return (string)stream_get_contents($browser);
    }

    public function testTheSignInComesBackWithItsTokenAndTheIdeKeepsRunning(): void
    {
        $loopback = new Loopback();
        $page = $this->browse($loopback, 'token=jpi_abc&state=' . $loopback->state);
        self::assertStringContainsString('You are signed in', $page);
        self::assertTrue($loopback->done->isOk());
        self::assertSame('jpi_abc', $loopback->done->result());
        $loopback->poll();      // the frames go on
        $loopback->fail('closed');
    }

    public function testALinkOfAnotherSignInIsRefused(): void
    {
        $loopback = new Loopback();
        $page = $this->browse($loopback, 'token=jpi_abc&state=other');
        self::assertStringContainsString('not the one the IDE is waiting for', $page);
        self::assertFalse($loopback->done->isDone());
        $loopback->fail('closed');
        self::assertFalse($loopback->done->isOk());
    }
}
