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
    #[\Override]
    protected function setUp(): void
    {
        // CacheFactory's buildFile() falls back to sys_get_temp_dir() when
        // Kernel isn't booted (this unit test never boots). The cache pool
        // lands in /tmp/piwigo/cache/; we don't assert about the location,
        // just round-trip behaviour.
        Config::loadArray(['cache.backend' => 'file']);
    }

    #[\Override]
    protected function tearDown(): void
    {
        Config::reset();
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
