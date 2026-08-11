<?php

declare(strict_types=1);

use Piwigo\Cache\PersistentFileCache;
use Piwigo\Config\CurrentConfig;
use Piwigo\Core\AppInfo;
use Piwigo\Core\Kernel;
use Piwigo\Core\Paths;
use Piwigo\Tests\Support\CurrentPathsTestFactory;

/**
 * PersistentFileCache is the only concrete subclass of the abstract
 * Piwigo\Cache\PersistentCache -- this exercises that abstract class's own
 * real logic (make_key()'s scalar/serialize()-fallback key building,
 * default_lifetime, instance_key) plus the abstract get()/set()/purge()
 * contract PersistentFileCache implements it against, through a real
 * temp-directory-backed filesystem, not a fake. Same isolated-temp-root
 * pattern as tests/Unit/Image/DerivativeCacheServiceTest.php: a unique
 * sys_get_temp_dir() root per test via Kernel::boot() means the
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

/**
 * CurrentConfig is now a real, constructor-injected instance (no more
 * static property bag) -- a plain top-level helper (rather than a
 * `$this->currentConfig` built once in beforeEach) is used because
 * PHPStan can't narrow a Pest-bound `$this->...` property's type across
 * separate test closures (same rationale as
 * tests/Unit/Lang/LangServiceTest.php's own langServiceTestNewLang()
 * helper). dataLocation() is set identically ('data/') on every call, so
 * a fresh instance per call is behaviorally equivalent to a shared one
 * for this file's purposes.
 */
function persistentFileCacheTestNewCurrentConfig(): CurrentConfig
{
    $currentConfig = new CurrentConfig();
    $currentConfig->dataLocation = 'data/';

    return $currentConfig;
}

beforeEach(function (): void {
    $root = sys_get_temp_dir() . '/piwigo-persistent-file-cache-test-' . bin2hex(random_bytes(8));
    mkdir($root, 0o777, true);
    // Captured on $this, not re-read via CurrentPathsTestFactory::get() in
    // afterEach() below -- belt-and-suspenders alongside the Kernel::reset()
    // call right below: if boot() ever threw here for any reason, afterEach()
    // still runs, and re-resolving through the container would delete
    // whatever root some other test left bound instead of this fixture root.
    $this->root = $root;
    // A prior test file left Kernel booted without resetting first would
    // otherwise make this boot() silently no-op, leaving CurrentPathsTestFactory
    // (and afterEach's own recursive delete below) pointed at whatever
    // root that earlier boot bound instead of this fixture root.
    Kernel::reset();
    Kernel::boot(Paths::fromRoot($root));
    // Pre-create the cache dir set() itself writes into: without this, its
    // first @file_put_contents() attempt against the not-yet-existing dir
    // legitimately fails (by design -- see its own mkgetdir()-then-retry
    // fallback), but the resulting PHP warning trips this suite's
    // failOnWarning="true" even though it's `@`-suppressed (the "@
    // suppression doesn't hide warnings from PHPUnit" gotcha) -- not a bug
    // in the class under test, just a path this test doesn't need to take.
    mkdir(CurrentPathsTestFactory::get()->root . persistentFileCacheTestNewCurrentConfig()->dataLocation . 'cache/', 0o777, true);
});

afterEach(function (): void {
    persistentFileCacheTestRrmdir($this->root);
    Kernel::reset();
});

test('make_key hashes a scalar array key joined by & plus the instance key', function (): void {
    $cache = new PersistentFileCache(persistentFileCacheTestNewCurrentConfig(), CurrentPathsTestFactory::get());

    $key = $cache->make_key(['category', 12, 'thumbnails']);

    expect($key)
        ->toBe(md5('category&12&thumbnails' . AppInfo::VERSION));
});

test('make_key falls back to serialize() for a non-scalar array part', function (): void {
    $cache = new PersistentFileCache(persistentFileCacheTestNewCurrentConfig(), CurrentPathsTestFactory::get());

    $key = $cache->make_key([
        'category', [
            'nested' => true,
        ]]);

    expect($key)
        ->toBe(md5('category&' . serialize([
            'nested' => true,
        ]) . AppInfo::VERSION));
});

test('make_key hashes a bare string key plus the instance key, with no & joining', function (): void {
    $cache = new PersistentFileCache(persistentFileCacheTestNewCurrentConfig(), CurrentPathsTestFactory::get());

    $key = $cache->make_key('plain_string_key');

    expect($key)
        ->toBe(md5('plain_string_key' . AppInfo::VERSION));
});

