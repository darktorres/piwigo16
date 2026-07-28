<?php

declare(strict_types=1);

use Piwigo\Cache\CacheFactory;
use Symfony\Component\Cache\Adapter\ApcuAdapter;
use Symfony\Component\Cache\Adapter\FilesystemAdapter;
use Symfony\Component\Cache\Exception\CacheException;

// Environment-aware throughout: ext-apcu isn't installed in every
// environment (confirmed absent in this one), so tests assert the
// documented behavior for whichever state actually holds rather than
// hard-coding one environment's outcome.
//
// buildApcu()'s own successful-build return (`new ApcuAdapter(...)`, only
// reached when ApcuAdapter::isSupported() is true) is left uncovered here,
// same as FilesystemHelperTest's documented PHP_OS-gated branch -- neither
// ext-apcu nor ext-redis/ext-relay/predis-predis is installed in this
// environment (confirmed via `php -m` and `composer show`), so that branch
// is a real but environment-gated dead path here, not chased.
//
// buildRedis(), by contrast, IS fully reachable without a live Redis
// server or the extension: RedisAdapter::createConnection() checks
// extension/package availability *before* ever touching the network
// (verified directly: it throws CacheException synchronously), so the DSN
// resolution + createConnection() call are exercised for real below; only
// the final `new RedisAdapter(...)` success return stays environment-gated
// uncovered, same reasoning as buildApcu() above.

beforeEach(function (): void {
    putenv('PIWIGO_CACHE_ADAPTER');
    putenv('PIWIGO_REDIS_DSN');
});

afterEach(function (): void {
    putenv('PIWIGO_CACHE_ADAPTER');
    putenv('PIWIGO_REDIS_DSN');
});

test('the default adapter is apcu when available, else filesystem', function (): void {
    $pool = CacheFactory::create();

    if (ApcuAdapter::isSupported()) {
        expect($pool)->toBeInstanceOf(ApcuAdapter::class);
    } else {
        expect($pool)->toBeInstanceOf(FilesystemAdapter::class);
    }
});

test('an explicit filesystem request always succeeds', function (): void {
    expect(CacheFactory::create('filesystem'))->toBeInstanceOf(FilesystemAdapter::class);
});

test('the PIWIGO_CACHE_ADAPTER env var is honored when no explicit param is given', function (): void {
    putenv('PIWIGO_CACHE_ADAPTER=filesystem');

    expect(CacheFactory::create())->toBeInstanceOf(FilesystemAdapter::class);
});

test('an explicit param overrides the env var', function (): void {
    putenv('PIWIGO_CACHE_ADAPTER=bogus');

    expect(CacheFactory::create('filesystem'))->toBeInstanceOf(FilesystemAdapter::class);
});

test('an explicit apcu request succeeds if available, else fails loudly', function (): void {
    if (ApcuAdapter::isSupported()) {
        expect(CacheFactory::create('apcu'))->toBeInstanceOf(ApcuAdapter::class);
    } else {
        expect(static fn (): \Psr\Cache\CacheItemPoolInterface => CacheFactory::create('apcu'))->toThrow(RuntimeException::class);
    }
});

test('an unknown adapter name throws', function (): void {
    expect(static fn (): \Psr\Cache\CacheItemPoolInterface => CacheFactory::create('bogus'))->toThrow(InvalidArgumentException::class);
});

test('an explicit redis request honors PIWIGO_REDIS_DSN but fails loudly without ext-redis/relay/predis', function (): void {
    putenv('PIWIGO_REDIS_DSN=redis://cachehost:6380');

    expect(static fn (): \Psr\Cache\CacheItemPoolInterface => CacheFactory::create('redis'))
        ->toThrow(CacheException::class, 'Cannot find the "redis" extension');
});

test('an explicit redis request defaults to redis://localhost:6379 when PIWIGO_REDIS_DSN is unset', function (): void {
    expect(static fn (): \Psr\Cache\CacheItemPoolInterface => CacheFactory::create('redis'))
        ->toThrow(CacheException::class, 'Cannot find the "redis" extension');
});
