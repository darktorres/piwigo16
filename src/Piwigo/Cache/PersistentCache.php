<?php

declare(strict_types=1);

namespace Piwigo\Cache;

use Psr\Cache\CacheItemPoolInterface;

/**
 * Legacy persistent-cache API. Concrete subclasses wrap a PSR-6 pool
 * and delegate the get/set/purge surface to it.
 */
abstract class PersistentCache
{
    public int $default_lifetime = 86400;
    protected string $instance_key = PHPWG_VERSION;

    abstract public function getPool(): CacheItemPoolInterface;

    /** @param array<mixed>|string $key */
    public function makeKey(array|string $key): string
    {
        if (is_array($key)) {
            $key = implode('&', array_map(static fn (mixed $k): string => is_scalar($k) ? (string) $k : '', $key));
        }
        $key .= $this->instance_key;
        return md5($key);
    }

    abstract public function get(string $key, mixed &$value): bool;

    abstract public function set(string $key, mixed $value, ?int $lifetime = null): bool;

    abstract public function purge(bool $all): bool;
}