test('make_key on an empty array key still hashes just the instance key', function (): void {
    $cache = new PersistentFileCache(persistentFileCacheTestNewCurrentConfig(), CurrentPathsTestFactory::get());

    $key = $cache->make_key([]);

    expect($key)
        ->toBe(md5(AppInfo::VERSION));
});

test('get returns false and leaves $value untouched for a never-cached key', function (): void {
    $cache = new PersistentFileCache(persistentFileCacheTestNewCurrentConfig(), CurrentPathsTestFactory::get());
    $value = 'sentinel-untouched';

    $found = $cache->get($cache->make_key('missing'), $value);

    expect($found)
        ->toBeFalse()
        ->and($value)
        ->toBe('sentinel-untouched');
});

test('set then get round-trips the exact cached value', function (): void {
    $cache = new PersistentFileCache(persistentFileCacheTestNewCurrentConfig(), CurrentPathsTestFactory::get());
    $key = $cache->make_key(['round_trip']);

    $written = $cache->set($key, [
        'section' => 'best_rated',
        'count' => 5,
    ]);

    expect($written)
        ->toBeTrue();

    $value = null;
    $found = $cache->get($key, $value);

    expect($found)
        ->toBeTrue()
        ->and($value)
        ->toBe([
            'section' => 'best_rated',
            'count' => 5,
        ]);
});

test('set self-heals by creating the missing cache dir when the first write attempt fails, then succeeds', function (): void {
    // Undoes beforeEach's own pre-created cache dir (see that block's own
    // docblock) so the first @file_put_contents() attempt genuinely fails
    // against a not-yet-existing directory, reaching set()'s own
    // mkgetdir()-then-retry fallback for real.
    $dir = CurrentPathsTestFactory::get()->root . persistentFileCacheTestNewCurrentConfig()->dataLocation . 'cache/';
    rmdir($dir);
    $cache = new PersistentFileCache(persistentFileCacheTestNewCurrentConfig(), CurrentPathsTestFactory::get());
    $key = $cache->make_key(['self-heal']);

    set_error_handler(static fn (): bool => true);
    try {
        $written = $cache->set($key, 'healed-value');
    } finally {
        restore_error_handler();
    }

    expect($written)
        ->toBeTrue()
        ->and(is_dir($dir))
        ->toBeTrue();

    $value = null;
    expect($cache->get($key, $value))
        ->toBeTrue()
        ->and($value)
        ->toBe('healed-value');
});

test('set returns false when both the initial write and the mkgetdir-then-retry attempt fail', function (): void {
    // Same starting point as the self-heal test above (cache dir removed),
    // but the data/ dir housing it is also made non-writable -- mkgetdir()
    // itself then fails to (re)create cache/ (MKGETDIR_DIE_ON_ERROR is
    // stripped from the flags passed here, so it returns false quietly
    // instead of throwing), and the retry file_put_contents() still
    // targets a directory that was never actually created.
    $dataRoot = CurrentPathsTestFactory::get()->root . persistentFileCacheTestNewCurrentConfig()->dataLocation;
    $dir = $dataRoot . 'cache/';
    rmdir($dir);
    chmod($dataRoot, 0o555);
    $cache = new PersistentFileCache(persistentFileCacheTestNewCurrentConfig(), CurrentPathsTestFactory::get());
    $key = $cache->make_key(['self-heal-fails']);

    set_error_handler(static fn (): bool => true);
    try {
        $written = $cache->set($key, 'unwritten-value');
    } finally {
        restore_error_handler();
        // Restore before afterEach's own recursive cleanup runs.
        chmod($dataRoot, 0o755);
    }

    expect($written)
        ->toBeFalse();
});

test('get returns false at the exact expiry boundary (expire === now), not just strictly past it', function (): void {
    // The "already expired" test below only ever uses a lifetime of -10
    // (well past the boundary); this pins expire to the *exact* current
    // second so `$expire > time()` (correct) and a mutated `>=` diverge
    // -- get()'s own internal time() call happens microseconds after
    // this one, on the same real clock tick in practice.
    $cache = new PersistentFileCache(persistentFileCacheTestNewCurrentConfig(), CurrentPathsTestFactory::get());
    $key = $cache->make_key(['exact-boundary-expiry']);
    $dir = CurrentPathsTestFactory::get()->root . persistentFileCacheTestNewCurrentConfig()->dataLocation . 'cache/';
    file_put_contents($dir . $key . '.cache', serialize([
        'expire' => time(),
        'data' => 'boundary-value',
    ]));

    $value = 'sentinel-untouched';
    $found = $cache->get($key, $value);

    expect($found)
        ->toBeFalse()
        ->and($value)
        ->toBe('sentinel-untouched');
});

