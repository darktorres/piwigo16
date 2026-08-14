<?php

declare(strict_types=1);

namespace Piwigo\Cache;

use Override;
use Psr\Cache\CacheItemInterface;
use Psr\Cache\CacheItemPoolInterface;

/**
 * Base for the individually-bound, zero-arg-constructed named cache pool
 * types (ConfigCachePool, PermissionsCachePool, etc.) that replaced
 * CachePools::X() -- each concrete subclass is otherwise-empty, existing
 * only so PHP-DI can disambiguate 10 pools sharing this one
 * CacheItemPoolInterface-delegating implementation by constructor type
 * alone. config/container.php's own factory() entry for each subclass is
 * what actually supplies the namespace/TTL (via CacheFactory::create()),
 * matching CachePools::X()'s own former per-pool configuration -- this
 * class has no opinion on either.
 */
abstract class AbstractNamedCachePool implements CacheItemPoolInterface
{
    public function __construct(
        private readonly CacheItemPoolInterface $pool,
    ) {}

    #[Override]
    public function getItem(string $key): CacheItemInterface
    {
        return $this->pool->getItem($key);
    }

    /**
     * @param string[] $keys
     * @return iterable<string, CacheItemInterface>
     */
    #[Override]
    public function getItems(array $keys = []): iterable
    {
        /** @var iterable<string, CacheItemInterface> $items */
        $items = $this->pool->getItems($keys);

        return $items;
    }

    #[Override]
    public function hasItem(string $key): bool
    {
        return $this->pool->hasItem($key);
    }

    #[Override]
    public function clear(): bool
    {
        return $this->pool->clear();
    }

    #[Override]
    public function deleteItem(string $key): bool
    {
        return $this->pool->deleteItem($key);
    }

    /**
     * @param string[] $keys
     */
    #[Override]
    public function deleteItems(array $keys): bool
    {
        return $this->pool->deleteItems($keys);
    }

    #[Override]
    public function save(CacheItemInterface $item): bool
    {
        return $this->pool->save($item);
    }

    #[Override]
    public function saveDeferred(CacheItemInterface $item): bool
    {
        return $this->pool->saveDeferred($item);
    }

    #[Override]
    public function commit(): bool
    {
        return $this->pool->commit();
    }
}
