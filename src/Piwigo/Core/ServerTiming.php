<?php

declare(strict_types=1);

namespace Piwigo\Core;

/**
 * Simple stopwatch registry. Mirrors Kernel's own static-state + reset()
 * shape -- reset() exists purely for test isolation and, later,
 * worker-mode request isolation (the same SEC-60 pattern Kernel already
 * established).
 */
final class ServerTiming
{
    /**
     * @var array<string, float>
     */
    private static array $starts = [];

    /**
     * @var array<string, float>
     */
    private static array $durations = [];

    public static function start(string $name): void
    {
        self::$starts[$name] = microtime(true);
    }

    public static function stop(string $name): void
    {
        if (! isset(self::$starts[$name])) {
            return;
        }

        self::$durations[$name] = (microtime(true) - self::$starts[$name]) * 1000.0;
        unset(self::$starts[$name]);
    }

    /**
     * @return array<string, float> name => duration in milliseconds
     */
    public static function all(): array
    {
        return self::$durations;
    }

    public static function reset(): void
    {
        self::$starts = [];
        self::$durations = [];
    }
}
