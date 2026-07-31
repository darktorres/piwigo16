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

test('set self-heals by creating the missing cache dir when the first write attempt fails, then succeeds', function (): void {
    // Undoes beforeEach's own pre-created cache dir (see that block's own
    // docblock) so the first @file_put_contents() attempt genuinely fails
    // against a not-yet-existing directory, reaching set()'s own
    // mkgetdir()-then-retry fallback for real.
    $dir = CurrentPaths::get()->root . CurrentConfig::dataLocation() . 'cache/';
    rmdir($dir);
    $cache = new PersistentFileCache();
    $key = $cache->make_key(['self-heal']);

    set_error_handler(static fn (): bool => true);
    try {
        $written = $cache->set($key, 'healed-value');
    } finally {
        restore_error_handler();
    }

    expect($written)->toBeTrue()
        ->and(is_dir($dir))->toBeTrue();

    $value = null;
    expect($cache->get($key, $value))->toBeTrue()
        ->and($value)->toBe('healed-value');
});

test('set returns false when both the initial write and the mkgetdir-then-retry attempt fail', function (): void {
    // Same starting point as the self-heal test above (cache dir removed),
    // but the data/ dir housing it is also made non-writable -- mkgetdir()
    // itself then fails to (re)create cache/ (MKGETDIR_DIE_ON_ERROR is
    // stripped from the flags passed here, so it returns false quietly
    // instead of throwing), and the retry file_put_contents() still
    // targets a directory that was never actually created.
    $dataRoot = CurrentPaths::get()->root . CurrentConfig::dataLocation();
    $dir = $dataRoot . 'cache/';
    rmdir($dir);
    chmod($dataRoot, 0o555);
    $cache = new PersistentFileCache();
    $key = $cache->make_key(['self-heal-fails']);

    set_error_handler(static fn (): bool => true);
    try {
        $written = $cache->set($key, 'unwritten-value');
    } finally {
        restore_error_handler();
        // Restore before afterEach's own recursive cleanup runs.
        chmod($dataRoot, 0o755);
    }

    expect($written)->toBeFalse();
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

test('purge is a no-op on a directory with no .cache files at all (glob() returns an empty array)', function (): void {
    $cache = new PersistentFileCache();

    // No set() call has happened in this test, so the cache dir mkdir()ed
    // in beforeEach() is genuinely empty -- glob('*.cache') returns [],
    // not false, exercising purge()'s own early-return guard.
    $cache->purge(true);
    $cache->purge(false);

    $dir = CurrentPaths::get()->root . CurrentConfig::dataLocation() . 'cache/';
    expect(glob($dir . '*.cache'))->toBe([]);
});

test('get returns false when the file is unreadable despite existing (a Unix domain socket at that path, not a regular file)', function (): void {
    // is_readable() is true for a socket special file with the usual
    // permission bits, but file_get_contents() itself fails to open it
    // (verified directly: "No such device or address") and returns false
    // rather than throwing -- the one realistic, deterministic way to
    // reach this branch without a genuine TOCTOU race.
    $cache = new PersistentFileCache();
    $key = $cache->make_key(['blocked-by-socket']);
    $dir = CurrentPaths::get()->root . CurrentConfig::dataLocation() . 'cache/';
    $path = $dir . $key . '.cache';

    $socket = socket_create(AF_UNIX, SOCK_STREAM, 0);
    if (! $socket instanceof Socket) {
        throw new RuntimeException('socket_create failed');
    }
    expect(socket_bind($socket, $path))->toBeTrue();

    try {
        $value = 'sentinel-untouched';
        // @ suppression alone doesn't stop PHPUnit's own ErrorHandler from
        // surfacing the warning (failOnWarning="true") -- a real no-op
        // handler for the duration of this one expected-to-warn call is
        // the reliable way to swallow it, matching ArrayHelperTest's own
        // established pattern.
        set_error_handler(static fn (): bool => true);
        try {
            $found = $cache->get($key, $value);
        } finally {
            restore_error_handler();
        }

        expect($found)->toBeFalse()
            ->and($value)->toBe('sentinel-untouched');
    } finally {
        socket_close($socket);
    }
})->skip(! extension_loaded('sockets'), 'requires ext-sockets to create a Unix domain socket file');

test('get returns false for a cache file whose payload is not an array at all', function (): void {
    $cache = new PersistentFileCache();
    $key = $cache->make_key(['scalar-payload']);
    $dir = CurrentPaths::get()->root . CurrentConfig::dataLocation() . 'cache/';
    file_put_contents($dir . $key . '.cache', serialize('just-a-scalar-string'));

    $value = 'sentinel-untouched';
    $found = $cache->get($key, $value);

    expect($found)->toBeFalse()
        ->and($value)->toBe('sentinel-untouched');
});

test('get returns false for a cache file whose payload array is missing the data key', function (): void {
    $cache = new PersistentFileCache();
    $key = $cache->make_key(['missing-data-key']);
    $dir = CurrentPaths::get()->root . CurrentConfig::dataLocation() . 'cache/';
    file_put_contents($dir . $key . '.cache', serialize(['expire' => time() + 3600]));

    $value = 'sentinel-untouched';
    $found = $cache->get($key, $value);

    expect($found)->toBeFalse()
        ->and($value)->toBe('sentinel-untouched');
});

test('get returns false when the stored expire value is not an int', function (): void {
    $cache = new PersistentFileCache();
    $key = $cache->make_key(['non-int-expire']);
    $dir = CurrentPaths::get()->root . CurrentConfig::dataLocation() . 'cache/';
    file_put_contents($dir . $key . '.cache', serialize(['expire' => 'not-an-int', 'data' => 'some-value']));

    $value = 'sentinel-untouched';
    $found = $cache->get($key, $value);

    expect($found)->toBeFalse()
        ->and($value)->toBe('sentinel-untouched');
});

test('set eventually fires its opportunistic purge(false) over many calls, sweeping a stale file', function (): void {
    // mt_rand() % 97 === 0 gates the opportunistic purge -- rather than
    // pin a magic mt_srand() seed to this build's own RNG output (fragile
    // across PHP versions), enough set() calls make hitting that ~1/97
    // chance at least once a near-certainty ([96/97]^2000 ~= 2e-9) and the
    // real, observable effect (the stale file disappearing) is what
    // actually matters here.
    $cache = new PersistentFileCache();
    $dir = CurrentPaths::get()->root . CurrentConfig::dataLocation() . 'cache/';

    $staleKey = $cache->make_key(['stale-sentinel-for-opportunistic-purge']);
    $cache->set($staleKey, 'stale-value');
    touch($dir . $staleKey . '.cache', time() - $cache->default_lifetime - 60);

    for ($i = 0; $i < 2000; $i++) {
        $cache->set($cache->make_key(['opportunistic-purge-churn', $i]), 'churn-value');
    }

    expect(is_file($dir . $staleKey . '.cache'))->toBeFalse();
});
