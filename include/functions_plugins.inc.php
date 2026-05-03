<?php

declare(strict_types=1);
// +-----------------------------------------------------------------------+
// | This file is part of Piwigo.                                          |
// |                                                                       |
// | For copyright and license information, please view the COPYING.txt    |
// | file that was distributed with this source code.                      |
// +-----------------------------------------------------------------------+

/**
 * @package functions\plugins
 */


/** base directory of plugins */
define('PHPWG_PLUGINS_PATH', PHPWG_ROOT_PATH.'plugins/');
/** default priority for plugins handlers */
define('EVENT_HANDLER_PRIORITY_NEUTRAL', 50);


// Vendor plugins/themes do `class foo_maintain extends PluginMaintain`
// without a namespace prefix, so PluginMaintain must be reachable in the
// global namespace. Aliases — not duplicate class definitions — keep the
// signatures in lockstep with src/Piwigo/Admin/{Plugin,Theme}Maintain.php
// so vendor LSP checks pass against the real (relaxed) parent.
class_alias(\Piwigo\Admin\PluginMaintain::class, 'PluginMaintain');
class_alias(\Piwigo\Admin\ThemeMaintain::class, 'ThemeMaintain');


/**
 * Register an event handler.
 *
 * @param string $event the name of the event to listen to
 * @param callable|string $func the callback function
 * @param int $priority greater priority will be executed at last
 * @param string $include_path file to include before executing the callback
 * @return bool false is handler already exists
 */
function add_event_handler(
    $event,
    $func,
    $priority = EVENT_HANDLER_PRIORITY_NEUTRAL,
    $include_path = null
): bool {
    global $pwg_event_handlers;

    if (isset($pwg_event_handlers[$event][$priority])) {
        foreach ($pwg_event_handlers[$event][$priority] as $handler) {
            if ($handler['function'] == $func) {
                return false;
            }
        }
    }

    $pwg_event_handlers[$event][$priority][] = [
      'function' => $func,
      'include_path' => is_string($include_path) ? $include_path : null,
      ];

    ksort($pwg_event_handlers[$event]);
    return true;
}

/**
 * Removes an event handler.
 * @see add_event_handler()
 *
 * @param string $event
 * @param Callable $func
 * @param int $priority
 */
function remove_event_handler(
    $event,
    $func,
    $priority = EVENT_HANDLER_PRIORITY_NEUTRAL
): bool {
    global $pwg_event_handlers;

    if (!isset($pwg_event_handlers[$event][$priority])) {
        return false;
    }
    for ($i = 0; $i < count($pwg_event_handlers[$event][$priority]); $i++) {
        if ($pwg_event_handlers[$event][$priority][$i]['function'] == $func) {
            unset($pwg_event_handlers[$event][$priority][$i]);
            $pwg_event_handlers[$event][$priority] =
              array_values($pwg_event_handlers[$event][$priority]);

            if (empty($pwg_event_handlers[$event][$priority])) {
                unset($pwg_event_handlers[$event][$priority]);
                if (empty($pwg_event_handlers[$event])) {
                    unset($pwg_event_handlers[$event]);
                }
            }
            return true;
        }
    }
    return false;
}

/**
 * Triggers a modifier event and calls all registered event handlers.
 * trigger_change() is used as a modifier: it allows to transmit _$data_
 * through all handlers, thus each handler MUST return a value,
 * optional _$args_ are not transmitted.
 *
 * @since 2.6
 *
 * @param string $event the event name
 * @param mixed ...$args data to transmit to all handlers; first arg is the main data
 * @return mixed the modified data
 */
