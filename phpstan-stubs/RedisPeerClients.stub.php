<?php

/**
 * Psalm-only type markers for two of the optional Redis client libraries
 * Symfony\Component\Cache\Adapter\RedisAdapter::createConnection()'s return
 * type accepts (\Redis|\RedisArray|\RedisCluster|\Predis\ClientInterface|
 * \Relay\Relay|\Relay\Cluster) -- CacheFactory::buildRedis() only ever picks
 * one at real runtime, based on whichever client library the deployment
 * actually installed, and forwards it straight into `new RedisAdapter(...)`
 * without calling any of its own members. Redis/RedisArray/RedisCluster/
 * Relay\Relay are already covered by the bundled
 * vendor/jetbrains/phpstorm-stubs package (see psalm.xml's <stubs>), but it
 * doesn't ship Relay\Cluster, and Predis\ClientInterface belongs to
 * predis/predis, a separate Packagist library with no bundled stub
 * anywhere -- empty marker declarations are enough since neither is ever
 * actually installed nor its members called in this codebase.
 */

namespace Relay {
    class Cluster
    {
    }
}

namespace Predis {
    interface ClientInterface
    {
    }
}
