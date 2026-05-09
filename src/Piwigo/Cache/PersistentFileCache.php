<?php

declare(strict_types=1);

namespace Piwigo\Cache;

use Piwigo\Config\Config;
use Psr\Cache\CacheItemPoolInterface;
use Symfony\Component\Cache\Adapter\FilesystemAdapter;
use Symfony\Component\Cache\PruneableInterface;

/**
 * Legacy-API shim backed by a PSR-6 pool (default: FilesystemAdapter).
 *
 * Pass any CacheItemPoolInterface to the constructor to override the backend
 * (e.g. the pool returned by CacheFactory::create()). When called without
 * arguments, a FilesystemAdapter pointing at the data/cache directory is used.
 */
final class PersistentFileCache extends PersistentCache
{
    private readonly CacheItemPoolInterface $pool;

    public function __construct(?CacheItemPoolInterface $pool = null)
    {
        if ($pool !== null) {
            $this->pool = $pool;
        } else {
            $directory   = PHPWG_ROOT_PATH . Config::dataLocation() . 'cache/';
            $this->pool  = new FilesystemAdapter('', $this->default_lifetime, $directory);
        }
    }

    #[\Override]
    public function getPool(): CacheItemPoolInterface
    {
        return $this->pool;
    }

    #[\Override]
    public function get(string $key, mixed &$value): bool
    {
        $item = $this->pool->getItem($key);
        if (!$item->isHit()) {
            return false;
        }
        $value = $item->get();
        return true;
    }

    #[\Override]
    public function set(string $key, array|string|int|float|bool $value, ?int $lifetime = null): bool
    {
        $item = $this->pool->getItem($key);
        $item->set($value);
        $item->expiresAfter($lifetime ?? $this->default_lifetime);
        return $this->pool->save($item);
    }

    #[\Override]
    public function purge(bool $all): bool
    {
        if ($all) {
            return $this->pool->clear();
        }
        if ($this->pool instanceof PruneableInterface) {
            return $this->pool->prune();
        }
        return $this->pool->clear();
    }
}
