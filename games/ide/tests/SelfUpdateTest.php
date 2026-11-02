<?php

namespace Tests;

use App\SelfUpdate;
use JumPHP\Testing\AppTestCase;

/** The IDE keeps itself up to date: update.json signed, a newer version found, installed, offered. */
final class SelfUpdateTest extends AppTestCase
{
    private string $privateKey = '';
    private string $publicKey = '';

    protected function setUp(): void
    {
        putenv('JUMPHP_IDE_UPDATES=0');                 // no check at start: the tests ask themselves
        putenv('JUMPHP_IDE_SERVER=http://127.0.0.1:9');
        parent::setUp();
        $key = openssl_pkey_new(['private_key_type' => OPENSSL_KEYTYPE_EC, 'curve_name' => 'prime256v1']);
        self::assertNotFalse($key);
        openssl_pkey_export($key, $pem);
        $this->privateKey = $pem;
        $this->publicKey = (string)openssl_pkey_get_details($key)['key'];
    }

    protected function tearDown(): void
    {
        parent::tearDown();
        putenv('JUMPHP_IDE_UPDATES');
        putenv('JUMPHP_UPDATE_URL');
    }

    /** update.json as games/ide/tools/release.sh writes it (tools/app-release.php). */
    private function updateJson(string $version, string $id = 'ide'): string
    {
        $signed = json_encode(['id' => $id, 'version' => $version, 'notes' => 'Faster.', 'platforms' => [
            'linux-x86_64' => ['url' => "https://example.com/ide-v$version/ide-linux-x86_64", 'size' => 3, 'sha256' => hash('sha256', 'new')],
        ]]);
        openssl_sign((string)$signed, $signature, $this->privateKey, OPENSSL_ALGO_SHA256);
        return (string)json_encode(['signed' => $signed, 'signature' => base64_encode($signature)]);
    }

    public function testTheIdeKnowsWhereItsUpdatesAre(): void
    {
        $state = $this->invoke('ide', ['name' => 'update.state']);
        self::assertSame('idle', $state['state']);
        self::assertSame($this->app->config->get('version'), $state['current']);
        self::assertTrue($state['auto']);
        self::assertStringEndsWith('/games/ide/installer/update.json', (string)$this->app->updater()->url());
    }

    public function testANewerVersionIsFoundAndOffered(): void
    {
        $updates = new SelfUpdate($this->app);
        $update = $this->app->updater()->parse($this->updateJson('99.0.0'), $this->publicKey, 'linux-x86_64');
        self::assertNotNull($update);
        $updates->found($update);
        $state = $updates->state();
        // run from its sources (no executable to replace): offered, not installed
        self::assertSame('available', $state['state']);
        self::assertSame('99.0.0', $state['version']);
        self::assertFalse($state['installable']);
        self::assertFalse($updates->restart());
    }

    public function testTheSameOrAnOlderVersionIsNotAnUpdate(): void
    {
        $current = (string)$this->app->config->get('version');
        self::assertNull($this->app->updater()->parse($this->updateJson($current), $this->publicKey, 'linux-x86_64'));
        self::assertNull($this->app->updater()->parse($this->updateJson('0.0.1'), $this->publicKey, 'linux-x86_64'));
        $updates = new SelfUpdate($this->app);
        $updates->found(null);
        self::assertSame('latest', $updates->state()['state']);
    }

    public function testAnUpdateSignedWithAnotherKeyOrForAnotherAppIsRefused(): void
    {
        $other = openssl_pkey_new(['private_key_type' => OPENSSL_KEYTYPE_EC, 'curve_name' => 'prime256v1']);
        self::assertNotFalse($other);
        $otherPublic = (string)openssl_pkey_get_details($other)['key'];
        try {
            $this->app->updater()->parse($this->updateJson('99.0.0'), $otherPublic, 'linux-x86_64');
            self::fail('a wrong signature was accepted');
        } catch (\RuntimeException $e) {
            self::assertStringContainsString('signature', $e->getMessage());
        }
        $this->expectExceptionMessage('another app');
        $this->app->updater()->parse($this->updateJson('99.0.0', 'notes'), $this->publicKey, 'linux-x86_64');
    }

    public function testCheckingWithoutNetworkSaysWhy(): void
    {
        putenv('JUMPHP_UPDATE_URL=http://127.0.0.1:9/update.json');
        $updates = new SelfUpdate($this->app);
        self::assertSame('checking', $updates->check(true)['state']);
        for ($i = 0; $i < 300 && $updates->state()['state'] === 'checking'; $i++) {
            $this->wait(0.05);
        }
        $state = $updates->state();
        self::assertSame('error', $state['state']);
        self::assertTrue($state['manual']);
        self::assertNotEmpty($state['error']);
    }

    public function testAutomaticUpdatesCanBeTurnedOff(): void
    {
        $state = $this->invoke('ide', ['name' => 'update.auto', 'payload' => ['on' => false]]);
        self::assertFalse($state['auto']);
        self::assertFalse($this->app->options->get('autoUpdate'));
        $this->invoke('ide', ['name' => 'update.auto', 'payload' => ['on' => true]]);
        self::assertTrue($this->app->options->get('autoUpdate'));
    }
}
