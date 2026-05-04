<?php

declare(strict_types=1);

namespace Piwigo\Cache;

use Psr\Cache\CacheItemPoolInterface;
use Symfony\Component\Cache\Adapter\ApcuAdapter;
use Symfony\Component\Cache\Adapter\ChainAdapter;
use Symfony\Component\Cache\Adapter\FilesystemAdapter;
use Symfony\Component\Cache\Adapter\RedisAdapter;
use Piwigo\Config\Config;

/**
 * Creates PSR-6 cache pools for the requested (or configured) backend.
 *
 * Supported backends via Config::cacheBackend():
 *   'file'  — FilesystemAdapter (default, zero infra)
 *   'apcu'  — ApcuAdapter       (single-server in-process speed)
 *   'redis' — RedisAdapter      (multi-server clustering)
 *   'chain' — ChainAdapter([apcu, redis, file]) (fast reads, durable writes)
 */
final class CacheFactory
{
    public static function create(?string $backend = null): CacheItemPoolInterface
    {
        $backend    ??= Config::cacheBackend();
        $namespace   = Config::cacheNamespace();
        $defaultTtl  = Config::cacheDefaultTtl();

        return match ($backend) {
            'apcu'  => new ApcuAdapter($namespace, $defaultTtl),
            'redis' => self::buildRedis($namespace, $defaultTtl),
            'chain' => new ChainAdapter([
                new ApcuAdapter($namespace, $defaultTtl),
                self::buildRedis($namespace, $defaultTtl),
                self::buildFile($namespace, $defaultTtl),
            ]),
            default => self::buildFile($namespace, $defaultTtl),
        };
    }

    private static function buildFile(string $namespace, int $defaultTtl): FilesystemAdapter
    {
        $directory = defined('PHPWG_ROOT_PATH')
            ? PHPWG_ROOT_PATH . Config::dataLocation() . 'cache/'
            : sys_get_temp_dir() . '/piwigo/cache/';
        return new FilesystemAdapter($namespace, $defaultTtl, $directory);
    }

    private static function buildRedis(string $namespace, int $defaultTtl): RedisAdapter
    {
        $url    = Config::cacheRedisUrl();
        $client = RedisAdapter::createConnection($url);
        return new RedisAdapter($client, $namespace, $defaultTtl);
    }
}
