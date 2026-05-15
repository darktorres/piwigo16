<?php

declare(strict_types=1);

namespace Piwigo\Theme;

use Psr\Container\ContainerInterface;

/**
 * Theme contract for Piwigo 17+.
 *
 * Mirrors {@see \Piwigo\Plugin\PluginInterface} plus theme-specific
 * methods for inheritance (`getParentId`, `loadParentCss`) and asset
 * resolution (`getAssetDir`, `getLocalHeadTemplate`).
 *
 * Themes use composition for inheritance: a child theme returns the
 * parent's id from `getParentId()`, and `ThemeRegistry` walks the chain
 * at boot to build the asset-resolution order. Asset/template paths
 * resolve by walking the chain and returning the first hit.
 *
 * Lifecycle methods follow the same idempotency contract as
 * `PluginInterface`. `boot()` is the equivalent of the legacy
 * `themeconf.inc.php` file-include side-effects, now with DI access.
 *
 * Asset kinds for `getAssetDir()`: `'img'`, `'icon'`, `'mimeIcon'`. The
 * map is open-ended — a theme may answer for additional kinds, but the
 * three above are the canonical core lookups.
 */
interface ThemeInterface
{
    public function getId(): string;

    public function getVersion(): string;

    public function getName(): string;

    public function getParentId(): ?string;

    public function loadParentCss(): bool;

    public function getAssetDir(string $kind): string;

    public function getLocalHeadTemplate(): ?string;

    public function boot(ContainerInterface $container): void;

    public function install(): void;

    public function activate(): void;

    public function deactivate(): void;

    public function uninstall(): void;

    public function update(string $oldVersion, string $newVersion): void;

    /**
     * Symfony `EventSubscriberInterface`-compatible event subscription.
     * See {@see \Piwigo\Plugin\PluginInterface::subscribedEvents()} for
     * the return-value shape contract.
     *
     * @return array<class-string, string|array{0: string, 1?: int}|list<array{0: string, 1?: int}>>
     */
    public function subscribedEvents(): array;
}
