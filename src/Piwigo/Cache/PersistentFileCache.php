<?php

declare(strict_types=1);

namespace Piwigo\Cache;

/**
  Implementation of a persistent cache using files.
*/
class PersistentFileCache extends PersistentCache
{
    private readonly string $dir;

    public function __construct()
    {
        $this->dir = PHPWG_ROOT_PATH.\Piwigo\Config\Config::dataLocation().'cache/';
    }

    public function get($key, &$value): bool
    {
        $fileContent = @file_get_contents($this->dir.$key.'.cache');
        if ($fileContent !== false) {
            $loaded = unserialize($fileContent);
            if (is_array($loaded) && isset($loaded['expire']) && is_int($loaded['expire'])) {
                if ($loaded['expire'] > time()) {
                    $value = $loaded['data'];
                    return true;
                }
            }
        }
        return false;
    }

    public function set($key, $value, $lifetime = null): bool
    {
        if ($lifetime === null) {
            $lifetime = $this->default_lifetime;
        }

        if (random_int(0, mt_getrandmax()) % 97 == 0) {
            $this->purge(false);
        }

        $serialized = serialize([
            'expire' => time() + $lifetime,
            'data' => $value,
          ]);

        if (false === @file_put_contents($this->dir.$key.'.cache', $serialized)) {
            mkgetdir($this->dir, MKGETDIR_DEFAULT & ~MKGETDIR_DIE_ON_ERROR);
            $retried = file_put_contents($this->dir.$key.'.cache', $serialized);
            if ($retried === false) {
                return false;
            }
        }
        return true;
    }

    public function purge(bool $all): bool
    {
        $files = glob($this->dir.'*.cache');
        if (empty($files)) {
            return false;
        }

        $limit = time() - $this->default_lifetime;
        foreach ($files as $file) {
            if ($all || @filemtime($file) < $limit) {
                @unlink($file);
            }
        }
        return true;
    }

}
