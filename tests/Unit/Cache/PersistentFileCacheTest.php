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
    private PersistentFileCache $cache;

    protected function setUp(): void
    {
        $this->tmpRoot  = PHPWG_ROOT_PATH . 'tmp_cache_test_' . getmypid() . '_' . mt_rand() . '/';
        $this->cacheDir = $this->tmpRoot . 'cache/';
        mkdir($this->cacheDir, 0755, true);

        $rel = str_replace(PHPWG_ROOT_PATH, '', $this->tmpRoot);
        Config::loadArray(['data_location' => $rel]);

        $this->cache = new PersistentFileCache();
    }

    protected function tearDown(): void
    {
        $this->cache->purge(true);
        Config::reset();
        self::deleteDir($this->cacheDir);
        self::deleteDir($this->tmpRoot);
    }

    private static function deleteDir(string $dir): void
    {
        if (!is_dir($dir)) {
            return;
        }
        $items = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($dir, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST
        );
        foreach ($items as $item) {
            /** @var \SplFileInfo $item */
            $item->isDir() ? rmdir($item->getPathname()) : unlink($item->getPathname());
        }
        rmdir($dir);
    }

    public function test_get_returns_false_for_missing_key(): void
    {
        $value = null;
        self::assertFalse($this->cache->get('no_such_key', $value));
        self::assertNull($value);
    }

    public function test_set_and_get_round_trip_with_array(): void
    {
        $this->cache->set('my_key', ['answer' => 42], 3600);

        $value = null;
        self::assertTrue($this->cache->get('my_key', $value));
        self::assertSame(['answer' => 42], $value);
    }

    public function test_set_and_get_round_trip_with_scalar(): void
    {
        $this->cache->set('scalar', 'hello', 3600);

        $value = null;
        self::assertTrue($this->cache->get('scalar', $value));
        self::assertSame('hello', $value);
    }

    public function test_get_returns_false_for_expired_entry(): void
    {
        // expiresAfter(-1) stores the item with a past expiry timestamp;
        // the next getItem() call sees it as a miss.
        $this->cache->set('exp_key', 'stale', -1);

        $value = null;
        self::assertFalse($this->cache->get('exp_key', $value));
    }

    public function test_purge_all_clears_every_entry(): void
    {
        $this->cache->set('a', 1, 3600);
        $this->cache->set('b', 2, 3600);

        $this->cache->purge(true);

        $va = null;
        $vb = null;
        self::assertFalse($this->cache->get('a', $va));
        self::assertFalse($this->cache->get('b', $vb));
    }

    public function test_purge_false_preserves_fresh_entries(): void
    {
        $this->cache->set('fresh', 'keep', 3600);
        $this->cache->set('stale', 'drop', -1);

        $this->cache->purge(false);

        $value = null;
        self::assertTrue($this->cache->get('fresh', $value));
        self::assertSame('keep', $value);
    }

    public function test_make_key_is_consistent(): void
    {
        $k1 = $this->cache->makeKey('same');
        $k2 = $this->cache->makeKey('same');
        self::assertSame($k1, $k2);
    }

    public function test_make_key_differs_for_different_inputs(): void
    {
        $k1 = $this->cache->makeKey('foo');
        $k2 = $this->cache->makeKey('bar');
        self::assertNotSame($k1, $k2);
    }

    public function test_getPool_returns_psr6_pool(): void
    {
        self::assertInstanceOf(\Psr\Cache\CacheItemPoolInterface::class, $this->cache->getPool());
    }
}
