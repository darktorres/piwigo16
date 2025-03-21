<?php

// +-----------------------------------------------------------------------+
// | This file is part of Piwigo.                                          |
// |                                                                       |
// | For copyright and license information, please view the COPYING.txt    |
// | file that was distributed with this source code.                      |
// +-----------------------------------------------------------------------+

namespace Piwigo\inc;

/**
 * Provides a persistent cache mechanism across multiple page loads/sessions etc...
 */
abstract class PersistentCache
{
    public $default_lifetime = 86400;

    protected $instance_key = PHPWG_VERSION;

    /**
     * @return string a key that can be safely be used with get/set methods
     */
    public function make_key($key)
    {
        if (is_array($key)) {
            $key = implode('&', $key);
        }

        $key .= $this->instance_key;
        return md5($key);
    }

    /**
     * Searches for a key in the persistent cache and fills corresponding value.
     * @param string $key
     * @param mixed $value
     * @return false if the $key is not found in cache ($value is not modified in this case)
     */
    abstract public function get($key, &$value);

    /**
     * Sets a key/value pair in the persistent cache.
     * @param string $key - it should be the return value of make_key function
     * @param mixed $value
     * @param int $lifetime
     * @return false on error
     */
    abstract public function set($key, $value, $lifetime = null);

    /**
     * Purge the persistent cache.
     * @param bool $all - if false only expired items will be purged
     */
    abstract public function purge($all);
}
