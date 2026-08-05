<?php

declare(strict_types=1);

use Piwigo\Bootstrap\RequestPipeline;
use Piwigo\Bootstrap\SentryBootstrap;
use Piwigo\Cache\CacheFactory;
use Piwigo\Core\Container;
use Piwigo\Core\FeatureFlag;
use Piwigo\Core\Kernel;
use Piwigo\Core\ServerTiming;
use Piwigo\Http\Middleware\ControllerInvokerMiddleware;
use Piwigo\Http\Middleware\ExceptionHandlerMiddleware;
use Piwigo\Http\Middleware\RoutingMiddleware;
use Piwigo\Http\Middleware\SecurityHeadersMiddleware;
use Piwigo\Http\Middleware\SentryMiddleware;
use Piwigo\Http\Middleware\ServerTimingMiddleware;
use Piwigo\Http\Middleware\SessionMiddleware;
use Piwigo\Http\MiddlewarePipeline;
use Piwigo\Http\RequestFactory;
use Piwigo\Http\ResponseEmitter;
use Piwigo\Http\ResponseFactory;
use Piwigo\Routing\Router;

// opcache.preload directive -- see docs/REFERENCE.md. Preloads hot
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
    Kernel::class,
    Container::class,
    ServerTiming::class,
    FeatureFlag::class,
    RequestPipeline::class,
    SentryBootstrap::class,
    RequestFactory::class,
    ResponseEmitter::class,
    ResponseFactory::class,
    MiddlewarePipeline::class,
    ExceptionHandlerMiddleware::class,
    SecurityHeadersMiddleware::class,
    SessionMiddleware::class,
    ServerTimingMiddleware::class,
    SentryMiddleware::class,
    RoutingMiddleware::class,
    ControllerInvokerMiddleware::class,
    Router::class,
    CacheFactory::class,
] as $class) {
    class_exists($class);
}