function trigger_change(string $event, mixed ...$args): mixed
{
    global $pwg_event_handlers;

    $data = $args[0] ?? null;
    if (isset($pwg_event_handlers['trigger'])) {// debugging
        trigger_notify(
            'trigger',
            ['type' => 'event', 'event' => $event, 'data' => $data]
        );
    }

    if (!isset($pwg_event_handlers[$event])) {
        return $data;
    }

    foreach ($pwg_event_handlers[$event] as $priority => $handlers) {
        foreach ($handlers as $handler) {
            $args[0] = $data;

            if (!empty($handler['include_path'])) {
                include_once($handler['include_path']);
            }

            $data = call_user_func_array($handler['function'], $args);
        }
    }

    if (isset($pwg_event_handlers['trigger'])) {// debugging
        trigger_notify(
            'trigger',
            ['type' => 'post_event', 'event' => $event, 'data' => $data]
        );
    }

    return $data;
}

/**
 * Triggers a notifier event and calls all registered event handlers.
 * trigger_notify() is only used as a notifier, no modification of data is possible
 *
 * @since 2.6
 *
 * @param string $event
 */
function trigger_notify(string $event, mixed ...$args): void
{
    global $pwg_event_handlers;

    if (isset($pwg_event_handlers['trigger']) and $event != 'trigger') {// debugging - avoid recursive calls
        trigger_notify(
            'trigger',
            ['type' => 'action', 'event' => $event, 'data' => null]
        );
    }

    if (!isset($pwg_event_handlers[$event])) {
        return;
    }

    foreach ($pwg_event_handlers[$event] as $priority => $handlers) {
        foreach ($handlers as $handler) {
            if (!empty($handler['include_path'])) {
                include_once($handler['include_path']);
            }

            call_user_func_array($handler['function'], $args);
        }
    }
}

/**
 * Saves some data with the associated plugin id, data are only available
 * during script lifetime.
 * @depracted 2.6
 *
 * @param string $plugin_id
 * @param mixed &$data
 */
function set_plugin_data($plugin_id, &$data): bool
{
    global $pwg_loaded_plugins;
    if (isset($pwg_loaded_plugins[$plugin_id])) {
        $pwg_loaded_plugins[$plugin_id]['plugin_data'] = &$data;
        return true;
    }
    return false;
}

/**
 * Retrieves plugin data saved previously with set_plugin_data.
 * @see set_plugin_data()
 * @depracted 2.6
 *
 * @param string $plugin_id
 * @return mixed
 */
function &get_plugin_data($plugin_id)
{
    global $pwg_loaded_plugins;
    return $pwg_loaded_plugins[$plugin_id]['plugin_data'] ?? null;
}

/**
 * Returns an array of plugins defined in the database.
 *
 * @param string $state optional filter
 * @param string $id returns only data about given plugin
 */
/** @return array<array<string,mixed>> */
function get_db_plugins(?string $state = '', ?string $id = ''): array
{
    $query = '
SELECT * FROM '.PLUGINS_TABLE;
    $clauses = [];
    if (!empty($state)) {
        $clauses[] = 'state=\''.$state.'\'';
    }
    if (!empty($id)) {
        $clauses[] = 'id="'.$id.'"';
    }
    if (count($clauses)) {
        $query .= '
  WHERE '. implode(' AND ', $clauses);
    }

    return query2array($query);
}

/**
 * Loads a plugin in memory.
 * It performs autoupdate, includes the main.inc.php file and updates *$pwg_loaded_plugins*.
 *
 * @param array $plugin
 */
/** @param array<string,mixed> $plugin */
function load_plugin(array $plugin): void
{
    $plugin_id = is_scalar($plugin['id']) ? (string) $plugin['id'] : '';
    $file_name = PHPWG_PLUGINS_PATH.$plugin_id.'/main.inc.php';
    if (file_exists($file_name)) {
        autoupdate_plugin($plugin);
        global $pwg_loaded_plugins;
        $pwg_loaded_plugins[$plugin_id] = $plugin;
        include_once($file_name);
    }
}

/**
 * Performs update task of a plugin.
 * Autoupdate is only performed if the plugin has a maintain.class.php file.
 *
 * @since 2.7
 *
 * @param array &$plugin (id, version, state) will be updated if version changes
 */
