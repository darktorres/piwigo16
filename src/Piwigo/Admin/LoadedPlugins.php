<?php

declare(strict_types=1);

// +-----------------------------------------------------------------------+
// | This file is part of Piwigo.                                          |
// |                                                                       |
// | For copyright and license information, please view the COPYING.txt    |
// | file that was distributed with this source code.                      |
// +-----------------------------------------------------------------------+

namespace Piwigo\Admin;

use LogicException;

/**
 * Holds the current request's loaded-plugins map.
 *
 * This is a container-shared instance: the writer
 * (`Admin\LoadedPluginsMiddleware` -- populated from
 * `PluginConfig\PluginRegistry::getActiveIds()`/`getManifest()` after
 * `Http\Middleware\PluginBootstrapMiddleware`'s own `bootActive()` call
 * runs earlier in the same pipeline, replacing `Admin\PluginLoader::
 * loadPlugins()`'s former direct writes) receives this instance via the
 * same container-shared resolution every other real consumer uses. All 3
 * real readers (`BatchManagerUnitPageRenderer`, `IntroSubController`,
 * `PluginSubController`) receive it via constructor injection.
 */
final class LoadedPlugins
{
    /**
     * Element shape matches `Projection\Plugin::toArray()`'s shape --
     * `Admin\LoadedPluginsMiddleware`'s own real writer builds each entry
     * that way, one per `PluginRegistry::getActiveIds()` entry.
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
            throw new LogicException('LoadedPlugins not initialised -- call Piwigo\Admin\LoadedPluginsMiddleware::process() first.');
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
