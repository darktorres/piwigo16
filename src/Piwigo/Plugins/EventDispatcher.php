<?php

declare(strict_types=1);

namespace Piwigo\Plugins;

/**
 * Static event dispatcher replacing the $pwg_event_handlers global.
 *
 * After init(), $GLOBALS['pwg_event_handlers'] references the same storage as
 * self::$handlers, so legacy plugin code that writes to the global directly still works.
 */
final class EventDispatcher
{
    public const int NEUTRAL_PRIORITY = 50;

    /**
     * @var array<string, array<int, list<array{function: mixed, include_path: string|null}>>>
     * Public only for the $GLOBALS reference bridge — use the typed methods.
     */
    public static array $handlers = [];

    public static function init(): void
    {
        $GLOBALS['pwg_event_handlers'] = &self::$handlers;
    }

    public static function addListener(string $event, mixed $func, int $priority = self::NEUTRAL_PRIORITY, ?string $include_path = null): bool
    {
        if (isset(self::$handlers[$event][$priority])) {
            foreach (self::$handlers[$event][$priority] as $handler) {
                if ($handler['function'] == $func) {
                    return false;
                }
            }
        }

        self::$handlers[$event][$priority][] = [
            'function' => $func,
            'include_path' => $include_path,
        ];

        ksort(self::$handlers[$event]);
        return true;
    }

    public static function removeListener(string $event, mixed $func, int $priority = self::NEUTRAL_PRIORITY): bool
    {
        if (!isset(self::$handlers[$event][$priority])) {
            return false;
        }

        $bucket = &self::$handlers[$event][$priority];
        for ($i = 0; $i < count($bucket); $i++) {
            if ($bucket[$i]['function'] == $func) {
                unset($bucket[$i]);
                $bucket = array_values($bucket);

                if (empty($bucket)) {
                    unset(self::$handlers[$event][$priority]);
                    if (empty(self::$handlers[$event])) {
                        unset(self::$handlers[$event]);
                    }
                }
                return true;
            }
        }
        return false;
    }

    public static function dispatch(string $event, mixed ...$args): mixed
    {
        $data = $args[0] ?? null;

        if (isset(self::$handlers['trigger'])) {
            self::notify('trigger', ['type' => 'event', 'event' => $event, 'data' => $data]);
        }

        if (!isset(self::$handlers[$event])) {
            return $data;
        }

        foreach (self::$handlers[$event] as $handlers) {
            foreach ($handlers as $handler) {
                $args[0] = $data;
                if (!empty($handler['include_path'])) {
                    include_once($handler['include_path']);
                }
                if (is_callable($handler['function'])) {
                    $data = call_user_func_array($handler['function'], $args);
                }
            }
        }

        if (isset(self::$handlers['trigger'])) {
            self::notify('trigger', ['type' => 'post_event', 'event' => $event, 'data' => $data]);
        }

        return $data;
    }

    public static function notify(string $event, mixed ...$args): void
    {
        if (isset(self::$handlers['trigger']) && $event !== 'trigger') {
            self::notify('trigger', ['type' => 'action', 'event' => $event, 'data' => null]);
        }

        if (!isset(self::$handlers[$event])) {
            return;
        }

        foreach (self::$handlers[$event] as $handlers) {
            foreach ($handlers as $handler) {
                if (!empty($handler['include_path'])) {
                    include_once($handler['include_path']);
                }
                if (is_callable($handler['function'])) {
                    call_user_func_array($handler['function'], $args);
                }
            }
        }
    }

    public static function reset(): void
    {
        self::$handlers = [];
        $GLOBALS['pwg_event_handlers'] = &self::$handlers;
    }
}
