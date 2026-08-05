<?php

declare(strict_types=1);

// +-----------------------------------------------------------------------+
// | This file is part of Piwigo.                                          |
// +-----------------------------------------------------------------------+

namespace Piwigo\Admin;

use LogicException;
use Piwigo\Activity\ActivityService;
use Piwigo\Auth\AccessControl;
use Piwigo\Config\CurrentConfig;
use Piwigo\Core\ActivitySystem;
use Piwigo\Core\CurrentPaths;
use Piwigo\Core\PageState;
use Piwigo\Core\VersionHelper;
use Piwigo\Core\WsContext;
use Piwigo\Db\DbConnection;
use Piwigo\Db\EntityManagerFactory;
use Piwigo\Event\Lifecycle\PluginsLoaded;
use Piwigo\PluginConfig\EventDispatcher;
use Piwigo\PluginConfig\PluginEntity;

/**
 * Loads all currently-active plugins on every request (was
 * include/functions_plugins.inc.php's load_plugins()/load_plugin()/
 * autoupdate_plugin()) -- lives under Admin\, not PluginConfig\, because
 * autoupdatePlugin() depends on PluginMaintain (Admin, deptrac L4); putting
 * this in PluginConfig (L2b, alongside EventDispatcher/PluginRepository)
 * would make an L2b class depend on L4, the wrong direction. Same
 * "administrative machinery invoked from non-admin-page contexts"
 * precedent as PluginMaintain itself.
 */
final class PluginLoader
{
    /**
     * Base directory of plugins, trailing slash included.
     *
     * P23 batch 8f-4: replaces the PHPWG_PLUGINS_PATH define (formerly
     * include/functions.inc.php's top-level `define('PHPWG_PLUGINS_PATH',
     * PHPWG_ROOT_PATH . 'plugins/')`, file deleted; verified: no frozen
     * install/db or install/upgrade_*.php script reads that constant, so
     * no legacy-bootstrap define needs to survive). A static method rather
     * than a class constant because the underlying value (formerly
     * PHPWG_ROOT_PATH, now Piwigo\Core\CurrentPaths) isn't guaranteed
     * resolved at class-linking time -- same reasoning as
     * PhotosAddDirectPageRenderer::baseUrl() (P23 batch 8f-1). Lives here
     * (every real reader is L4Integration -- Admin/Admin\Extensions/
     * Controller -- or a root entry script) on the class that owns
     * per-request plugin loading.
     */
    public static function pluginsPath(): string
    {
        return CurrentPaths::get()->plugins;
    }

    /**
     * Loads all the registered plugins.
     */
    public static function loadPlugins(LoadedPlugins $loadedPlugins, EventDispatcher $eventDispatcher, ActivityService $activityService, CurrentConfig $currentConfig, WsContext $wsContext, AccessControl $accessControl, PageState $pageState): void
    {
        $loadedPlugins->set([]);
        if ($currentConfig->enablePlugins()) {
            $plugins = EntityManagerFactory::build(DbConnection::build())->getRepository(PluginEntity::class)->getDbPlugins('active');
            foreach ($plugins as $plugin) {// include main from a function to avoid using same function context
                // Unboxed back to array here -- loadPlugin()/autoupdatePlugin()
                // mutate $plugin['version'] in place through a by-reference
                // param, which a readonly Plugin object can't support (same
                // "unbox where genuinely needed" shape as
                // CategoryCatsRenderer's own unboxing).
                self::loadPlugin($plugin->toArray(), $loadedPlugins, $activityService, $wsContext, $accessControl, $pageState);
            }
            $eventDispatcher->dispatchNotify(new PluginsLoaded());
        }
    }

