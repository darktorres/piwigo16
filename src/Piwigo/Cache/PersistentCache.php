<?php

declare(strict_types=1);

// +-----------------------------------------------------------------------+
// | This file is part of Piwigo.                                          |
// |                                                                       |
// | For copyright and license information, please view the COPYING.txt    |
// | file that was distributed with this source code.                      |
// +-----------------------------------------------------------------------+

namespace Piwigo\Cache;

use Piwigo\Core\AppInfo;

/**
 * Provides a persistent cache mechanism across multiple page loads/sessions etc...
 *
 * get()/set()'s cached value is genuinely arbitrary by design -- matches
 * PSR-16 CacheInterface's own get(string $key, mixed $default = null):
 * mixed / set(string $key, mixed $value, ...): bool contract. makeKey()'s
 * $key parts are scalar-checked (with a serialize() fallback) at their own
 * use site rather than assumed.
 */
abstract class PersistentCache
{
    public int $default_lifetime = 86400;

    protected string $instance_key = AppInfo::VERSION;

    /**
     * @param array<int|string, mixed>|string $key
     * @return string a key that can be safely be used with get/set methods
     */
    public function makeKey(array|string $key): string
    {
        if (is_array($key)) {
            $parts = array_map(
                static fn (mixed $part): string => is_scalar($part) ? (string) $part : serialize($part),
                $key
            );
            $key = implode('&', $parts);
        }
        $key .= $this->instance_key;
        return md5($key);
    }

    /**
     * Searches for a key in the persistent cache and fills corresponding value.
     * @param-out mixed $value
     * @return bool false if the $key is not found in cache ($value is not modified in this case)
     */
    // Only purge() has a real caller today; get()/set() are still the real cache contract.
    // @phpstan-ignore shipmonk.deadMethod
    abstract public function get(string $key, mixed &$value): bool;

    /**
     * Sets a key/value pair in the persistent cache.
     * @param string $key - it should be the return value of makeKey function
     * @return bool false on error
     */
    // @phpstan-ignore shipmonk.deadMethod
    abstract public function set(string $key, mixed $value, ?int $lifetime = null): bool;

    /**
     * Purge the persistent cache.
     * @param bool $all - if false only expired items will be purged
     */
    abstract public function purge(bool $all): void;
}
