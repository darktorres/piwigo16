<?php

declare(strict_types=1);

// +-----------------------------------------------------------------------+
// | This file is part of Piwigo.                                          |
// |                                                                       |
// | For copyright and license information, please view the COPYING.txt    |
// | file that was distributed with this source code.                      |
// +-----------------------------------------------------------------------+

namespace Piwigo\Admin;

/**
 * Holds the current request's loaded-plugins map -- Phase 2 global-residual
 * sweep, replacing the legacy `global $pwg_loaded_plugins;` bridge.
 * Container-shared instance (singleton-DI campaign, Phase 1): the writer
 * (`PluginLoader::loadPlugins()`/`loadPlugin()`, itself an entirely-static
 * helper outside `Bootstrap/`) receives this instance as an explicit
 * parameter from `RequestBootstrap` rather than resolving it via a static
 * accessor -- `PluginLoader` isn't itself part of this campaign's scope, so
 * threading the shared instance through as a plain parameter (the same
 * mechanism `Paths` already uses) avoids needing any static delegating
 * shim. All 3 real readers (`BatchManagerUnitPageRenderer`,
 * `IntroSubController`, `PluginSubController`) receive it via constructor
 * injection.
 */
final class LoadedPlugins
{
    /**
     * Element shape matches PluginLoader::loadPlugin()'s own $plugin param
     * (Projection\Plugin::toArray()'s shape) -- its only real writer.
     *
     * @var array<string, array{id: string, state: string, version: string}>|null
     */
    private ?array $plugins = null;

    /**
     * @return array<string, array{id: string, state: string, version: string}>
     */
    public function get(): array
    {
        if ($this->plugins === null) {
            throw new \LogicException('LoadedPlugins not initialised -- call Piwigo\Admin\PluginLoader::loadPlugins() first.');
        }

        return $this->plugins;
    }

    /**
     * @param array<string, array{id: string, state: string, version: string}> $plugins
     */
    public function set(array $plugins): void
    {
        $this->plugins = $plugins;
    }

    /**
     * @param array{id: string, state: string, version: string} $plugin
     */
    public function add(string $pluginId, array $plugin): void
    {
        $this->plugins ??= [];
        $this->plugins[$pluginId] = $plugin;
    }

    public function isInitialized(): bool
    {
        return $this->plugins !== null;
    }

    public function reset(): void
    {
        $this->plugins = null;
    }
}
