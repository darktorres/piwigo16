<?php

declare(strict_types=1);

namespace Piwigo\Cache;

/**
 * Per-request memoisation cache, replacing direct $cache global array writes.
 *
 * Usage: RequestCache::remember('get_icon', 'title', fn() => Lang::t(...))
 */
final class RequestCache
{
    /** @var array<string, array<string, mixed>> */
    private static array $data = [];

    public static function has(string $ns, string $key): bool
    {
        return array_key_exists($key, self::$data[$ns] ?? []);
    }

    public static function get(string $ns, string $key): mixed
    {
        return self::$data[$ns][$key] ?? null;
    }

    public static function set(string $ns, string $key, mixed $value): void
    {
        self::$data[$ns][$key] = $value;
    }

    /**
     * Return the cached value for $key inside namespace $ns, computing and
     * storing it via $compute on first access.
     */
    public static function remember(string $ns, string $key, callable $compute): mixed
    {
        if (!array_key_exists($key, self::$data[$ns] ?? [])) {
            self::$data[$ns][$key] = $compute();
        }
        return self::$data[$ns][$key];
    }

    public static function clearNs(string $ns): void
    {
        unset(self::$data[$ns]);
    }

    public static function reset(): void
    {
        self::$data = [];
    }
}
