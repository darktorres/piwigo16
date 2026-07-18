<?php

declare(strict_types=1);

use Piwigo\Cache\CacheFactory;
use Symfony\Component\Cache\Adapter\ApcuAdapter;
use Symfony\Component\Cache\Adapter\FilesystemAdapter;

// Environment-aware throughout: ext-apcu isn't installed in every
// environment (confirmed absent in this one), so tests assert the
// documented behavior for whichever state actually holds rather than
// hard-coding one environment's outcome.

beforeEach(function (): void {
    putenv('PIWIGO_CACHE_ADAPTER');
});

afterEach(function (): void {
    putenv('PIWIGO_CACHE_ADAPTER');
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
