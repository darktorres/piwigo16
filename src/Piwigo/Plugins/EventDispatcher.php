<?php

declare(strict_types=1);

namespace Piwigo\Plugins;

use Piwigo\Admin\Integrity\CheckIntegrity;
use Piwigo\Menu\BlockManager;

/**
 * Static event dispatcher.
 */
final class EventDispatcher
{
    public const int NEUTRAL_PRIORITY = 50;

    /** @var array<string, array<int, list<array{function: mixed, include_path: string|null}>>> */
    public static array $handlers = [];

    public static function init(): void
    {
        self::$handlers = [];
    }

    /**
     * @psalm-param \Closure(CheckIntegrity):void|\Closure(BlockManager):void|\Closure(array<mixed>, string):array<mixed>|\Closure((array<mixed>|string)):string|\Closure(bool, string, string, bool):bool|\Closure(mixed, string, array<mixed>):mixed|\Closure(string, array<string, mixed>):string|string $func
     */
    public static function addListener(string $event, string|\Closure $func, int $priority = self::NEUTRAL_PRIORITY, ?string $include_path = null): bool
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
                if (isset($handler['include_path']) && $handler['include_path'] !== '') {
                    /** @psalm-suppress UnresolvableInclude */
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
                if (isset($handler['include_path']) && $handler['include_path'] !== '') {
                    /** @psalm-suppress UnresolvableInclude */
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
    }
}
