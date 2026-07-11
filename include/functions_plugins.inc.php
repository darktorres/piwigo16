<?php

declare(strict_types=1);

// +-----------------------------------------------------------------------+
// | This file is part of Piwigo.                                          |
// |                                                                       |
// | For copyright and license information, please view the COPYING.txt    |
// | file that was distributed with this source code.                      |
// +-----------------------------------------------------------------------+

use Piwigo\Admin\PluginMaintain;
use Piwigo\Core\ActivitySystem;
use Piwigo\Db\Tables;

/** base directory of plugins */
define('PHPWG_PLUGINS_PATH', PHPWG_ROOT_PATH . 'plugins/');
/** default priority for plugins handlers */
define('EVENT_HANDLER_PRIORITY_NEUTRAL', 50);

/**
 * Register an event handler.
 *
 * @param string $event the name of the event to listen to
 * @param callable $func the callback function
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
    /** @var array<string, array<int, list<array{function: callable, include_path: string|null}>>> $pwg_event_handlers */
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
 * @param callable $func
 * @param int $priority
 */
function remove_event_handler(
    $event,
    $func,
    $priority = EVENT_HANDLER_PRIORITY_NEUTRAL
): bool {
    /** @var array<string, array<int, list<array{function: callable, include_path: string|null}>>> $pwg_event_handlers */
    global $pwg_event_handlers;

    if (! isset($pwg_event_handlers[$event][$priority])) {
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
 * @param string $event
 * @param mixed $data data to transmit to all handlers
 * @return mixed
 */
function trigger_change($event, $data = null)
{
    /** @var array<string, array<int, list<array{function: callable, include_path: string|null}>>> $pwg_event_handlers */
    global $pwg_event_handlers;

    if (isset($pwg_event_handlers['trigger'])) {// debugging
        trigger_notify(
            'trigger',
            [
                'type' => 'event',
                'event' => $event,
                'data' => $data,
            ]
        );
    }

    if (! isset($pwg_event_handlers[$event])) {
        return $data;
    }
    $args = func_get_args();
    array_shift($args);

    foreach ($pwg_event_handlers[$event] as $priority => $handlers) {
        foreach ($handlers as $handler) {
            $args[0] = $data;

            if (! empty($handler['include_path'])) {
                include_once $handler['include_path'];
            }

            $data = call_user_func_array($handler['function'], $args);
        }
    }

    if (isset($pwg_event_handlers['trigger'])) {// debugging
        trigger_notify(
            'trigger',
            [
                'type' => 'post_event',
                'event' => $event,
                'data' => $data,
            ]
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
 * @param mixed ...$args extra event data, forwarded to registered handlers
 */
function trigger_notify($event, ...$args): void
{
    /** @var array<string, array<int, list<array{function: callable, include_path: string|null}>>> $pwg_event_handlers */
    global $pwg_event_handlers;

    if (isset($pwg_event_handlers['trigger']) and $event != 'trigger') {// debugging - avoid recursive calls
        trigger_notify(
            'trigger',
            [
                'type' => 'action',
                'event' => $event,
                'data' => null,
            ]
        );
    }

    if (! isset($pwg_event_handlers[$event])) {
        return;
    }

    foreach ($pwg_event_handlers[$event] as $priority => $handlers) {
        foreach ($handlers as $handler) {
            if (! empty($handler['include_path'])) {
                include_once $handler['include_path'];
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
 * @param mixed $data
 */
function set_plugin_data($plugin_id, &$data): bool
{
    /** @var array<string, array<string, mixed>> $pwg_loaded_plugins */
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
    /** @var array<string, array<string, mixed>> $pwg_loaded_plugins */
    global $pwg_loaded_plugins;
    return $pwg_loaded_plugins[$plugin_id]['plugin_data'] ?? null;
}

/**
 * Returns an array of plugins defined in the database.
 *
 * @param string|null $state optional filter; null is treated the same as ''
 *   below (both are empty()) — admin/include/plugins.class.php passes null
 *   explicitly when it only wants to filter by $id
 * @param string $id returns only data about given plugin
 * @return list<array<string, string|null>> - thin wrapper around
 *   query2array($query) with no $key_name/$value_name, precisely typed as
 *   list<array<string, string|null>> (this driver never enables
 *   MYSQLI_OPT_INT_AND_FLOAT_NATIVE)
 */
function get_db_plugins($state = '', $id = ''): array
{
    $query = '
SELECT * FROM ' . Tables::plugins();
    $clauses = [];
    if (! empty($state)) {
        $clauses[] = 'state=\'' . $state . '\'';
    }
    if (! empty($id)) {
        $clauses[] = 'id="' . $id . '"';
    }
    if ((bool) count($clauses)) {
        $query .= '
  WHERE ' . implode(' AND ', $clauses);
    }

    return query2array($query);
}

/**
 * Loads a plugin in memory.
 * It performs autoupdate, includes the main.inc.php file and updates *$pwg_loaded_plugins*.
 *
 * @param array<string, string|null> $plugin - matches get_db_plugins()'s
 *   real element type (its only caller, load_plugins(), passes rows
 *   straight from there)
 */
function load_plugin(array $plugin): void
{
    $plugin_id = $plugin['id'] ?? null;
    if (! is_string($plugin_id)) {
        // 'id' is a NOT NULL varchar primary key in the plugins table; a
        // non-string value here means the row is not usable.
        return;
    }

    $file_name = PHPWG_PLUGINS_PATH . $plugin_id . '/main.inc.php';
    if (file_exists($file_name)) {
        autoupdate_plugin($plugin);
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
 * @since 2.7
 *
 * @param array<string, string|null> $plugin (id, version, state) will be
 *   updated if version changes - matches get_db_plugins()'s real element
 *   type (its only caller, load_plugin(), already guards 'id' to string)
 */
function autoupdate_plugin(array &$plugin): void
{
    $plugin_id = $plugin['id'] ?? null;
    if (! is_string($plugin_id)) {
        // 'id' is a NOT NULL varchar primary key in the plugins table; a
        // non-string value here means the row is not usable.
        return;
    }

    // try to find the filesystem version in lines 2 to 10 of main.inc.php
    $fh = fopen(PHPWG_PLUGINS_PATH . $plugin_id . '/main.inc.php', 'r');
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
          (bool) safe_version_compare($plugin_version, $fs_version, '<')
    )
    ) {
        $old_version = $plugin_version;
        $new_version = $fs_version;

        $plugin['version'] = $fs_version;

        $maintain_file = PHPWG_PLUGINS_PATH . $plugin_id . '/maintain.class.php';

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
                throw new \LogicException("autoupdate_plugin(): {$classname} does not extend PluginMaintain");
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
            $query = '
UPDATE ' . Tables::plugins() . '
  SET version = "' . $fs_version . '"
  WHERE id = "' . $plugin_id . '"
;';
            pwg_query($query);

            pwg_activity('system', ActivitySystem::Plugin, 'autoupdate', [
                'plugin_id' => $plugin_id,
                'from_version' => $old_version,
                'to_version' => $new_version,
            ]);
        }
    }
}

/**
 * Loads all the registered plugins.
 */
function load_plugins(): void
{
    /**
     * @var array<string, mixed> $conf
     * @var array<string, array<string, mixed>> $pwg_loaded_plugins
     */
    global $conf, $pwg_loaded_plugins;
    $pwg_loaded_plugins = [];
    if ((bool) $conf['enable_plugins']) {
        $plugins = get_db_plugins('active');
        foreach ($plugins as $plugin) {// include main from a function to avoid using same function context
            load_plugin($plugin);
        }
        trigger_notify('plugins_loaded');
    }
}
