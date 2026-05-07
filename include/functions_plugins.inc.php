<?php

declare(strict_types=1);

use Piwigo\Admin\PluginMaintain;
use Piwigo\Admin\ThemeMaintain;
use Piwigo\Plugins\EventDispatcher;
use Piwigo\Plugins\LoadedPluginRegistry;

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


// The 6 functions below call EventDispatcher/LoadedPluginRegistry directly —
// they are invoked from file-level code before Kernel::boot() and must never
// go through ServiceLocator. The PluginService wraps them for callers that
// prefer DI, but the free-function delegates bypass the container.

function add_event_handler(
    mixed $event,
    mixed $func,
    int $priority = EVENT_HANDLER_PRIORITY_NEUTRAL,
    ?string $include_path = null
): bool {
    return EventDispatcher::addListener(is_scalar($event) ? (string) $event : '', $func, $priority, $include_path);
}

function remove_event_handler(
    mixed $event,
    mixed $func,
    int $priority = EVENT_HANDLER_PRIORITY_NEUTRAL
): bool {
    return EventDispatcher::removeListener(is_scalar($event) ? (string) $event : '', $func, $priority);
}

function trigger_change(string $event, mixed ...$args): mixed
{
    return EventDispatcher::dispatch($event, ...$args);
}

function trigger_notify(string $event, mixed ...$args): void
{
    EventDispatcher::notify($event, ...$args);
}

function set_plugin_data(string $plugin_id, mixed &$data): bool
{
    return LoadedPluginRegistry::setData($plugin_id, $data);
}

function &get_plugin_data(string $plugin_id): mixed
{
    return LoadedPluginRegistry::getData($plugin_id);
}

/**
 * Factory helper used by PluginMaintain dispatch in src/ — keeps dynamic
 * instantiation in include/ (not subject to piwigo.noDynamicNew in src/).
 *
 * @param class-string<PluginMaintain> $classname
 */
function instantiate_plugin_maintain(string $classname, string $plugin_id): PluginMaintain
{
    return new $classname($plugin_id);
}

/**
 * Factory helper for ThemeMaintain dispatch.
 *
 * @param class-string<ThemeMaintain> $classname
 */
function instantiate_theme_maintain(string $classname, string $theme_id): ThemeMaintain
{
    return new $classname($theme_id);
}
