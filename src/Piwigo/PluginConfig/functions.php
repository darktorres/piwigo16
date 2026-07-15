<?php

declare(strict_types=1);

// +-----------------------------------------------------------------------+
// | This file is part of Piwigo.                                          |
// +-----------------------------------------------------------------------+

use Piwigo\PluginConfig\EventDispatcher;

// Every declaration below is guarded with function_exists(): composer's
// autoload.files mechanism already loads this file once at process start,
// but any class_exists()/interface_exists() probe for a plausible-but-
// nonexistent FQCN under this namespace (e.g.
// tests/Arch/StructuralTest.php's "every Piwigo\ class ... has #[\Override]"
// test, which computes Piwigo\PluginConfig\functions from this file's own
// basename while walking every .php file under src/Piwigo/) makes
// composer's PSR-4 resolver try to autoload Piwigo\PluginConfig\functions,
// resolve it to this exact file via the PSR-4 basename match, and
// `include` it a second time -- confirmed via a direct repro
// (class_exists('Piwigo\PluginConfig\functions') alone triggers "Cannot
// redeclare function add_event_handler()"). The guard makes the second
// pass a safe no-op instead of a fatal.

/**
 * Register an event handler.
 *
 * @param string $event the name of the event to listen to
 * @param array<int, mixed>|object|string $func the callback function --
 *   deliberately not `callable`: PHP's native `callable` type validates
 *   callability eagerly at registration time, which the original never did
 *   (see Piwigo\PluginConfig\EventDispatcher::$handlers)
 * @param int $priority greater priority will be executed at last
 * @param string $include_path file to include before executing the callback
 * @return bool false is handler already exists
 */
if (! function_exists('add_event_handler')) {
    function add_event_handler(
        $event,
        $func,
        $priority = 50,
        $include_path = null
    ): bool {
        return EventDispatcher::get()->addEventHandler(
            $event,
            $func,
            $priority,
            is_string($include_path) ? $include_path : null,
        );
    }
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
if (! function_exists('trigger_change')) {
    function trigger_change($event, $data = null)
    {
        $args = func_get_args();
        array_shift($args);
        array_shift($args);

        return EventDispatcher::get()->triggerChange($event, $data, ...$args);
    }
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
if (! function_exists('trigger_notify')) {
    function trigger_notify($event, ...$args): void
    {
        EventDispatcher::get()->triggerNotify($event, ...$args);
    }
}
