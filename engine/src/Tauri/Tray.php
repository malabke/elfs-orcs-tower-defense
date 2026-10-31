<?php

namespace JumPHP\Tauri;

use Evenement\EventEmitter;
use JumPHP\Kernel\Platform;

/**
 * An icon in the system's tray, made by App::tray(). Events: 'menu' (string $itemId), 'click' (string $button,
 * not on Linux).
 *
 *   $app->tray('main', ['tooltip' => 'Notes', 'menu' => [['id' => 'show', 'text' => 'Show'], '-', ['id' => 'quit', 'text' => 'Quit']]])
 *       ->on('menu', fn (string $item) => $item === 'quit' ? $app->quit() : $app->window()->show()->focus());
 */
final class Tray extends EventEmitter
{
    public function __construct(private readonly App $app, public readonly string $id)
    {
    }

    /** @internal App::tray(): (re)creates it with these options */
    public function setUp(array $options): void
    {
        $menu = [];
        foreach ((array)($options['menu'] ?? []) as $item) {
            $menu[] = $item === '-' ? ['separator' => true] : [
                'id' => (string)($item['id'] ?? ''),
                'text' => (string)($item['text'] ?? $item['id'] ?? ''),
                'enabled' => (bool)($item['enabled'] ?? true),
            ];
        }
        $command = ['cmd' => 'tray.create', 'tray' => $this->id];
        $icon = $options['icon'] ?? (is_file($this->app->config->path('assets/icon.png')) ? 'assets/icon.png' : null);
        if ($icon !== null) {
            $command['icon'] = Platform::isAbsolutePath((string)$icon) ? (string)$icon : $this->app->config->path((string)$icon);
        }
        foreach (['tooltip', 'title'] as $key) {
            if (isset($options[$key])) {
                $command[$key] = (string)$options[$key];
            }
        }
        if ($menu !== []) {
            $command['menu'] = $menu;
            $command['menu_on_left_click'] = (bool)($options['menu_on_left_click'] ?? true);
        }
        $this->app->sendCommand($command);
    }

    public function setTooltip(string $tooltip): self
    {
        $this->app->sendCommand(['cmd' => 'tray.set_tooltip', 'tray' => $this->id, 'tooltip' => $tooltip]);
        return $this;
    }

    /** New items (and options): the icon is made again. */
    public function update(array $options): self
    {
        $this->setUp($options);
        return $this;
    }

    public function remove(): void
    {
        $this->app->sendCommand(['cmd' => 'tray.remove', 'tray' => $this->id]);
        $this->app->forgetTray($this->id);
        $this->removeAllListeners();
    }
}