/** @param array<string,mixed> $plugin */
function autoupdate_plugin(array &$plugin): void
{
    $plugin_id = is_scalar($plugin['id']) ? (string) $plugin['id'] : '';
    // try to find the filesystem version in lines 2 to 10 of main.inc.php
    $fh = fopen(PHPWG_PLUGINS_PATH.$plugin_id.'/main.inc.php', 'r');
    $fs_version = null;
    $i = -1;

    while ($fh !== false && ($line = fgets($fh)) !== false && $fs_version == null && $i < 10) {
        $i++;
        if ($i < 2) {
            continue;
        } // first lines are typically "<?php" and "/*"

        if (preg_match('/Version:\\s*([\\w.-]+)/', $line, $matches)) {
            $fs_version = $matches[1];
        }
    }

    if ($fh !== false) {
        fclose($fh);
    }

    $plugin_version = is_scalar($plugin['version']) ? (string) $plugin['version'] : '';
    // if version is auto (dev) or superior
    if ($fs_version != null && (
        $fs_version == 'auto' || $plugin_version == 'auto' ||
          safe_version_compare($plugin_version, $fs_version, '<')
    )
    ) {
        $old_version = $plugin_version;
        $new_version = $fs_version;

        $plugin['version'] = $fs_version;

        $maintain_file = PHPWG_PLUGINS_PATH.$plugin_id.'/maintain.class.php';

        // autoupdate is applicable only to plugins with 2.7 architecture
        if (file_exists($maintain_file)) {
            // call update method
            include_once($maintain_file);

            $classname = $plugin_id.'_maintain';

            // piwigo-videojs and piwigo-openstreetmap unfortunately have a "-" in their folder
            // name (=plugin_id) and a class name can't have a "-". So we have to replace with a "_"
            $classname = str_replace('-', '_', $classname);

            if (class_exists($classname) && is_a($classname, \Piwigo\Admin\PluginMaintain::class, true)) {
                $plugin_maintain = new $classname($plugin_id);
                $errors = &\Piwigo\Core\PageState::current()->errors;
                $plugin_maintain->update($old_version, $fs_version, $errors);
            }
        }

        // update database (only on production). We want to avoid registering an "auto" to "auto" update,
        // which happens for each "version=auto" plugin on each page load.
        if ($new_version != $old_version) {
            $query = '
UPDATE '. PLUGINS_TABLE .'
  SET version = "'. $fs_version .'"
  WHERE id = "'. $plugin_id .'"
;';
            pwg_query($query);

            pwg_activity('system', ACTIVITY_SYSTEM_PLUGIN, 'autoupdate', ['plugin_id' => $plugin_id, 'from_version' => $old_version, 'to_version' => $new_version]);
        }
    }
}

/**
 * Loads all the registered plugins.
 */
function load_plugins(): void
{
    global $pwg_loaded_plugins;
    $pwg_loaded_plugins = [];
    if (\Piwigo\Config\Config::enablePlugins()) {
        $plugins = get_db_plugins('active');
        foreach ($plugins as $plugin) {// include main from a function to avoid using same function context
            load_plugin($plugin);
        }
        trigger_notify('plugins_loaded');
    }
}

/**
 * Factory helper used by PluginMaintain dispatch in src/ — keeps dynamic
 * instantiation in include/ (not subject to piwigo.noDynamicNew in src/).
 *
 * @param class-string<\Piwigo\Admin\PluginMaintain> $classname
 */
function instantiate_plugin_maintain(string $classname, string $plugin_id): \Piwigo\Admin\PluginMaintain
{
    return new $classname($plugin_id);
}

/**
 * Factory helper for ThemeMaintain dispatch.
 *
 * @param class-string<\Piwigo\Admin\ThemeMaintain> $classname
 */
function instantiate_theme_maintain(string $classname, string $theme_id): \Piwigo\Admin\ThemeMaintain
{
    return new $classname($theme_id);
}
