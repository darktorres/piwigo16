<?php

declare(strict_types=1);

namespace Piwigo\Core;

use Piwigo\Bootstrap\Container;
use Piwigo\Config\Config;
use Piwigo\Http\Middleware\AuthMiddleware;
use Piwigo\Http\Middleware\ControllerInvokerMiddleware;
use Piwigo\Http\Middleware\CsrfMiddleware;
use Piwigo\Http\Middleware\ExceptionHandlerMiddleware;
use Piwigo\Http\Middleware\FilterMiddleware;
use Piwigo\Http\Middleware\RoutingMiddleware;
use Piwigo\Http\Middleware\SessionMiddleware;
use Piwigo\Http\MiddlewarePipeline;
use Piwigo\Storage\StorageRegistry;
use Piwigo\Users\CurrentUser;
use Psr\Container\ContainerInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;
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

        PageState::current();  // initialise singleton
        Lang::attachGlobals();
        CurrentUser::attachGlobals();

        self::$container = Container::build();

        // Seed LoggerRegistry with a NullLogger if common.inc.php hasn't set a real one yet
        // (index.php?/install and index.php?/upgrade bypass common.inc.php).
        if (!LoggerRegistry::isInitialized()) {
            LoggerRegistry::set(new NullLogger());
        }

        // Eagerly wire the StorageRegistry so StorageRegistry::disk() works
        // from procedural upload code without going through the container.
        self::$container->get(StorageRegistry::class);

        // MigrationRunner::migrate() is intentionally NOT called here.
        // It runs in CommonBootstrap::run() after ConfigService::loadConfFromDb(),
        // because migrations depend on Config::$data being populated from the DB.
    }

    /**
     * Run the PSR-15 middleware pipeline for the given request.
     * Must be called after boot().
     */
    public static function handle(ServerRequestInterface $request): ResponseInterface
    {
        if (self::$container === null) {
            throw new \LogicException('Kernel not booted — call Kernel::boot() first.');
        }

        $unreachable = new class () implements RequestHandlerInterface {
            #[\Override]
            public function handle(ServerRequestInterface $request): ResponseInterface
            {
                throw new \LogicException('Pipeline terminal handler reached — this is a bug.');
            }
        };

        return new MiddlewarePipeline(
            [
                self::service(ExceptionHandlerMiddleware::class),
                self::service(SessionMiddleware::class),
                self::service(AuthMiddleware::class),
                self::service(FilterMiddleware::class),
                self::service(CsrfMiddleware::class),
                self::service(RoutingMiddleware::class),
                self::service(ControllerInvokerMiddleware::class),
            ],
            $unreachable,
        )->handle($request);
    }

    public static function container(): ContainerInterface
    {
        if (self::$container === null) {
            throw new \LogicException('Kernel not booted — call Kernel::boot() first.');
        }
        return self::$container;
    }

    /**
     * Typed service lookup for static-bootstrap contexts that cannot accept
     * constructor injection (entry-point controllers' static helpers, the
     * CommonBootstrap orchestrator, etc.). Service classes themselves must
     * declare their dependencies in constructors and never call this.
     *
     * @template T of object
     * @param class-string<T> $id
     * @return T
     */
    public static function service(string $id): object
    {
        if (self::$container === null) {
            throw new \LogicException('Kernel not booted — call Kernel::boot() first.');
        }
        $service = self::$container->get($id);
        if (!$service instanceof $id) {
            throw new \LogicException("Container returned an unexpected type for '{$id}'.");
        }
        return $service;
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
    }
}
