<?php

declare(strict_types=1);

use Piwigo\Auth\AccessLevelChecker;
use Piwigo\Category\CategoryRepository;
use Piwigo\Common\ValueObject\UserId;
use Piwigo\Config\CurrentConfig;
use Piwigo\Core\FilterState;
use Piwigo\Db\DbConnection;
use Piwigo\Db\EntityManagerFactory;
use Piwigo\Group\GroupEntity;
use Piwigo\Permission\ForbiddenCategoriesCache;
use Piwigo\Permission\PermissionRepository;
use Piwigo\Permission\PermissionService;
use Piwigo\Users\CurrentUser;
use Symfony\Component\Cache\Adapter\ArrayAdapter;

/**
 * Piwigo\Permission\ForbiddenCategoriesCache -- per-user cached wrapper
 * around PermissionService::getForbiddenCategories(). No dedicated
 * Integration/Browser spec of its own.
 *
 * A fresh Symfony `ArrayAdapter` gives a real, isolated
 * `CacheItemPoolInterface` per test -- no shared cache state, no
 * fixture dependency. `PermissionService` is `final readonly`, so a
 * cache-hit skipping the real service call is proven by pre-seeding the
 * pool with a deliberately unrealistic sentinel string and asserting
 * `getForUser()` returns it verbatim, rather than a freshly recomputed
 * (and therefore genuinely different-looking) value.
 */
function forbiddenCategoriesCacheTestService(): PermissionService
{
    $conn = DbConnection::build();
    $currentConfig = new CurrentConfig();
    $currentUser = new CurrentUser($currentConfig);

    return new PermissionService(
        new PermissionRepository(EntityManagerFactory::build($conn)),
        EntityManagerFactory::build($conn)->getRepository(GroupEntity::class),
        new CategoryRepository(EntityManagerFactory::build($conn), $currentConfig),
        $currentUser,
        new FilterState(),
        new AccessLevelChecker($currentUser, $currentConfig),
    );
}

test('getForUser computes and caches a real value on a cache miss', function (): void {
    $pool = new ArrayAdapter();
    $cache = new ForbiddenCategoriesCache(forbiddenCategoriesCacheTestService(), $pool);

    $result = $cache->getForUser(UserId::from(999999)->value, 'guest');

    $item = $pool->getItem('forbidden_999999');
    expect($item->isHit())
        ->toBeTrue()
        ->and($item->get())
        ->toBe($result);
});

test('getForUser returns the cached value verbatim on a cache hit, without recomputing it', function (): void {
    $pool = new ArrayAdapter();
    $item = $pool->getItem('forbidden_999999');
    $item->set('SENTINEL-CACHED-VALUE-NOT-RECOMPUTED');
    $pool->save($item);
    $cache = new ForbiddenCategoriesCache(forbiddenCategoriesCacheTestService(), $pool);

    $result = $cache->getForUser(UserId::from(999999)->value, 'guest');

    expect($result)
        ->toBe('SENTINEL-CACHED-VALUE-NOT-RECOMPUTED');
});
