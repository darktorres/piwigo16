<?php

declare(strict_types=1);

namespace Piwigo\Core;

use Piwigo\Bootstrap\Container;
use Piwigo\Config\Config;
use Piwigo\Http\MiddlewarePipeline;
use Piwigo\Http\Middleware\AuthMiddleware;
use Piwigo\Http\Middleware\ControllerInvokerMiddleware;
use Piwigo\Http\Middleware\CsrfMiddleware;
use Piwigo\Http\Middleware\ExceptionHandlerMiddleware;
use Piwigo\Http\Middleware\FallbackHandler;
use Piwigo\Http\Middleware\FilterMiddleware;
use Piwigo\Http\Middleware\RoutingMiddleware;
use Piwigo\Http\Middleware\SessionMiddleware;
use Piwigo\Migrations\MigrationRunner;
use Piwigo\Storage\StorageRegistry;
use Piwigo\Users\CurrentUser;
use Psr\Container\ContainerInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Log\NullLogger;

/**
 * Single boot entry point for the typed-service layer.
 *
 * Call order: common.inc.php runs first (ConfigLoader populates Config::$data
 * directly; legacy code populates $page/$user/$lang via the bootstrap dance),
 * then every root entry point calls Kernel::boot() immediately after. boot()
 * wires PageState / CurrentUser / Lang to their respective $GLOBALS via
 * reference bridges so legacy procedural reads stay coherent with the typed
 * facades. (Config no longer needs a bridge — ConfigLoader writes directly
 * to Config::$data and all readers/writers are migrated.)
 *
 * The guard (self::$booted) makes the call idempotent: nested entry points that
 * include common.inc.php a second time will not re-wire and corrupt references.
 */
final class Kernel
{
    private static bool $booted = false;
    private static ?ContainerInterface $container = null;

    public static function boot(): void
    {
        if (self::$booted) {
            return;
        }
        self::$booted = true;

        PageState::attachGlobals();
        Lang::attachGlobals();
        CurrentUser::attachGlobals();

        self::$container = Container::build();
        ServiceLocator::setContainer(self::$container);

        // Seed LoggerRegistry with a NullLogger if common.inc.php hasn't set a real one yet
        // (install.php and upgrade.php do not include common.inc.php).
        if (!LoggerRegistry::isInitialized()) {
            LoggerRegistry::set(new NullLogger());
        }

        // Eagerly wire the StorageRegistry so StorageRegistry::disk() works
        // from procedural upload code without going through the container.
        self::$container->get(StorageRegistry::class);

        if (Config::autoMigrate()) {
            MigrationRunner::migrate();
        }
    }

    /**
     * Run the PSR-15 middleware pipeline for the given request.
     *
     * Must be called after boot(). During the Wave-A/B migration the pipeline
     * falls back to FallbackHandler (404) for routes whose controllers are not
     * yet implemented; that is expected and harmless while index.php still uses
     * the legacy procedural flow.
     */
    public static function handle(ServerRequestInterface $request): ResponseInterface
    {
        if (self::$container === null) {
            throw new \LogicException('Kernel not booted — call Kernel::boot() first.');
        }

        return (new MiddlewarePipeline(
            [
                ServiceLocator::get(ExceptionHandlerMiddleware::class),
                ServiceLocator::get(SessionMiddleware::class),
                ServiceLocator::get(AuthMiddleware::class),
                ServiceLocator::get(FilterMiddleware::class),
                ServiceLocator::get(CsrfMiddleware::class),
                ServiceLocator::get(RoutingMiddleware::class),
                ServiceLocator::get(ControllerInvokerMiddleware::class),
            ],
            ServiceLocator::get(FallbackHandler::class),
        ))->handle($request);
    }

    public static function container(): ContainerInterface
    {
        if (self::$container === null) {
            throw new \LogicException('Kernel not booted — call Kernel::boot() first.');
        }
        return self::$container;
    }

    public static function isBooted(): bool
    {
        return self::$booted;
    }

    // ---- Test helpers ----------------------------------------------------

    public static function reset(): void
    {
        self::$booted = false;
        self::$container = null;
        Config::reset();
        PageState::reset();
        Lang::reset();
        LanguageStack::reset();
        CurrentUser::reset();
        StorageRegistry::reset();
        ServiceLocator::reset();
    }
}
