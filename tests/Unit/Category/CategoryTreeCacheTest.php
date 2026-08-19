<?php

declare(strict_types=1);

use Piwigo\Auth\AccessLevelChecker;
use Piwigo\Cache\CacheFactory;
use Piwigo\Cache\TranslationsCachePool;
use Piwigo\Category\CategoryRepository;
use Piwigo\Category\CategoryService;
use Piwigo\Category\CategoryTreeCache;
use Piwigo\Config\CurrentConfig;
use Piwigo\Core\FilterState;
use Piwigo\Core\Kernel;
use Piwigo\Core\Paths;
use Piwigo\Db\DbConnection;
use Piwigo\Db\EntityManagerFactory;
use Piwigo\Group\GroupEntity;
use Piwigo\Lang\Translator;
use Piwigo\Permission\PermissionRepository;
use Piwigo\Permission\PermissionService;
use Piwigo\PluginConfig\EventDispatcher;
use Piwigo\Tests\Support\LangTestFactory;
use Piwigo\Users\CurrentUser;
use Piwigo\Users\UserRepository;
use Symfony\Component\Cache\Adapter\ArrayAdapter;

/**
 * Piwigo\Category\CategoryTreeCache -- per-user cached replacement for
 * `user_cache_categories`'s row set. No dedicated Integration/Browser
 * spec of its own.
 *
 * A fresh Symfony `ArrayAdapter` gives a real, isolated
 * `CacheItemPoolInterface` per test -- no shared cache state, no
 * fixture dependency. `CategoryTreeCache` is `final readonly`, so a
 * cache-hit skipping the real rollup+name-lookup queries is proven by
 * pre-seeding the pool with a deliberately unrealistic sentinel value
 * and asserting `getForUser()` returns it verbatim.
 */
function categoryTreeCacheTestService(): CategoryService
{
    $conn = DbConnection::build();
    $currentConfig = new CurrentConfig();
    $currentUser = new CurrentUser($currentConfig);

    return new CategoryService(
        LangTestFactory::get(),
        new CategoryRepository(EntityManagerFactory::build($conn), $currentConfig),
        new PermissionService(
            new PermissionRepository(EntityManagerFactory::build($conn)),
            EntityManagerFactory::build($conn)->getRepository(GroupEntity::class),
            new CategoryRepository(EntityManagerFactory::build($conn), $currentConfig),
            $currentUser,
            new FilterState(),
            new AccessLevelChecker($currentUser, $currentConfig),
        ),
        $currentConfig,
        new EventDispatcher(),
        new Translator($currentConfig, new TranslationsCachePool(CacheFactory::create(namespace: 'piwigo.translations'))),
        new AccessLevelChecker($currentUser, $currentConfig),
        new UserRepository(EntityManagerFactory::build($conn), new EventDispatcher(), $currentConfig),
    );
}

function categoryTreeCacheTestRepo(): CategoryRepository
{
    return new CategoryRepository(EntityManagerFactory::build(DbConnection::build()), new CurrentConfig());
}

beforeEach(function (): void {
    Kernel::boot(Paths::fromRoot(sys_get_temp_dir()));
});

afterEach(function (): void {
    Kernel::reset();
});

test('getForUser computes and caches a real value on a cache miss', function (): void {
    $pool = new ArrayAdapter();
    $cache = new CategoryTreeCache(categoryTreeCacheTestService(), categoryTreeCacheTestRepo(), $pool);

    $result = $cache->getForUser(999999, 0, '');

    $item = $pool->getItem('tree_999999');
    expect($item->isHit())
        ->toBeTrue()
        ->and($item->get())
        ->toBe($result);
});

test('getForUser returns the cached value verbatim on a cache hit, without recomputing it', function (): void {
    $pool = new ArrayAdapter();
    $item = $pool->getItem('tree_999999');
    $item->set([
        'SENTINEL' => 'CACHED-VALUE-NOT-RECOMPUTED',
    ]);
    $pool->save($item);
    $cache = new CategoryTreeCache(categoryTreeCacheTestService(), categoryTreeCacheTestRepo(), $pool);

    $result = $cache->getForUser(999999, 0, '');

    expect($result)
        ->toBe([
            'SENTINEL' => 'CACHED-VALUE-NOT-RECOMPUTED',
        ]);
});
