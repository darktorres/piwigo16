<?php

declare(strict_types=1);

use Monolog\Formatter\JsonFormatter;
use Monolog\Handler\RotatingFileHandler;
use Monolog\Logger as MonologLogger;
use Piwigo\Cache\CacheFactory;
use Piwigo\Routing\Router;
use Psr\Cache\CacheItemPoolInterface;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;
use Psr\SimpleCache\CacheInterface;
use Symfony\Component\Cache\Psr16Cache;
use function DI\factory;

// DI\autowire() is the default -- a service with only typed class-reference
// constructor params needs no entry here at all; PHP-DI resolves it via
// reflection. Add an explicit entry only for:
//   - interface bindings (e.g. SomeInterface::class => \DI\get(SomeImpl::class))
//   - non-obvious construction (config values, factory methods, conditional logic)
//   - unresolvable string/config parameters
//
// This grows incrementally, one entry at a time, as later phases find a
// concrete class that genuinely needs one -- never pre-populated ahead of
// need. See src/Piwigo/Core/Container.php.

/**
 * @return array<string, mixed>
 */
return [
    // Unresolvable string param (the routes file path) -- Router::fromFile()
    // needs a path autowire can't provide.
    Router::class => factory(static fn (): Router => Router::fromFile(dirname(__DIR__) . '/config/routes.php')),

    // Non-obvious construction (handler + formatter wiring). Monolog "app"
    // channel only -- the "security" channel (a named $securityLogger
    // parameter) is deferred until a real consumer exists (P11/P16/P28).
    LoggerInterface::class => factory(static function (): LoggerInterface {
        $handler = new RotatingFileHandler(dirname(__DIR__) . '/_data/logs/piwigo.log', 30);
        $handler->setFormatter(new JsonFormatter());

        return new MonologLogger('app', [$handler]);
    }),

    // Non-obvious construction (adapter selection reads env vars --
    // Config::cacheAdapter() doesn't exist yet, P13). No named pools yet
    // (config/permissions/category_tree/tag_cloud/general/rate_limiter) --
    // none have a real consumer today; each gets its own entry only when
    // its owning phase lands.
    CacheItemPoolInterface::class => factory(static fn (): CacheItemPoolInterface => CacheFactory::create()),

    // PSR-16 wraps the same PSR-6 pool instance (container-shared by
    // default) rather than building a second one -- symfony/cache adapters
    // implement Symfony's own Contracts\Cache\CacheInterface, not PSR-16
    // directly; Psr16Cache is the real adapter for that.
    CacheInterface::class => factory(static function (ContainerInterface $c): CacheInterface {
        $pool = $c->get(CacheItemPoolInterface::class);
        if (! $pool instanceof CacheItemPoolInterface) {
            throw new \LogicException('Container returned an unexpected type for ' . CacheItemPoolInterface::class);
        }

        return new Psr16Cache($pool);
    }),
];
