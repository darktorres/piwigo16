<?php

declare(strict_types=1);

use Piwigo\Cache\PersistentFileCache;
use Piwigo\Config\CurrentConfig;
use Piwigo\Core\AppInfo;
use Piwigo\Core\CurrentPaths;
use Piwigo\Core\Paths;

/**
 * PersistentFileCache is the only concrete subclass of the abstract
 * Piwigo\Cache\PersistentCache -- this exercises that abstract class's own
 * real logic (make_key()'s scalar/serialize()-fallback key building,
 * default_lifetime, instance_key) plus the abstract get()/set()/purge()
 * contract PersistentFileCache implements it against, through a real
 * temp-directory-backed filesystem, not a fake. Same isolated-temp-root
 * pattern as tests/Unit/Image/DerivativeCacheServiceTest.php: a unique
 * sys_get_temp_dir() root per test via CurrentPaths::set() means the
 * recursive-delete helper below can never touch anything outside this
 * run's own sandbox.
 */
function persistentFileCacheTestRrmdir(string $dir): void
{
    if (! is_dir($dir)) {
        return;
    }
    $nodes = scandir($dir);
    foreach ($nodes !== false ? $nodes : [] as $node) {
        if ($node === '.' || $node === '..') {
            continue;
        }
        $path = $dir . '/' . $node;
        is_dir($path) ? persistentFileCacheTestRrmdir($path) : unlink($path);
    }
    rmdir($dir);
}

beforeEach(function (): void {
    CurrentConfig::reset();
    $root = sys_get_temp_dir() . '/piwigo-persistent-file-cache-test-' . bin2hex(random_bytes(8));
    mkdir($root, 0o777, true);
    CurrentPaths::set(Paths::fromRoot($root));
    CurrentConfig::setDataLocation('data/');
    // Pre-create the cache dir set() itself writes into: without this, its
    // first @file_put_contents() attempt against the not-yet-existing dir
    // legitimately fails (by design -- see its own mkgetdir()-then-retry
    // fallback), but the resulting PHP warning trips this suite's
    // failOnWarning="true" even though it's `@`-suppressed (the "@
    // suppression doesn't hide warnings from PHPUnit" gotcha) -- not a bug
    // in the class under test, just a path this test doesn't need to take.
    mkdir(CurrentPaths::get()->root . CurrentConfig::dataLocation() . 'cache/', 0o777, true);
});

afterEach(function (): void {
    persistentFileCacheTestRrmdir(CurrentPaths::get()->root);
    CurrentConfig::reset();
    CurrentPaths::reset();
});

test('make_key hashes a scalar array key joined by & plus the instance key', function (): void {
    $cache = new PersistentFileCache();

    $key = $cache->make_key(['category', 12, 'thumbnails']);

    expect($key)->toBe(md5('category&12&thumbnails' . AppInfo::VERSION));
});

test('make_key falls back to serialize() for a non-scalar array part', function (): void {
    $cache = new PersistentFileCache();

    $key = $cache->make_key(['category', ['nested' => true]]);

    expect($key)->toBe(md5('category&' . serialize(['nested' => true]) . AppInfo::VERSION));
});

test('make_key hashes a bare string key plus the instance key, with no & joining', function (): void {
    $cache = new PersistentFileCache();

    $key = $cache->make_key('plain_string_key');

    expect($key)->toBe(md5('plain_string_key' . AppInfo::VERSION));
});

test('make_key on an empty array key still hashes just the instance key', function (): void {
    $cache = new PersistentFileCache();

    $key = $cache->make_key([]);

    expect($key)->toBe(md5(AppInfo::VERSION));
});

test('get returns false and leaves $value untouched for a never-cached key', function (): void {
    $cache = new PersistentFileCache();
    $value = 'sentinel-untouched';

    $found = $cache->get($cache->make_key('missing'), $value);

    expect($found)->toBeFalse()
        ->and($value)->toBe('sentinel-untouched');
});

test('set then get round-trips the exact cached value', function (): void {
    $cache = new PersistentFileCache();
    $key = $cache->make_key(['round_trip']);

    $written = $cache->set($key, ['section' => 'best_rated', 'count' => 5]);

    expect($written)->toBeTrue();

    $value = null;
    $found = $cache->get($key, $value);

    expect($found)->toBeTrue()
        ->and($value)->toBe(['section' => 'best_rated', 'count' => 5]);
});

test('get returns false for a value whose lifetime already expired', function (): void {
    $cache = new PersistentFileCache();
    $key = $cache->make_key(['already_expired']);

    $cache->set($key, 'stale-payload', -10);

    $value = null;
    $found = $cache->get($key, $value);

    expect($found)->toBeFalse()
        ->and($value)->toBeNull();
});

test('purge(true) deletes every cache file regardless of age', function (): void {
    $cache = new PersistentFileCache();
    $freshKey = $cache->make_key(['fresh']);
    $oldKey = $cache->make_key(['old']);
    $cache->set($freshKey, 'fresh-value');
    $cache->set($oldKey, 'old-value');

    $cache->purge(true);

    $freshValue = null;
    $oldValue = null;
    expect($cache->get($freshKey, $freshValue))->toBeFalse()
        ->and($cache->get($oldKey, $oldValue))->toBeFalse();
});

test('purge(false) deletes only files older than default_lifetime, keeping fresh ones', function (): void {
    $cache = new PersistentFileCache();
    $freshKey = $cache->make_key(['fresh_partial_purge']);
    $oldKey = $cache->make_key(['old_partial_purge']);
    $cache->set($freshKey, 'fresh-value');
    $cache->set($oldKey, 'old-value');

    $dir = CurrentPaths::get()->root . CurrentConfig::dataLocation() . 'cache/';
    // Backdate the "old" file's mtime past default_lifetime (86400s) so
    // purge(false)'s own `filemtime($file) < $limit` cutoff catches it,
    // without waiting a real day for it to actually age out.
    touch($dir . $oldKey . '.cache', time() - $cache->default_lifetime - 60);

    $cache->purge(false);

    $freshValue = null;
    $oldValue = null;
    expect($cache->get($freshKey, $freshValue))->toBeTrue()
        ->and($freshValue)->toBe('fresh-value')
        ->and($cache->get($oldKey, $oldValue))->toBeFalse();
});
