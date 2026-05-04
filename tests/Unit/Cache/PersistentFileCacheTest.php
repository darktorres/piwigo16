<?php

declare(strict_types=1);

namespace Piwigo\Tests\Unit\Cache;

use PHPUnit\Framework\TestCase;
use Piwigo\Cache\PersistentFileCache;
use Piwigo\Config\Config;

/**
 * Unit tests for PersistentFileCache.
 *
 * PersistentFileCache builds its cache dir as:
 *   PHPWG_ROOT_PATH + Config::dataLocation() + 'cache/'
 *
 * We override dataLocation to point to a fresh temp dir so tests are isolated.
 * PHPWG_ROOT_PATH is the repo root (constant, cannot be changed at runtime).
 */
final class PersistentFileCacheTest extends TestCase
{
    private string $tmpRoot;
    private string $cacheDir;

    protected function setUp(): void
    {
        // Create a unique temp dir under the repo root so the path relative
        // to PHPWG_ROOT_PATH can be computed simply.
        $this->tmpRoot = PHPWG_ROOT_PATH . 'tmp_cache_test_' . getmypid() . '_' . mt_rand() . '/';
        $this->cacheDir = $this->tmpRoot . 'cache/';
        mkdir($this->cacheDir, 0755, true);

        // Config::dataLocation() returns getString('data_location', '_data/').
        // Set it to our tmp dir (relative to PHPWG_ROOT_PATH).
        $rel = str_replace(PHPWG_ROOT_PATH, '', $this->tmpRoot);
        Config::loadArray(['data_location' => $rel]);
    }

    protected function tearDown(): void
    {
        Config::reset();
        foreach (glob($this->cacheDir . '*.cache') ?: [] as $f) {
            unlink($f);
        }
        \Piwigo\Core\Filesystem::tryRmdir($this->cacheDir);
        \Piwigo\Core\Filesystem::tryRmdir($this->tmpRoot);
    }

    public function test_get_returns_false_for_missing_key(): void
    {
        $cache = new PersistentFileCache();
        $value = null;
        self::assertFalse($cache->get('no_such_key', $value));
        self::assertNull($value);
    }

    public function test_set_and_get_round_trip_with_array(): void
    {
        $cache = new PersistentFileCache();
        $cache->set('my_key', ['answer' => 42], 3600);

        $value = null;
        self::assertTrue($cache->get('my_key', $value));
        self::assertSame(['answer' => 42], $value);
    }

    public function test_set_and_get_round_trip_with_scalar(): void
    {
        $cache = new PersistentFileCache();
        $cache->set('scalar', 'hello', 3600);

        $value = null;
        self::assertTrue($cache->get('scalar', $value));
        self::assertSame('hello', $value);
    }

    public function test_get_returns_false_for_expired_entry(): void
    {
        $cache = new PersistentFileCache();
        // Manually write a cache file with an already-expired timestamp.
        $serialized = serialize(['expire' => time() - 10, 'data' => 'stale']);
        file_put_contents($this->cacheDir . 'expired_key.cache', $serialized);

        $value = null;
        self::assertFalse($cache->get('expired_key', $value));
    }

    public function test_purge_expired_removes_only_stale_files(): void
    {
        $cache = new PersistentFileCache();
        // purge(false) uses filemtime < (time() - default_lifetime=86400).
        // Touch the file with an mtime older than 1 day.
        file_put_contents($this->cacheDir . 'stale.cache', serialize(['expire' => time() - 1, 'data' => 'x']));
        touch($this->cacheDir . 'stale.cache', time() - 90000);
        $cache->set('fresh', 'keep', 3600);

        $cache->purge(false);

        self::assertFileDoesNotExist($this->cacheDir . 'stale.cache');
        $value = null;
        self::assertTrue($cache->get('fresh', $value));
    }

    public function test_purge_all_removes_every_cache_file(): void
    {
        $cache = new PersistentFileCache();
        $cache->set('a', 1, 3600);
        $cache->set('b', 2, 3600);

        $cache->purge(true);

        $remaining = glob($this->cacheDir . '*.cache') ?: [];
        self::assertCount(0, $remaining);
    }
}