test('get returns false for a value whose lifetime already expired', function (): void {
    $cache = new PersistentFileCache(persistentFileCacheTestNewCurrentConfig(), CurrentPathsTestFactory::get());
    $key = $cache->make_key(['already_expired']);

    $cache->set($key, 'stale-payload', -10);

    $value = null;
    $found = $cache->get($key, $value);

    expect($found)
        ->toBeFalse()
        ->and($value)
        ->toBeNull();
});

test('purge(true) deletes every cache file regardless of age', function (): void {
    $cache = new PersistentFileCache(persistentFileCacheTestNewCurrentConfig(), CurrentPathsTestFactory::get());
    $freshKey = $cache->make_key(['fresh']);
    $oldKey = $cache->make_key(['old']);
    $cache->set($freshKey, 'fresh-value');
    $cache->set($oldKey, 'old-value');

    $cache->purge(true);

    $freshValue = null;
    $oldValue = null;
    expect($cache->get($freshKey, $freshValue))
        ->toBeFalse()
        ->and($cache->get($oldKey, $oldValue))
        ->toBeFalse();
});

test('purge(false) deletes only files older than default_lifetime, keeping fresh ones', function (): void {
    $cache = new PersistentFileCache(persistentFileCacheTestNewCurrentConfig(), CurrentPathsTestFactory::get());
    $freshKey = $cache->make_key(['fresh_partial_purge']);
    $oldKey = $cache->make_key(['old_partial_purge']);
    $cache->set($freshKey, 'fresh-value');
    $cache->set($oldKey, 'old-value');

    $dir = CurrentPathsTestFactory::get()->root . persistentFileCacheTestNewCurrentConfig()->dataLocation . 'cache/';
    // Backdate the "old" file's mtime past default_lifetime (86400s) so
    // purge(false)'s own `filemtime($file) < $limit` cutoff catches it,
    // without waiting a real day for it to actually age out.
    touch($dir . $oldKey . '.cache', time() - $cache->default_lifetime - 60);

    $cache->purge(false);

    $freshValue = null;
    $oldValue = null;
    expect($cache->get($freshKey, $freshValue))
        ->toBeTrue()
        ->and($freshValue)
        ->toBe('fresh-value')
        ->and($cache->get($oldKey, $oldValue))
        ->toBeFalse();
});

test('purge is a no-op on a directory with no .cache files at all (glob() returns an empty array)', function (): void {
    $cache = new PersistentFileCache(persistentFileCacheTestNewCurrentConfig(), CurrentPathsTestFactory::get());

    // No set() call has happened in this test, so the cache dir mkdir()ed
    // in beforeEach() is genuinely empty -- glob('*.cache') returns [],
    // not false, exercising purge()'s own early-return guard.
    $cache->purge(true);
    $cache->purge(false);

    $dir = CurrentPathsTestFactory::get()->root . persistentFileCacheTestNewCurrentConfig()->dataLocation . 'cache/';
    expect(glob($dir . '*.cache'))->toBe([]);
});

test('get returns false when the file is unreadable despite existing (a Unix domain socket at that path, not a regular file)', function (): void {
    // is_readable() is true for a socket special file with the usual
    // permission bits, but file_get_contents() itself fails to open it
    // (verified directly: "No such device or address") and returns false
    // rather than throwing -- the one realistic, deterministic way to
    // reach this branch without a genuine TOCTOU race.
    $cache = new PersistentFileCache(persistentFileCacheTestNewCurrentConfig(), CurrentPathsTestFactory::get());
    $key = $cache->make_key(['blocked-by-socket']);
    $dir = CurrentPathsTestFactory::get()->root . persistentFileCacheTestNewCurrentConfig()->dataLocation . 'cache/';
    $path = $dir . $key . '.cache';

    $socket = socket_create(AF_UNIX, SOCK_STREAM, 0);
    if (! $socket instanceof Socket) {
        throw new RuntimeException('socket_create failed');
    }
    expect(socket_bind($socket, $path))
        ->toBeTrue();

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

        expect($found)
            ->toBeFalse()
            ->and($value)
            ->toBe('sentinel-untouched');
    } finally {
        socket_close($socket);
    }
})->skip(! extension_loaded('sockets'), 'requires ext-sockets to create a Unix domain socket file');

