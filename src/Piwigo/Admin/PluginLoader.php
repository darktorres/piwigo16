<?php

declare(strict_types=1);

// +-----------------------------------------------------------------------+
// | This file is part of Piwigo.                                          |
// +-----------------------------------------------------------------------+

namespace Piwigo\Admin;

use Piwigo\Core\Paths;

/**
 * `pluginsPath()` is the last surviving member of this class.
 * `Admin\Extensions\ExtensionScanner`/`ExtensionType`/
 * `ExtensionLifecycle` and `Controller\Admin\PluginSubController` all
 * resolve the real `plugins/` directory through it, serving the
 * legacy/external-PEM-catalog admin machinery that stays explicitly out
 * of scope for the plugin/theme contract rewrite (see
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
