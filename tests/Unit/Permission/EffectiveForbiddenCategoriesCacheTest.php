<?php

declare(strict_types=1);

use Piwigo\Auth\AccessLevelChecker;
use Piwigo\Category\CategoryRepository;
use Piwigo\Category\CategoryService;
use Piwigo\Common\ValueObject\UserId;
use Piwigo\Config\CurrentConfig;
use Piwigo\Core\FilterState;
use Piwigo\Core\Kernel;
use Piwigo\Core\Paths;
use Piwigo\Db\DbConnection;
use Piwigo\Db\EntityManagerFactory;
use Piwigo\Group\GroupEntity;
use Piwigo\Lang\Translator;
use Piwigo\Permission\EffectiveForbiddenCategoriesCache;
use Piwigo\Permission\PermissionRepository;
use Piwigo\Permission\PermissionService;
use Piwigo\Permission\Projection\EffectivePermissionsSnapshot;
use Piwigo\PluginConfig\EventDispatcher;
use Piwigo\Tests\Support\LangTestFactory;
use Piwigo\Users\CurrentUser;
use Psr\Cache\CacheItemPoolInterface;
use Symfony\Component\Cache\Adapter\ArrayAdapter;

/**
 * Piwigo\Permission\EffectiveForbiddenCategoriesCache -- per-user cached
 * wrapper around the "effective" permission snapshot. No dedicated
 * Integration/Browser spec of its own.
 *
 * A fresh Symfony `ArrayAdapter` gives a real, isolated
 * `CacheItemPoolInterface` per test. `EffectiveForbiddenCategoriesCache`
 * is `final readonly`, so a cache-hit skipping the real (multi-query)
 * compute() is proven by pre-seeding the pool with a deliberately
 * unrealistic sentinel `EffectivePermissionsSnapshot` and asserting
 * `getForUser()` returns it verbatim.
 */
beforeEach(function (): void {
    Kernel::boot(Paths::fromRoot(sys_get_temp_dir()));
});

afterEach(function (): void {
    Kernel::reset();
});

function effectiveForbiddenCategoriesCacheTestSubject(CacheItemPoolInterface $pool): EffectiveForbiddenCategoriesCache
{
    $conn = DbConnection::build();
    $currentConfig = new CurrentConfig();
    $currentUser = new CurrentUser($currentConfig);
    $accessLevelChecker = new AccessLevelChecker($currentUser, $currentConfig);

    $permissionRepository = new PermissionRepository(EntityManagerFactory::build($conn));
    $permissionService = new PermissionService(
        $permissionRepository,
        EntityManagerFactory::build($conn)->getRepository(GroupEntity::class),
        new CategoryRepository(EntityManagerFactory::build($conn), $currentConfig),
        $currentUser,
        new FilterState(),
        $accessLevelChecker,
    );
    $categoryService = new CategoryService(
        LangTestFactory::get(),
        new CategoryRepository(EntityManagerFactory::build($conn), $currentConfig),
        $permissionService,
        $currentConfig,
        new EventDispatcher(),
        new Translator($currentConfig),
        $accessLevelChecker,
    );

    return new EffectiveForbiddenCategoriesCache($accessLevelChecker, $permissionService, $categoryService, $permissionRepository, $pool);
}

test('getForUser computes and caches a real snapshot on a cache miss', function (): void {
    $pool = new ArrayAdapter();
    $cache = effectiveForbiddenCategoriesCacheTestSubject($pool);

    $result = $cache->getForUser(UserId::from(999999)->value, 'guest', 0);

    $item = $pool->getItem('effective_999999');
    expect($item->isHit())
        ->toBeTrue()
        ->and($item->get())
        ->toEqual($result);
});

test('getForUser returns the cached snapshot verbatim on a cache hit, without recomputing it', function (): void {
    $pool = new ArrayAdapter();
    $sentinel = new EffectivePermissionsSnapshot(
        forbiddenCategories: 'SENTINEL-NOT-RECOMPUTED',
        imageAccessType: 'NOT IN',
        imageAccessList: '0',
        nbTotalImages: '12345',
        lastPhotoDate: null,
    );
    $item = $pool->getItem('effective_999999');
    $item->set($sentinel);
    $pool->save($item);
    $cache = effectiveForbiddenCategoriesCacheTestSubject($pool);

    $result = $cache->getForUser(UserId::from(999999)->value, 'guest', 0);

    expect($result)
        ->toEqual($sentinel);
});
