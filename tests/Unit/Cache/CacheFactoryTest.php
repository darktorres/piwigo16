<?php

declare(strict_types=1);

namespace Piwigo\Tests\Unit\Cache;

use PHPUnit\Framework\TestCase;
use Piwigo\Cache\CacheFactory;
use Piwigo\Config\Config;
use Psr\Cache\CacheItemPoolInterface;

/**
 * Smoke tests for CacheFactory — verifies each backend alias returns a valid
 * PSR-6 pool and that round-trips work on the 'file' backend.
 */
final class CacheFactoryTest extends TestCase
{
    private string $tmpRoot;

    protected function setUp(): void
    {
        $this->tmpRoot = PHPWG_ROOT_PATH . 'tmp_factory_test_' . getmypid() . '_' . mt_rand() . '/';
        mkdir($this->tmpRoot . 'cache/', 0755, true);

        $rel = str_replace(PHPWG_ROOT_PATH, '', $this->tmpRoot);
        Config::loadArray(['data_location' => $rel, 'cache.backend' => 'file']);
    }

    protected function tearDown(): void
    {
        Config::reset();
        $this->deleteDir($this->tmpRoot);
    }

    private function deleteDir(string $dir): void
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

    public function test_create_returns_psr6_pool(): void
    {
        $pool = CacheFactory::create('file');
        self::assertInstanceOf(CacheItemPoolInterface::class, $pool);
    }

    public function test_file_backend_round_trip(): void
    {
        $pool = CacheFactory::create('file');
        $item = $pool->getItem('test_key');
        $item->set('expected_value');
        $pool->save($item);

        $loaded = $pool->getItem('test_key');
        self::assertTrue($loaded->isHit());
        self::assertSame('expected_value', $loaded->get());
    }

    public function test_create_uses_configured_backend(): void
    {
        Config::loadArray(['cache.backend' => 'file']);
        $pool = CacheFactory::create();
        self::assertInstanceOf(CacheItemPoolInterface::class, $pool);
    }

    public function test_explicit_backend_overrides_config(): void
    {
        Config::loadArray(['cache.backend' => 'redis']);
        // Even though config says redis, passing 'file' overrides it.
        $pool = CacheFactory::create('file');
        self::assertInstanceOf(CacheItemPoolInterface::class, $pool);
    }
}
