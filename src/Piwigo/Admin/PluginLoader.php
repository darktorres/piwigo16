<?php

declare(strict_types=1);

// +-----------------------------------------------------------------------+
// | This file is part of Piwigo.                                          |
// +-----------------------------------------------------------------------+

namespace Piwigo\Admin;

use Piwigo\Core\Paths;

/**
 * `pluginsPath()` is the last surviving member of this class -- its own
 * former per-request plugin-loading methods (`loadPlugins()`/
 * `loadPlugin()`/`autoupdatePlugin()`, `was include/functions_plugins.
 * inc.php`'s `load_plugins()`/`load_plugin()`/`autoupdate_plugin()`)
 * were retired in P27.4, replaced by `PluginConfig\PluginRegistry::
 * bootActive()` (`Bootstrap\RequestBootstrap::connect()`). This method
 * itself stays: `Admin\Extensions\ExtensionScanner`/`ExtensionType`/
 * `ExtensionLifecycle` and `Controller\Admin\PluginSubController` all
 * still resolve the real `plugins/` directory through it, serving the
 * legacy/external-PEM-catalog admin machinery that stays explicitly out
 * of scope for the P27 plugin/theme contract rewrite (see
 * `Admin\Extensions\ExtensionScanner`'s own docblock).
 */
final class PluginLoader
{
    /**
     * Base directory of plugins, trailing slash included.
     *
     * A static method rather than a class constant because the underlying
     * `Paths` param isn't guaranteed resolved at class-linking time. Every
     * real reader lives in L4Integration (Admin, Admin\Extensions,
     * Controller) or a root entry script, on the class that owns
     * per-request plugin loading.
     */
    public static function pluginsPath(Paths $paths): string
    {
        return $paths->plugins;
    }
}
