<?php

declare(strict_types=1);

// opcache.preload directive -- see docs/DEVELOPMENT.md. Preloads hot
// classes into shared memory at Apache start, eliminating file reads +
// autoloader lookups per request. Documented but NOT enabled in any
// php.ini here (a deployment-config step); CI runs without preload (tests
// need fresh resolution) per the doc's own text.
//
// Scoped to classes that exist today. Grows automatically as later
// phases add their own hot classes -- no phase "owns" finishing this
// list. The doc's full target list (Config, ConfigLoader, Paths, AppInfo,
// EntityManager, Connection) references classes that don't exist yet
// (P13/P14/P16).

require __DIR__ . '/../vendor/autoload.php';

foreach ([
    \Piwigo\Core\Kernel::class,
    \Piwigo\Core\Container::class,
    \Piwigo\Core\ServerTiming::class,
    \Piwigo\Core\FeatureFlag::class,
    \Piwigo\Bootstrap\RequestPipeline::class,
    \Piwigo\Bootstrap\SentryBootstrap::class,
    \Piwigo\Http\RequestFactory::class,
    \Piwigo\Http\ResponseEmitter::class,
    \Piwigo\Http\ResponseFactory::class,
    \Piwigo\Http\MiddlewarePipeline::class,
    \Piwigo\Http\Middleware\ExceptionHandlerMiddleware::class,
    \Piwigo\Http\Middleware\SecurityHeadersMiddleware::class,
    \Piwigo\Http\Middleware\SessionMiddleware::class,
    \Piwigo\Http\Middleware\ServerTimingMiddleware::class,
    \Piwigo\Http\Middleware\SentryMiddleware::class,
    \Piwigo\Http\Middleware\RoutingMiddleware::class,
    \Piwigo\Http\Middleware\ControllerInvokerMiddleware::class,
    \Piwigo\Routing\Router::class,
    \Piwigo\Cache\CacheFactory::class,
] as $class) {
    class_exists($class);
}