    /**
     * Loads a plugin in memory.
     * It performs autoupdate, includes the main.inc.php file and updates *$pwg_loaded_plugins*.
     *
     * @param array{id: string, state: string, version: string} $plugin -
     *   Projection\Plugin::toArray()'s own shape (its only caller,
     *   loadPlugins(), unboxes the repository's typed row there since this
     *   method needs mutable array semantics, not a readonly object)
     */
    private static function loadPlugin(array $plugin, LoadedPlugins $loadedPlugins, ActivityService $activityService, WsContext $wsContext, AccessControl $accessControl, PageState $pageState): void
    {
        $plugin_id = $plugin['id'];

        $file_name = self::pluginsPath() . $plugin_id . '/main.inc.php';
        if (file_exists($file_name)) {
            self::autoupdatePlugin($plugin, $activityService, $wsContext, $accessControl, $pageState);
            $loadedPlugins->add($plugin_id, $plugin);
            include_once $file_name;
        }
    }

    /**
     * Performs update task of a plugin.
     * Autoupdate is only performed if the plugin has a maintain.class.php file.
     *
     * @param array{id: string, state: string, version: string} $plugin will
     *   be updated if version changes - matches loadPlugin()'s own param
     *   shape (its only caller, already guards 'id' to string)
     */
    private static function autoupdatePlugin(array &$plugin, ActivityService $activityService, WsContext $wsContext, AccessControl $accessControl, PageState $pageState): void
    {
        $plugin_id = $plugin['id'];

        // try to find the filesystem version in lines 2 to 10 of main.inc.php
        $fh = fopen(self::pluginsPath() . $plugin_id . '/main.inc.php', 'r');
        $fs_version = null;
        $i = -1;

        if ($fh !== false) {
            while (($line = fgets($fh)) !== false && $fs_version === null && $i < 10) {
                $i++;
                if ($i < 2) {
                    continue;
                } // first lines are typically "<?php" and "/*"

                if ((bool) preg_match('/Version:\\s*([\\w.-]+)/', $line, $matches)) {
                    $fs_version = $matches[1];
                }
            }

            fclose($fh);
        }

        $plugin_version = $plugin['version'];

        // if version is auto (dev) or superior
        if ($fs_version !== null && (
            $fs_version === 'auto' || $plugin_version === 'auto' ||
              (bool) VersionHelper::safeVersionCompare($plugin_version, $fs_version, '<')
        )
        ) {
            $old_version = $plugin_version;
            $new_version = $fs_version;

            $plugin['version'] = $fs_version;

            $maintain_file = self::pluginsPath() . $plugin_id . '/maintain.class.php';

            // autoupdate is applicable only to plugins with 2.7 architecture
            if (file_exists($maintain_file)) {
                // call update method
                include_once $maintain_file;

                $classname = $plugin_id . '_maintain';

                // piwigo-videojs and piwigo-openstreetmap unfortunately have a "-" in their folder
                // name (=plugin_id) and a class name can't have a "-". So we have to replace with a "_"
                $classname = str_replace('-', '_', $classname);

                $plugin_maintain = new $classname($plugin_id, $wsContext, $accessControl);
                if (! $plugin_maintain instanceof PluginMaintain) {
                    throw new LogicException("PluginLoader::autoupdatePlugin(): {$classname} does not extend PluginMaintain");
                }
                // $old_version (pre-mutation), not $plugin['version'] (already
                // overwritten with $fs_version above) -- passing the mutated
                // value here made update() always see old==new, defeating any
                // version-gated migration logic in a plugin's own update().
                // update()'s $errors is only known as array<int, string> (a
                // plugin's own update() could in principle reassign with
                // non-sequential keys), narrower than PageState::$errors'
                // list<string> -- round-trip through a local var and
                // re-index on the way back in.
                $errors = $pageState->errors;
                $plugin_maintain->update($old_version, $fs_version, $errors);
                $pageState->errors = array_values($errors);
            }

            // update database (only on production). We want to avoid registering an "auto" to "auto" update,
            // which happens for each "version=auto" plugin on each page load.
            if ($new_version !== $old_version) {
                $conn = DbConnection::build();
                EntityManagerFactory::build($conn)->getRepository(PluginEntity::class)
                    ->updateVersion($plugin_id, $fs_version);

                $activityService
                    ->record('system', ActivitySystem::Plugin, 'autoupdate', [
                        'plugin_id' => $plugin_id,
                        'from_version' => $old_version,
                        'to_version' => $new_version,
                    ]);
            }
        }
    }
}