test('get returns false for a cache file whose payload is not an array at all', function (): void {
    $cache = new PersistentFileCache(persistentFileCacheTestNewCurrentConfig(), CurrentPathsTestFactory::get());
    $key = $cache->make_key(['scalar-payload']);
    $dir = CurrentPathsTestFactory::get()->root . persistentFileCacheTestNewCurrentConfig()->dataLocation . 'cache/';
    file_put_contents($dir . $key . '.cache', serialize('just-a-scalar-string'));

    $value = 'sentinel-untouched';
    $found = $cache->get($key, $value);

    expect($found)
        ->toBeFalse()
        ->and($value)
        ->toBe('sentinel-untouched');
});

test('get returns false for a cache file whose payload array is missing the data key', function (): void {
    $cache = new PersistentFileCache(persistentFileCacheTestNewCurrentConfig(), CurrentPathsTestFactory::get());
    $key = $cache->make_key(['missing-data-key']);
    $dir = CurrentPathsTestFactory::get()->root . persistentFileCacheTestNewCurrentConfig()->dataLocation . 'cache/';
    file_put_contents($dir . $key . '.cache', serialize([
        'expire' => time() + 3600,
    ]));

    $value = 'sentinel-untouched';
    $found = $cache->get($key, $value);

    expect($found)
        ->toBeFalse()
        ->and($value)
        ->toBe('sentinel-untouched');
});

test('get returns false when the stored expire value is not an int', function (): void {
    $cache = new PersistentFileCache(persistentFileCacheTestNewCurrentConfig(), CurrentPathsTestFactory::get());
    $key = $cache->make_key(['non-int-expire']);
    $dir = CurrentPathsTestFactory::get()->root . persistentFileCacheTestNewCurrentConfig()->dataLocation . 'cache/';
    file_put_contents($dir . $key . '.cache', serialize([
        'expire' => 'not-an-int',
        'data' => 'some-value',
    ]));

    $value = 'sentinel-untouched';
    $found = $cache->get($key, $value);

    expect($found)
        ->toBeFalse()
        ->and($value)
        ->toBe('sentinel-untouched');
});

test('set fires the opportunistic purge(false), not purge(true), on the exact 1-in-97 mt_rand() draw', function (): void {
    // mt_srand() makes mt_rand()'s next draw fully deterministic (a fixed,
    // portable Mt19937 implementation since PHP 7.1) -- seed 115's first
    // draw is 421105033, where %97===0 (should fire) but %96 and %98 are
    // both nonzero (so a mutated 97->96 or 97->98 divisor would NOT
    // coincidentally also fire), distinguishing the modulus/divisor
    // itself from the "fires at all" property the churn test below only
    // proves probabilistically over thousands of calls.
    $cache = new PersistentFileCache(persistentFileCacheTestNewCurrentConfig(), CurrentPathsTestFactory::get());
    $dir = CurrentPathsTestFactory::get()->root . persistentFileCacheTestNewCurrentConfig()->dataLocation . 'cache/';

    $staleKey = $cache->make_key(['exact-seed-stale']);
    $freshKey = $cache->make_key(['exact-seed-fresh']);
    $cache->set($staleKey, 'stale-value');
    $cache->set($freshKey, 'fresh-value');
    touch($dir . $staleKey . '.cache', time() - $cache->default_lifetime - 60);

    mt_srand(115);
    $written = $cache->set($cache->make_key(['exact-seed-trigger']), 'trigger-value');

    expect($written)
        ->toBeTrue()
        ->and(is_file($dir . $staleKey . '.cache'))->toBeFalse();
    // purge(false), not purge(true): the still-fresh sibling written just
    // before the triggering set() call must survive -- a mutated
    // `purge(true)` would delete it too, unconditionally.
    $freshValue = null;
    expect($cache->get($freshKey, $freshValue))
        ->toBeTrue()
        ->and($freshValue)
        ->toBe('fresh-value');
});

test('set does not fire the opportunistic purge on an mt_rand() draw one past the trigger value', function (): void {
    // Seed 74's first draw is 434226127, where %97===1 -- correct code
    // (`=== 0`) must NOT purge here, but a mutated `=== 1` (IncrementInteger
    // on the literal 0) would. Proves the exact target value, not just
    // that *some* modulus check exists.
    $cache = new PersistentFileCache(persistentFileCacheTestNewCurrentConfig(), CurrentPathsTestFactory::get());
    $dir = CurrentPathsTestFactory::get()->root . persistentFileCacheTestNewCurrentConfig()->dataLocation . 'cache/';

    $staleKey = $cache->make_key(['off-by-one-seed-stale']);
    $cache->set($staleKey, 'stale-value');
    touch($dir . $staleKey . '.cache', time() - $cache->default_lifetime - 60);

    mt_srand(74);
    $cache->set($cache->make_key(['off-by-one-seed-trigger']), 'trigger-value');

    expect(is_file($dir . $staleKey . '.cache'))->toBeTrue();
});

