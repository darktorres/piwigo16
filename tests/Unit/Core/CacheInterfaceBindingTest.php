<?php

declare(strict_types=1);

use Piwigo\Core\Kernel;
use Piwigo\Core\Paths;
use Psr\Cache\CacheItemPoolInterface;
use Psr\SimpleCache\CacheInterface;

/**
 * Category C2 (post-DI-campaign shim/facade audit): config/container.php's
 * `CacheInterface::class` binding was simplified from a hand-written
 * factory() closure to a plain `get(Psr16Cache::class)` interface binding.
 * A real, live round-trip through the container-resolved instance --
 * not just that construction doesn't throw -- since this changes how a
 * real service resolves. Filesystem-forced, matching CachePoolsTest.php's
 * own reasoning: the adapter choice itself is already covered by
 * CacheFactoryTest, what matters here is that Psr16Cache really wraps the
 * one container-shared CacheItemPoolInterface instance, not a second one.
 */
beforeEach(function (): void {
    putenv('PIWIGO_CACHE_ADAPTER=filesystem');
    Kernel::boot(Paths::fromRoot(dirname(__DIR__, 3)));
});

afterEach(function (): void {
    $cache = Kernel::container()->get(CacheInterface::class);
    if ($cache instanceof CacheInterface) {
        $cache->delete('cache_interface_binding_test_key');
    }
    Kernel::reset();
    putenv('PIWIGO_CACHE_ADAPTER');
});

test('CacheInterface resolves to a real Psr16Cache and round-trips a set/get through it', function (): void {
    $cache = Kernel::container()->get(CacheInterface::class);

    expect($cache)
        ->toBeInstanceOf(CacheInterface::class);
    if (! $cache instanceof CacheInterface) {
        return; // unreachable -- the assertion above already failed the test otherwise.
    }

    expect($cache->set('cache_interface_binding_test_key', 'real-value'))
        ->toBeTrue()
        ->and($cache->get('cache_interface_binding_test_key'))
        ->toBe('real-value');
});

test('CacheInterface wraps the same container-shared CacheItemPoolInterface instance, not a second pool', function (): void {
    $pool = Kernel::container()->get(CacheItemPoolInterface::class);
    expect($pool)
        ->toBeInstanceOf(CacheItemPoolInterface::class);
    if (! $pool instanceof CacheItemPoolInterface) {
        return; // unreachable -- the assertion above already failed the test otherwise.
    }

    // Write through the raw PSR-6 pool directly...
    $item = $pool->getItem('cache_interface_binding_test_key');
    $item->set('written-via-psr6-pool');
    $pool->save($item);

    // ...and read it back through the PSR-16 CacheInterface binding under
    // test. Only possible if both resolve to the same underlying pool
    // instance (container-shared), not two independently-constructed ones.
    $cache = Kernel::container()->get(CacheInterface::class);
    expect($cache)
        ->toBeInstanceOf(CacheInterface::class);
    if (! $cache instanceof CacheInterface) {
        return; // unreachable -- the assertion above already failed the test otherwise.
    }
    expect($cache->get('cache_interface_binding_test_key'))
        ->toBe('written-via-psr6-pool');
});
