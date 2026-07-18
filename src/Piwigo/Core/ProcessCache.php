<?php

declare(strict_types=1);

namespace Piwigo\Core;

/**
 * Generic per-request memoization registry -- Legacy Coupling Retirement
 * Track A gap-fill batch G5, replacing the legacy `global $cache;` bag.
 * Unlike Piwigo\Config\Config (a config snapshot with well-known keys),
 * this is a plain, unstructured key/value store: unrelated call sites each
 * memoize their own one-off computation under their own key (e.g.
 * `'cat_names'`, `'get_icon'`, `'default_user'`,
 * `self::class . '::tagAlphaCompare'`) for the remainder of the same
 * request/process. Same self-managed static shape as Config's own
 * $data/has()/override()/reset(), no seam initialization needed --
 * every consumer already defensively checks has()/reads with a `?? null`
 * fallback before first use, matching how the never-initialized raw
 * global always behaved.
 */
final class ProcessCache
{
    /**
     * @var array<string, mixed>
     */
    private static array $data = [];

    public static function has(string $key): bool
    {
        return array_key_exists($key, self::$data);
    }

    public static function get(string $key): mixed
    {
        return self::$data[$key] ?? null;
    }

    public static function set(string $key, mixed $value): void
    {
        self::$data[$key] = $value;
    }

    public static function forget(string $key): void
    {
        unset(self::$data[$key]);
    }

    /**
     * Test-only, for test-isolation between requests -- mirrors Config's
     * own reset().
     */
    public static function reset(): void
    {
        self::$data = [];
    }
}
