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


function add_event_handler(
    mixed $event,
    mixed $func,
    int $priority = EVENT_HANDLER_PRIORITY_NEUTRAL,
    ?string $include_path = null
): bool {
    return \Piwigo\Core\ServiceLocator::get(\Piwigo\Plugin\PluginService::class)->addEventHandler(is_scalar($event) ? (string) $event : '', $func, $priority, $include_path);
}

function remove_event_handler(
    mixed $event,
    mixed $func,
    int $priority = EVENT_HANDLER_PRIORITY_NEUTRAL
): bool {
    return \Piwigo\Core\ServiceLocator::get(\Piwigo\Plugin\PluginService::class)->removeEventHandler(is_scalar($event) ? (string) $event : '', $func, $priority);
}

function trigger_change(string $event, mixed ...$args): mixed
{
    return \Piwigo\Core\ServiceLocator::get(\Piwigo\Plugin\PluginService::class)->triggerChange($event, ...$args);
}

function trigger_notify(string $event, mixed ...$args): void
{
    \Piwigo\Core\ServiceLocator::get(\Piwigo\Plugin\PluginService::class)->triggerNotify($event, ...$args);
}

function set_plugin_data(string $plugin_id, mixed &$data): bool
{
    return \Piwigo\Core\ServiceLocator::get(\Piwigo\Plugin\PluginService::class)->setPluginData($plugin_id, $data);
}

function &get_plugin_data(string $plugin_id): mixed
{
    return \Piwigo\Core\ServiceLocator::get(\Piwigo\Plugin\PluginService::class)->getPluginData($plugin_id);
}

/** @return array<array<string,mixed>> */
function get_db_plugins(?string $state = '', ?string $id = ''): array
{
    return \Piwigo\Core\ServiceLocator::get(\Piwigo\Plugin\PluginService::class)->getDbPlugins($state, $id);
}

/** @param array<string,mixed> $plugin */
function load_plugin(array $plugin): void
{
    \Piwigo\Core\ServiceLocator::get(\Piwigo\Plugin\PluginService::class)->loadPlugin($plugin);
}

/** @param array<string,mixed> $plugin */
function autoupdate_plugin(array &$plugin): void
{
    \Piwigo\Core\ServiceLocator::get(\Piwigo\Plugin\PluginService::class)->autoupdatePlugin($plugin);
}

function load_plugins(): void
{
    \Piwigo\Core\ServiceLocator::get(\Piwigo\Plugin\PluginService::class)->loadPlugins();
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
