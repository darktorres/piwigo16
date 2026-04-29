<?php

declare(strict_types=1);

namespace Piwigo\Cache;

/**
  Provides a persistent cache mechanism across multiple page loads/sessions etc...
*/
abstract class PersistentCache
{
    public int $default_lifetime = 86400;
    protected string $instance_key = PHPWG_VERSION;

    /**
     * @return string a key that can be safely be used with get/set methods
     */
    /** @param array<mixed>|string $key */
    public function make_key(array|string $key): string
    {
        if (is_array($key)) {
            $key = implode('&', array_map(static fn (mixed $k): string => is_scalar($k) ? (string) $k : '', $key));
        }
        $key .= $this->instance_key;
        return md5($key);
    }

    /**
    Searches for a key in the persistent cache and fills corresponding value.
    * @param string $key
    * @param mixed $value
    * @return bool false if the $key is not found in cache ($value is not modified in this case)
    */
    abstract public function get($key, &$value);

    /**
    Sets a key/value pair in the persistent cache.
    @param string $key - it should be the return value of make_key function
    @param mixed $value
    @param int $lifetime
    * @return bool false on error
    */
    abstract public function set($key, $value, $lifetime = null);

    /**
    Purge the persistent cache.
    @param boolean $all - if false only expired items will be purged
    */
    abstract public function purge(bool $all): bool;
}
