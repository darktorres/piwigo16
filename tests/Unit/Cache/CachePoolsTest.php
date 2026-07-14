<?php

declare(strict_types=1);

use Piwigo\Cache\CachePools;

// Filesystem-forced throughout: the real behavior under test is namespace
// isolation between pools, not adapter selection (already covered by
// CacheFactoryTest) -- forcing one adapter keeps this deterministic
// regardless of whether ext-apcu is installed in the running environment.
beforeEach(function (): void {
    putenv('PIWIGO_CACHE_ADAPTER=filesystem');
});

afterEach(function (): void {
    $clear = static function (): void {
        foreach ([
            CachePools::config(),
            CachePools::permissions(),
            CachePools::categoryTree(),
            CachePools::tagCloud(),
            CachePools::general(),
        ] as $pool) {
            $pool->clear();
        }
    };
    $clear();
    putenv('PIWIGO_CACHE_ADAPTER');
});

test('each named pool is isolated from the others', function (): void {
    $item = CachePools::permissions()->getItem('shared_key');
    $item->set('permissions_value');
    CachePools::permissions()->save($item);

    expect(CachePools::permissions()->getItem('shared_key')->isHit())->toBeTrue()
        ->and(CachePools::permissions()->getItem('shared_key')->get())->toBe('permissions_value')
        ->and(CachePools::categoryTree()->getItem('shared_key')->isHit())->toBeFalse()
        ->and(CachePools::general()->getItem('shared_key')->isHit())->toBeFalse();
});

test('a value saved in one pool is retrievable from a fresh call to the same method', function (): void {
    $item = CachePools::categoryTree()->getItem('tree_key');
    $item->set(['album_1' => 42]);
    CachePools::categoryTree()->save($item);

    // A fresh CachePools::categoryTree() call builds a new adapter instance
    // pointed at the same namespace/backend -- proves pool identity is
    // namespace-derived, not tied to holding onto one object.
    expect(CachePools::categoryTree()->getItem('tree_key')->get())->toBe(['album_1' => 42]);
});

test('config, tagCloud and general pools are each independently addressable', function (): void {
    CachePools::config()->save(CachePools::config()->getItem('k')->set('config_value'));
    CachePools::tagCloud()->save(CachePools::tagCloud()->getItem('k')->set('tag_cloud_value'));
    CachePools::general()->save(CachePools::general()->getItem('k')->set('general_value'));

    expect(CachePools::config()->getItem('k')->get())->toBe('config_value')
        ->and(CachePools::tagCloud()->getItem('k')->get())->toBe('tag_cloud_value')
        ->and(CachePools::general()->getItem('k')->get())->toBe('general_value');
});
