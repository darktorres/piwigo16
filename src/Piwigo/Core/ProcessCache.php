<?php

declare(strict_types=1);

namespace Piwigo\Core;

/**
 * Generic per-request memoization registry. Unlike `Piwigo\Config\Config`
 * (a config snapshot with well-known keys), this is a plain, unstructured
 * key/value store: unrelated call sites each memoize their own one-off
 * computation under their own key (e.g. `'cat_names'`, `'get_icon'`,
 * `'default_user'`, `self::class . '::tagAlphaCompare'`) for the
 * remainder of the same request/process. Every consumer already
 * defensively checks has()/reads with a `?? null` fallback before first
 * use.
 *
 * Container-shared; consumers take it via constructor injection, or as
 * an explicit method parameter for static-only utilities with no
 * constructor of their own.
 */
final class ProcessCache
{
    /**
     * @var array<string, mixed>
     */
    private array $data = [];

    public function has(string $key): bool
    {
        return array_key_exists($key, $this->data);
    }

    public function get(string $key): mixed
    {
        return $this->data[$key] ?? null;
    }

    public function set(string $key, mixed $value): void
    {
        $this->data[$key] = $value;
    }

    public function forget(string $key): void
    {
        unset($this->data[$key]);
    }

    /**
     * Test-only -- production code never needs to clear this mid-request.
     */
    public function reset(): void
    {
        $this->data = [];
    }
}
