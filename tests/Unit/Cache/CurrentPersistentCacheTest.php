<?php

declare(strict_types=1);

use Piwigo\Cache\CurrentPersistentCache;
use Piwigo\Cache\PersistentFileCache;
use Piwigo\Core\CurrentPaths;
use Piwigo\Core\Paths;

/**
 * Piwigo\Cache\CurrentPersistentCache -- the per-request PersistentCache
 * facade. Had zero dedicated coverage (see /home/torres/.claude/plans/
 * piped-enchanting-spark.md, Wave 1) despite being indirectly touched by
 * several Integration tests -- none of them exercise isInitialized()/
 * reset() specifically.
 */
beforeEach(function (): void {
    CurrentPaths::set(Paths::fromRoot(dirname(__DIR__, 3)));
    CurrentPersistentCache::reset();
});

afterEach(function (): void {
    CurrentPersistentCache::reset();
});

test('get returns null and isInitialized is false before set() is ever called', function (): void {
    expect(CurrentPersistentCache::get())->toBeNull();
    expect(CurrentPersistentCache::isInitialized())->toBeFalse();
});

test('set makes get return the same instance and isInitialized true', function (): void {
    $cache = new PersistentFileCache();

    CurrentPersistentCache::set($cache);

    expect(CurrentPersistentCache::get())->toBe($cache);
    expect(CurrentPersistentCache::isInitialized())->toBeTrue();
});

test('reset clears a previously set instance', function (): void {
    CurrentPersistentCache::set(new PersistentFileCache());
    expect(CurrentPersistentCache::isInitialized())->toBeTrue();

    CurrentPersistentCache::reset();

    expect(CurrentPersistentCache::get())->toBeNull();
    expect(CurrentPersistentCache::isInitialized())->toBeFalse();
});
