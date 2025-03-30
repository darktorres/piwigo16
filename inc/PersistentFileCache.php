<?php

declare(strict_types=1);

// +-----------------------------------------------------------------------+
// | This file is part of Piwigo.                                          |
// |                                                                       |
// | For copyright and license information, please view the COPYING.txt    |
// | file that was distributed with this source code.                      |
// +-----------------------------------------------------------------------+

namespace Piwigo\inc;

use Override;

/**
 * Implementation of a persistent cache using files.
 */
final class PersistentFileCache extends PersistentCache
{
    private readonly string $dir;

    public function __construct()
    {
        global $conf;
        $this->dir = PHPWG_ROOT_PATH . $conf['data_location'] . 'cache/';
    }

    #[Override]
    public function get(
        string $key,
        array|bool|string|int|float|null|object &$value
    ): bool {
        $loaded = file_exists($this->dir . $key . '.cache') ? file_get_contents($this->dir . $key . '.cache') : false;

        if ($loaded !== false) {
            $loaded = unserialize($loaded);

            if ($loaded !== false &&
                $loaded['expire'] > time()
            ) {
                $value = $loaded['data'];
                return true;
            }
        }

        return false;
    }

    #[Override]
    public function set(
        string $key,
        array|bool|string|int|float|null|object $value,
        ?int $lifetime = null
    ): bool {
        if ($lifetime === null) {
            $lifetime = $this->default_lifetime;
        }

        if (mt_rand() % 97 == 0) {
            $this->purge(false);
        }

        $serialized = serialize([
            'expire' => time() + $lifetime,
            'data' => $value,
        ]);

        if (! file_exists($this->dir)) {
            functions::mkgetdir($this->dir, functions::MKGETDIR_DEFAULT & ~functions::MKGETDIR_DIE_ON_ERROR);
        }

        return file_put_contents($this->dir . $key . '.cache', $serialized) !== false;
    }

    #[Override]
    public function purge(
        bool $all
    ): void {
        $files = glob($this->dir . '*.cache');

        if (empty($files)) {
            return;
        }

        $limit = time() - $this->default_lifetime;

        foreach ($files as $file) {
            if ($all ||
                filemtime($file) < $limit
            ) {
                unlink($file);
            }
        }
    }
}
