<?php

namespace App;

use JumPHP\Tauri\App;
use JumPHP\Tauri\Update;

/**
 * The IDE keeps itself up to date (JumPHP\Tauri\Updater, docs/tauri.md "Updates").
 *
 * At start (after a few seconds) and every few hours, it reads the signed update.json of game.yaml app.update.url
 * (games/ide/installer/update.json on JumPHP's main branch, written by games/ide/tools/release.sh). A newer version
 * is downloaded and put in place of the executable at once, in the background (option autoUpdate, on by default);
 * the page then offers to restart into it (the next start runs it anyway). Run from its sources (`php app.php`),
 * the IDE has nothing to replace: it only says a newer version is out.
 *
 * States sent to the page (event `update.state`, command `update.state`):
 *   off (no address) | idle | checking | latest | available | installing | installed | error
 */
final class SelfUpdate
{
    public const FIRST_CHECK = 8.0;
    public const EVERY = 6 * 3600.0;

    /** @var array<string, mixed> state, current, installable, auto; version, notes, error, checked, manual (the user asked) */
    private array $state;

    private ?Update $update = null;

    public function __construct(private readonly App $app)
    {
        $this->state = [
            'state' => $app->updater()->url() === null ? 'off' : 'idle',
            'current' => $app->updater()->version(),
            'installable' => $app->updater()->isInstallable(),
            'auto' => (bool)$app->options->get('autoUpdate', true),
        ];
    }

    /** The checks at start and then every EVERY seconds (JUMPHP_IDE_UPDATES=0: none, for tests and screenshots). */
    public function start(): void
    {
        if ($this->state['state'] === 'off' || getenv('JUMPHP_IDE_UPDATES') === '0') {
            return;
        }
        $this->app->gameLoop->addTimer(self::FIRST_CHECK, fn () => $this->check());
        $this->app->gameLoop->addPeriodicTimer(self::EVERY, fn () => $this->check());
    }

    /** @return array<string, mixed> */
    public function state(): array
    {
        return $this->state;
    }

    /** Asks update.json now (Help > Check for Updates). */
    public function check(bool $manual = false): array
    {
        if (in_array($this->state['state'], ['off', 'checking', 'installing', 'installed'], true)) {
            return $this->state;
        }
        $this->set(['state' => 'checking', 'manual' => $manual]);
        $this->app->updater()->check()->then(
            fn (?Update $update) => $this->found($update),
            fn (\Throwable $e) => $this->set(['state' => 'error', 'error' => $e->getMessage()]),
        );
        return $this->state;
    }

    /** What check() found: a newer version is installed at once when it can be (and autoUpdate is on). */
    public function found(?Update $update): void
    {
        $this->update = $update;
        if ($update === null) {
            $this->set(['state' => 'latest', 'checked' => microtime(true)]);
            return;
        }
        $this->set(['state' => 'available', 'version' => $update->version, 'notes' => $update->notes, 'checked' => microtime(true)]);
        if ($this->state['installable'] && $this->state['auto']) {
            $this->install();
        }
    }

    /** Downloads the newer version and puts it in place of this executable (restart() then runs it). */
    public function install(): array
    {
        if ($this->update === null || !$this->state['installable'] || $this->state['state'] !== 'available') {
            return $this->state;
        }
        $this->set(['state' => 'installing']);
        $this->update->install()->then(
            fn () => $this->set(['state' => 'installed']),
            fn (\Throwable $e) => $this->set(['state' => 'error', 'error' => 'the update failed: ' . $e->getMessage()]),
        );
        return $this->state;
    }

    /** Starts the new executable and quits this one. */
    public function restart(): bool
    {
        if ($this->state['state'] !== 'installed') {
            return false;
        }
        $this->app->updater()->relaunch();
        return true;
    }

    public function setAuto(bool $on): array
    {
        $this->app->options->set('autoUpdate', $on);
        $this->set(['auto' => $on]);
        if ($on && $this->state['state'] === 'available') {
            $this->install();
        }
        return $this->state;
    }

    /** @param array<string, mixed> $changes */
    private function set(array $changes): void
    {
        $this->state = $changes + $this->state;
        if (($changes['state'] ?? 'error') !== 'error') {
            unset($this->state['error']);
        }
        if (($changes['state'] ?? null) !== null) {
            fwrite(STDERR, "[update] {$this->state['state']}" . (isset($this->state['version']) ? " {$this->state['version']}" : '')
                . (isset($this->state['error']) ? ": {$this->state['error']}" : '') . "\n");
        }
        $this->app->send('update.state', $this->state);
    }
}
