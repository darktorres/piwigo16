<?php

declare(strict_types=1);

namespace Piwigo\Cache;

use Piwigo\Config\Config;
use Piwigo\Core\Filesystem;

/**
  Implementation of a persistent cache using files.
*/
class PersistentFileCache extends PersistentCache
{
    private readonly string $dir;

    public function __construct()
    {
        $this->dir = PHPWG_ROOT_PATH.Config::dataLocation().'cache/';
    }

    public function get($key, &$value): bool
    {
        $path = $this->dir.$key.'.cache';
        $fileContent = is_file($path) ? file_get_contents($path) : false;
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

        $path = $this->dir.$key.'.cache';
        if (!is_dir($this->dir)) {
            mkgetdir($this->dir, MKGETDIR_DEFAULT & ~MKGETDIR_DIE_ON_ERROR);
        }
        return file_put_contents($path, $serialized) !== false;
    }

    public function purge(bool $all): bool
    {
        $files = glob($this->dir.'*.cache');
        if (empty($files)) {
            return false;
        }

        $limit = time() - $this->default_lifetime;
        foreach ($files as $file) {
            $mtime = Filesystem::tryFileMtime($file);
            if ($all || $mtime === false || $mtime < $limit) {
                Filesystem::tryUnlink($file);
            }
        }
        return true;
    }

}
