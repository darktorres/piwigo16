<?php

declare(strict_types=1);

namespace Piwigo\Plugin;

use Psr\Container\ContainerInterface;

/**
 * Plugin contract for Piwigo 17+.
 *
 * One file per plugin (`plugins/<id>/src/Plugin.php`). Lifecycle methods
 * live on the same interface — there is no separate `Maintain` class.
 * Empty bodies are fine for plugins that don't need a given hook.
 *
 * Lifecycle ordering (enforced by `PluginRegistry`):
 *
 * - `install()` runs **once** on first install. Idempotent re-runs are
 *   permitted; the registry runs Doctrine migrations before this method.
 * - `activate()` runs every time the plugin is turned on. Idempotent.
 * - `deactivate()` runs every time the plugin is turned off. Symmetric
 *   to `activate()`.
 * - `update($oldVersion, $newVersion)` runs when the loader detects a
 *   version mismatch between filesystem and DB. Receives the previous
 *   installed version so the plugin can step-wise migrate.
 * - `uninstall()` runs **once** when the plugin is removed. The registry
 *   invokes `down()` on every applied migration before this method.
 *
 * `boot()` runs on every request after the registry has resolved the
 * dependency graph and registered the plugin's PSR-4 autoload. It is
 * the point where the plugin reaches into the container to register its
 * own services or wire up listeners that need late-bound state.
 *
 * Event subscription uses Symfony's `EventSubscriberInterface`-compatible
 * shape: a class-string key (the typed event class) mapped to one or
 * more handler method names with optional priorities. The handler method
 * runs on `$this`; both `$this`'s constructor deps and the handler
 * method's own extra parameters are autowired by PHP-DI on every
 * invocation. The first parameter is always the typed event object; any
 * additional typed parameters are resolved from the container at call
 * time.
 *
 * @see \Piwigo\Theme\ThemeInterface for the matching theme contract.
 */
interface PluginInterface
{
    public function getId(): string;

    public function getVersion(): string;

    public function getName(): string;

    public function boot(ContainerInterface $container): void;

    public function install(): void;

    public function activate(): void;

    public function deactivate(): void;

    public function uninstall(): void;

    public function update(string $oldVersion, string $newVersion): void;

    /**
     * Symfony `EventSubscriberInterface`-compatible event subscription.
     *
     * Return value shape (per key):
     *  - `class-string => string`                      — single handler, default priority 0
     *  - `class-string => array{0: string, 1?: int}`   — single handler with priority
     *  - `class-string => list<array{0: string, 1?: int}>` — multiple handlers on the same event
     *
     * Higher priority runs first.
     *
     * @return array<class-string, string|array{0: string, 1?: int}|list<array{0: string, 1?: int}>>
     */
    public function subscribedEvents(): array;
}
