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
 * Static accessor for the current request's loaded-plugins map -- Phase 2
 * global-residual sweep, replacing the legacy `global $pwg_loaded_plugins;`
 * bridge. Same shape as this codebase's other per-request singleton
 * facades (`Template\CurrentTemplate`, `Users\CurrentUser`): a
 * self-managed static instance with `get()`/`set()`/`isInitialized()`/
 * `reset()`, not constructor-injected DI. The writer
 * (`PluginLoader::loadPlugins()`/`loadPlugin()`) and all 3 real readers
 * are `Piwigo\Admin`/`Piwigo\Controller\Admin` -- both `L4Integration` in
 * `deptrac.yaml` -- so unlike `Core\FilterState`, there's no cross-layer
 * placement decision to make here; this lives alongside its writer.
 */
final class LoadedPlugins
{
    /**
     * @var array<string, array<string, mixed>>|null
     */
    private static ?array $plugins = null;

    /**
     * @return array<string, array<string, mixed>>
     */
    public static function get(): array
    {
        if (self::$plugins === null) {
            throw new \LogicException('LoadedPlugins not initialised -- call Piwigo\Admin\PluginLoader::loadPlugins() first.');
        }

        return self::$plugins;
    }

    /**
     * @param array<string, array<string, mixed>> $plugins
     */
    public static function set(array $plugins): void
    {
        self::$plugins = $plugins;
    }

    /**
     * @param array<string, mixed> $plugin
     */
    public static function add(string $pluginId, array $plugin): void
    {
        self::$plugins ??= [];
        self::$plugins[$pluginId] = $plugin;
    }

    public static function isInitialized(): bool
    {
        return self::$plugins !== null;
    }

    /**
     * Test-only -- restricted to tests/ by an arch test, mirroring the
     * equivalent guard on SessionService's/Config's own reset() methods.
     */
    public static function reset(): void
    {
        self::$plugins = null;
    }
}
