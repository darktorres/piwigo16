<?php

declare(strict_types=1);

namespace Piwigo\Cache;

use Psr\Cache\CacheItemPoolInterface;
use Symfony\Component\Cache\Psr16Cache;

/**
 * PSR-16 (SimpleCache) facade over any PSR-6 pool.
 *
 * Usage:
 *   $simple = new Simple(CacheFactory::create());
 *   $simple->get('key', 'default');
 *   $simple->set('key', $value, 3600);
 */
final class Simple extends Psr16Cache
{
    public function __construct(CacheItemPoolInterface $pool)
    {
        parent::__construct($pool);
    }
}
