<?php

declare(strict_types=1);

// +-----------------------------------------------------------------------+
// | This file is part of Piwigo.                                          |
// +-----------------------------------------------------------------------+

namespace Piwigo\Admin;

use Piwigo\Core\ActivitySystem;
use Piwigo\Db\DbConnection;
use Piwigo\PluginConfig\PluginRepository;

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
     * than a class constant because PHPWG_ROOT_PATH is itself a runtime
     * define whose availability at class-linking time can't be guaranteed
     * on every path -- same reasoning as
     * PhotosAddDirectPageRenderer::baseUrl() (P23 batch 8f-1). Lives here
     * (every real reader is L4Integration -- Admin/Admin\Extensions/
     * Controller -- or a root entry script) on the class that owns
     * per-request plugin loading.
     */
    public static function pluginsPath(): string
    {
        return PHPWG_ROOT_PATH . 'plugins/';
    }

    /**
     * Loads all the registered plugins.
     */
    public static function loadPlugins(): void
    {
        /**
         * @var array<string, mixed> $conf
         * @var array<string, array<string, mixed>> $pwg_loaded_plugins
         */
        global $conf, $pwg_loaded_plugins;
        $pwg_loaded_plugins = [];
        if ((bool) $conf['enable_plugins']) {
            $plugins = new PluginRepository(DbConnection::build())->getDbPlugins('active');
            foreach ($plugins as $plugin) {// include main from a function to avoid using same function context
                self::loadPlugin($plugin);
            }
            trigger_notify('plugins_loaded');
        }
    }

    /**
     * Loads a plugin in memory.
     * It performs autoupdate, includes the main.inc.php file and updates *$pwg_loaded_plugins*.
     *
     * @param array<string, string|null> $plugin - matches
     *   PluginRepository::getDbPlugins()'s real element type (its only
     *   caller, loadPlugins(), passes rows straight from there)
     */
    private static function loadPlugin(array $plugin): void
    {
        $plugin_id = $plugin['id'] ?? null;
        if (! is_string($plugin_id)) {
            // 'id' is a NOT NULL varchar primary key in the plugins table; a
            // non-string value here means the row is not usable.
            return;
        }

        $file_name = self::pluginsPath() . $plugin_id . '/main.inc.php';
        if (file_exists($file_name)) {
            self::autoupdatePlugin($plugin);
            /** @var array<string, array<string, mixed>> $pwg_loaded_plugins */
            global $pwg_loaded_plugins;
            $pwg_loaded_plugins[$plugin_id] = $plugin;
            include_once $file_name;
        }
    }

    /**
     * Performs update task of a plugin.
     * Autoupdate is only performed if the plugin has a maintain.class.php file.
     *
     * @param array<string, string|null> $plugin (id, version, state) will be
     *   updated if version changes - matches PluginRepository::getDbPlugins()'s
     *   real element type (its only caller, loadPlugin(), already guards
     *   'id' to string)
     */
    private static function autoupdatePlugin(array &$plugin): void
    {
        $plugin_id = $plugin['id'] ?? null;
        if (! is_string($plugin_id)) {
            // 'id' is a NOT NULL varchar primary key in the plugins table; a
            // non-string value here means the row is not usable.
            return;
        }

        // try to find the filesystem version in lines 2 to 10 of main.inc.php
        $fh = fopen(self::pluginsPath() . $plugin_id . '/main.inc.php', 'r');
        $fs_version = null;
        $i = -1;

        if ($fh !== false) {
            while (($line = fgets($fh)) !== false && $fs_version == null && $i < 10) {
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

        // 'version' is a NOT NULL varchar column defaulting to '0'; fall back
        // to that same default if the row value is ever missing/non-string.
        $plugin_version = $plugin['version'] ?? null;
        $plugin_version = is_string($plugin_version) ? $plugin_version : '0';

        // if version is auto (dev) or superior
        if ($fs_version != null && (
            $fs_version == 'auto' || $plugin_version == 'auto' ||
              (bool) \Piwigo\Core\VersionHelper::safeVersionCompare($plugin_version, $fs_version, '<')
        )
        ) {
            $old_version = $plugin_version;
            $new_version = $fs_version;

            $plugin['version'] = $fs_version;

            $maintain_file = self::pluginsPath() . $plugin_id . '/maintain.class.php';

            // autoupdate is applicable only to plugins with 2.7 architecture
            if (file_exists($maintain_file)) {
                /** @var array<string, mixed> $page */
                global $page;

                // call update method
                include_once $maintain_file;

                $classname = $plugin_id . '_maintain';

                // piwigo-videojs and piwigo-openstreetmap unfortunately have a "-" in their folder
                // name (=plugin_id) and a class name can't have a "-". So we have to replace with a "_"
                $classname = str_replace('-', '_', $classname);

                $plugin_maintain = new $classname($plugin_id);
                if (! $plugin_maintain instanceof PluginMaintain) {
                    throw new \LogicException("PluginLoader::autoupdatePlugin(): {$classname} does not extend PluginMaintain");
                }
                // $page['errors'] is initialized to an array by common.inc.php,
                // but PHPStan can't prove it here; re-narrow to list<string> to
                // match PluginMaintain::update()'s array<int, string> $errors.
                $page['errors'] = is_array($page['errors'] ?? null) ? array_values(array_filter($page['errors'], is_string(...))) : [];
                // $old_version (pre-mutation), not $plugin['version'] (already
                // overwritten with $fs_version above) -- passing the mutated
                // value here made update() always see old==new, defeating any
                // version-gated migration logic in a plugin's own update().
                $plugin_maintain->update($old_version, $fs_version, $page['errors']);
            }

            // update database (only on production). We want to avoid registering an "auto" to "auto" update,
            // which happens for each "version=auto" plugin on each page load.
            if ($new_version != $old_version) {
                new PluginRepository(DbConnection::build())->updateVersion($plugin_id, $fs_version);

                (new \Piwigo\Activity\ActivityService(new \Piwigo\Activity\ActivityRepository(\Piwigo\Db\DbConnection::build())))->record('system', ActivitySystem::Plugin, 'autoupdate', [
                    'plugin_id' => $plugin_id,
                    'from_version' => $old_version,
                    'to_version' => $new_version,
                ]);
            }
        }
    }
}