test('purge is a no-op when glob() itself fails (an overlong pattern returns false, not [])', function (): void {
    // glob() returns `false` -- not [] -- once the assembled pattern
    // exceeds PHP's internal 4096-character limit (confirmed live via
    // `glob(str_repeat('a', 5000))`). The sibling "no .cache files"
    // test below only ever reaches the `=== []` half of this guard --
    // foreach()-ing an empty array is *also* silently a no-op (a mere
    // E_WARNING, not a TypeError -- confirmed live), so it can't tell a
    // real `||` apart from a mutated `&&`/`true` (both of which would
    // let a genuine `false` fall through to `foreach ($files as ...)`).
    // The one real difference is *which* warning fires: glob() itself
    // always warns about the overlong pattern regardless of the
    // mutation, but only the mutated fall-through additionally warns
    // about foreach()-ing a non-iterable -- a handler that recognizes
    // and swallows only the expected glob() warning surfaces that.
    $originalRoot = CurrentPathsTestFactory::get()->root;
    // A deliberate mid-test root switch (unlike beforeEach's own boot() --
    // see its own comment), so it must reset() first to actually rebind.
    Kernel::reset();
    Kernel::boot(Paths::fromRoot(sys_get_temp_dir() . '/' . str_repeat('a', 4100)));
    $cache = new PersistentFileCache(persistentFileCacheTestNewCurrentConfig(), CurrentPathsTestFactory::get());

    $unexpectedWarning = null;
    set_error_handler(function (int $errno, string $errstr) use (&$unexpectedWarning): bool {
        if (! str_contains($errstr, 'Pattern exceeds the maximum allowed length')) {
            $unexpectedWarning = $errstr;
        }
        return true;
    });
    try {
        $cache->purge(true);
        $cache->purge(false);
    } finally {
        restore_error_handler();
        Kernel::reset();
        Kernel::boot(Paths::fromRoot($originalRoot));
    }

    expect($unexpectedWarning)
        ->toBeNull();
});

test('purge(false) keeps a file exactly at the age cutoff boundary, not just strictly newer than it', function (): void {
    // The existing "deletes only files older than default_lifetime" test
    // backdates its stale file 60s past the cutoff -- this pins mtime to
    // the *exact* $limit value so `filemtime($file) < $limit` (correct)
    // and a mutated `<=` diverge; purge()'s own internal time() call
    // happens microseconds after this one, on the same real clock tick
    // in practice.
    $cache = new PersistentFileCache(persistentFileCacheTestNewCurrentConfig(), CurrentPathsTestFactory::get());
    $key = $cache->make_key(['exact-purge-boundary']);
    $cache->set($key, 'boundary-value');
    $dir = CurrentPathsTestFactory::get()->root . persistentFileCacheTestNewCurrentConfig()->dataLocation . 'cache/';
    touch($dir . $key . '.cache', time() - $cache->default_lifetime);

    $cache->purge(false);

    expect(is_file($dir . $key . '.cache'))->toBeTrue();
});

test('set eventually fires its opportunistic purge(false) over many calls, sweeping a stale file', function (): void {
    // mt_rand() % 97 === 0 gates the opportunistic purge -- rather than
    // pin a magic mt_srand() seed to this build's own RNG output (fragile
    // across PHP versions), enough set() calls make hitting that ~1/97
    // chance at least once a near-certainty ([96/97]^2000 ~= 2e-9) and the
    // real, observable effect (the stale file disappearing) is what
    // actually matters here.
    $cache = new PersistentFileCache(persistentFileCacheTestNewCurrentConfig(), CurrentPathsTestFactory::get());
    $dir = CurrentPathsTestFactory::get()->root . persistentFileCacheTestNewCurrentConfig()->dataLocation . 'cache/';

    $staleKey = $cache->make_key(['stale-sentinel-for-opportunistic-purge']);
    $cache->set($staleKey, 'stale-value');
    touch($dir . $staleKey . '.cache', time() - $cache->default_lifetime - 60);

    for ($i = 0; $i < 2000; $i++) {
        $cache->set($cache->make_key(['opportunistic-purge-churn', $i]), 'churn-value');
    }

    expect(is_file($dir . $staleKey . '.cache'))->toBeFalse();
});
