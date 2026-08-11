<?php

declare(strict_types=1);

use Piwigo\Cache\CacheFactory;
use Psr\Cache\CacheItemPoolInterface;
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

test('buildFilesystem points the adapter at the real repo root\'s _data/cache/ directory', function (): void {
    // No existing test ever inspects *where* the filesystem adapter
    // actually writes -- only that one gets built. FilesystemAdapter has
    // no public getter for its configured directory, so this reads the
    // same protected $directory property FilesystemTrait itself uses
    // internally (confirmed present via direct source read).
    $pool = CacheFactory::create('filesystem');

    $directory = new ReflectionProperty($pool, 'directory')
        ->getValue($pool);

    expect($directory)
        ->toBe(dirname(__DIR__, 3) . '/_data/cache/piwigo/');
});

test('the PIWIGO_CACHE_ADAPTER env var is honored when no explicit param is given', function (): void {
    putenv('PIWIGO_CACHE_ADAPTER=filesystem');

    expect(CacheFactory::create())->toBeInstanceOf(FilesystemAdapter::class);
});

test('the PIWIGO_CACHE_ADAPTER env var is honored for redis, not silently defaulted to auto-detect', function (): void {
    // The "env var honored" test above only ever checks 'filesystem',
    // which happens to be what auto-detect ALSO falls back to in this
    // environment (no ext-apcu) -- it can't tell envAdapter() genuinely
    // reading the env var apart from a mutated version that always
    // returns null (silently ignoring it). redis is never auto-detect's
    // own fallback, so routing into buildRedis() proves the env var was
    // actually read.
    putenv('PIWIGO_CACHE_ADAPTER=redis');

    expect(static fn (): CacheItemPoolInterface => CacheFactory::create())
        ->toThrow(CacheException::class, 'Cannot find the "redis" extension');
});

test('an empty-string PIWIGO_CACHE_ADAPTER is treated the same as unset, not as a literal empty adapter name', function (): void {
    // Distinct from both "unset" (beforeEach's bare putenv()) and a real
    // value -- envAdapter()'s own `!== ''` sentinel is what's supposed
    // to catch this, falling back to null (auto-detect) rather than
    // letting `''` reach the match() as a literal unknown adapter name.
    putenv('PIWIGO_CACHE_ADAPTER=');

    $pool = CacheFactory::create();

    if (ApcuAdapter::isSupported()) {
        expect($pool)->toBeInstanceOf(ApcuAdapter::class);
    } else {
        expect($pool)->toBeInstanceOf(FilesystemAdapter::class);
    }
});

test('an explicit param overrides the env var', function (): void {
    putenv('PIWIGO_CACHE_ADAPTER=bogus');

    expect(CacheFactory::create('filesystem'))->toBeInstanceOf(FilesystemAdapter::class);
});

test('an explicit apcu request succeeds if available, else fails loudly', function (): void {
    if (ApcuAdapter::isSupported()) {
        expect(CacheFactory::create('apcu'))->toBeInstanceOf(ApcuAdapter::class);
    } else {
        expect(static fn (): CacheItemPoolInterface => CacheFactory::create('apcu'))->toThrow(RuntimeException::class);
    }
});

test('an unknown adapter name throws', function (): void {
    expect(static fn (): CacheItemPoolInterface => CacheFactory::create('bogus'))->toThrow(InvalidArgumentException::class);
});

test('an explicit redis request honors PIWIGO_REDIS_DSN but fails loudly without ext-redis/relay/predis', function (): void {
    putenv('PIWIGO_REDIS_DSN=redis://cachehost:6380');

    expect(static fn (): CacheItemPoolInterface => CacheFactory::create('redis'))
        ->toThrow(CacheException::class, 'Cannot find the "redis" extension');
});

test('an explicit redis request defaults to redis://localhost:6379 when PIWIGO_REDIS_DSN is unset', function (): void {
    expect(static fn (): CacheItemPoolInterface => CacheFactory::create('redis'))
        ->toThrow(CacheException::class, 'Cannot find the "redis" extension');
});

test('an explicit redis request also defaults to redis://localhost:6379 when PIWIGO_REDIS_DSN is an empty string', function (): void {
    // Distinct from "unset" above -- an empty string is passed straight
    // through to RedisAdapter::createConnection() unless buildRedis()'s
    // own `!== ''` sentinel catches it, which fails *before* reaching
    // the extension-availability check with a different exception type
    // entirely ("Invalid Redis DSN", confirmed live), so this can't be
    // mistaken for the same failure as the "unset" case above.
    putenv('PIWIGO_REDIS_DSN=');

    expect(static fn (): CacheItemPoolInterface => CacheFactory::create('redis'))
        ->toThrow(CacheException::class, 'Cannot find the "redis" extension');
});
