<?php

declare(strict_types=1);

use Piwigo\Plugins\EventDispatcher;
use Piwigo\Plugins\LoadedPluginRegistry;

// +-----------------------------------------------------------------------+
// | This file is part of Piwigo.                                          |
// |                                                                       |
// | For copyright and license information, please view the COPYING.txt    |
// | file that was distributed with this source code.                      |
// +-----------------------------------------------------------------------+

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
