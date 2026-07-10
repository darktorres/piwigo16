<?php

declare(strict_types=1);

// +-----------------------------------------------------------------------+
// | This file is part of Piwigo.                                          |
// |                                                                       |
// | For copyright and license information, please view the COPYING.txt    |
// | file that was distributed with this source code.                      |
// +-----------------------------------------------------------------------+

namespace Piwigo\Cache;

/**
 * Provides a persistent cache mechanism across multiple page loads/sessions etc...
 */
abstract class PersistentCache
{
    public int $default_lifetime = 86400;

    protected string $instance_key = PHPWG_VERSION;

    /**
     * @param array<int|string, mixed>|string $key
     * @return string a key that can be safely be used with get/set methods
     */
    public function make_key(array|string $key): string
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
     * @param string $key
     * @param-out mixed $value
     * @return bool false if the $key is not found in cache ($value is not modified in this case)
     */
    abstract public function get($key, mixed &$value);

    /**
     * Sets a key/value pair in the persistent cache.
     * @param string $key - it should be the return value of make_key function
     * @param mixed $value
     * @param int $lifetime
     * @return bool false on error
     */
    abstract public function set($key, $value, $lifetime = null);

    /**
     * Purge the persistent cache.
     * @param bool $all - if false only expired items will be purged
     */
    abstract public function purge($all): void;
}
