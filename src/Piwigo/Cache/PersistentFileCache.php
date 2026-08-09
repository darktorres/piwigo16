<?php

declare(strict_types=1);

// +-----------------------------------------------------------------------+
// | This file is part of Piwigo.                                          |
// |                                                                       |
// | For copyright and license information, please view the COPYING.txt    |
// | file that was distributed with this source code.                      |
// +-----------------------------------------------------------------------+

namespace Piwigo\Cache;

use Override;
use Piwigo\Config\CurrentConfig;
use Piwigo\Core\FilesystemHelper;
use Piwigo\Core\Paths;

/**
 * Implementation of a persistent cache using files.
 */
final class PersistentFileCache extends PersistentCache
{
    private readonly string $dir;

    public function __construct(
        private readonly CurrentConfig $currentConfig,
        private readonly Paths $paths,
    ) {
        $data_location = $this->currentConfig->dataLocation;
        $this->dir = $this->paths->root . $data_location . 'cache/';
    }

    #[Override]
    public function get($key, mixed &$value): bool
    {
        $file = $this->dir . $key . '.cache';
        if (! is_readable($file)) {
            return false;
        }

        $loaded = file_get_contents($file);
        if ($loaded === false) {
            return false;
        }

        $unserialized = @unserialize($loaded);
        if (! is_array($unserialized) || ! isset($unserialized['expire'], $unserialized['data'])) {
            return false;
        }

        $expire = $unserialized['expire'];
        if (! is_int($expire)) {
            return false;
        }

        if ($expire > time()) {
            $value = $unserialized['data'];
            return true;
        }

        return false;
    }

    #[Override]
    public function set($key, $value, $lifetime = null): bool
    {
        if ($lifetime === null) {
            $lifetime = $this->default_lifetime;
        }

        if (mt_rand() % 97 === 0) {
            $this->purge(false);
        }

        $serialized = serialize([
            'expire' => time() + $lifetime,
            'data' => $value,
        ]);

        $path = $this->dir . $key . '.cache';
        $written = @file_put_contents($path, $serialized);
        if ($written === false) {
            FilesystemHelper::mkgetdir($this->dir, $this->currentConfig, FilesystemHelper::MKGETDIR_DEFAULT & ~FilesystemHelper::MKGETDIR_DIE_ON_ERROR);
            $written = @file_put_contents($path, $serialized);
            if ($written === false) {
                return false;
            }
        }
        return true;
    }

    #[Override]
    public function purge($all): void
    {
        $files = glob($this->dir . '*.cache');
        if ($files === false || $files === []) {
            return;
        }

        $limit = time() - $this->default_lifetime;
        foreach ($files as $file) {
            if ($all || @filemtime($file) < $limit) {
                @unlink($file);
            }
        }
    }
}
