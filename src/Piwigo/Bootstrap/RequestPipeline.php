<?php

declare(strict_types=1);

namespace Piwigo\Bootstrap;

use LogicException;
use Override;
use Piwigo\Admin\LoadedPluginsMiddleware;
use Piwigo\Core\Kernel;
use Piwigo\Http\Middleware\ApiErrorMiddleware;
use Piwigo\Http\Middleware\ConfigBootstrapMiddleware;
use Piwigo\Http\Middleware\ControllerInvokerMiddleware;
use Piwigo\Http\Middleware\ExceptionHandlerMiddleware;
use Piwigo\Http\Middleware\LanguageMiddleware;
use Piwigo\Http\Middleware\PluginBootstrapMiddleware;
use Piwigo\Http\Middleware\RoutingMiddleware;
use Piwigo\Http\Middleware\SecurityHeadersMiddleware;
use Piwigo\Http\Middleware\SentryMiddleware;
use Piwigo\Http\Middleware\ServerTimingMiddleware;
use Piwigo\Http\Middleware\SessionMiddleware;
use Piwigo\Http\MiddlewarePipeline;
use Piwigo\Http\ResponseFactory;
use Psr\Container\ContainerInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;

/**
 * Runs the real middleware pipeline -- 14 middleware now (workstream C3
 * Phase 1), every root file's own call shape:
 * `RequestPipeline::handle($request)`.
 *
 * Order: Exception stays outermost to catch everything downstream.
 * SecurityHeaders next. ServerTiming/Sentry moved outward, ahead of
 * BOOTSTRAP_MIDDLEWARE below (formerly `RequestBootstrap::connect()`/the
 * first half of `finalize()`, which ran entirely *before* this pipeline
 * even started) so they keep measuring/tracing the real bootstrap work
 * (DB connect, plugin load, user resolution, language load) the same way
 * `bootEntryPoint()`'s own 'boot' `ServerTiming` bracket always did, not
 * just routing + controller invocation. `ConfigBootstrapMiddleware`
 * (error-collector install, session save-handler registration, DB
 * connect, DB-backed config load, logger construction) must run before
 * `SessionMiddleware` (`session_start()`), matching `connect()`'s own
 * literal statement order -- `SessionBootstrap::register()` has to be
 * called before `session_start()` for its `session_set_save_handler()`
 * call to take effect. `PluginBootstrapMiddleware`/`Admin\
 * LoadedPluginsMiddleware`/`UserResolutionMiddleware`/`LanguageMiddleware`/
 * `FinalizeBridgeMiddleware` preserve `connect()`/`finalize()`'s own
 * remaining relative order exactly. All 7 run before `RoutingMiddleware`,
 * matching the pre-Phase-1 shape where connect()/finalize() always
 * completed before routing/controller invocation ever started.
 * `Http\Middleware\ApiErrorMiddleware` (P27) runs between
 * `RoutingMiddleware` and `ControllerInvokerMiddleware` -- it needs the
 * real `RouteResult`, and it must win before `ControllerInvokerMiddleware`'s
 * own generic 404 fallback for any `/api/v1/...` path whose route didn't
 * resolve to `Found`.
 *
 * Lives in Bootstrap/ (L4Integration), not Kernel (L1Infrastructure) --
 * orchestrating Http/Routing/Container together is genuinely an
 * integration concern, not infrastructure; deptrac.yaml only allows L1 to
 * depend on L0. Uses Kernel::container(), which an existing arch test
 * already restricts to Bootstrap/ + index.php -- this class is exactly the
 * kind of caller that boundary anticipated.
 */
final class RequestPipeline
{
    /**
     * The 7 real bootstrap-phase middleware -- formerly `RequestBootstrap::
     * connect()`'s and the first half of `finalize()`'s own procedural
     * body. Exposed separately from DEFAULT_MIDDLEWARE (which appends
     * routing/controller invocation to this same list) so
     * `public/admin.php` can run exactly this work on its own: unlike
     * every other real entry point, it never calls `handle()` at all --
     * it constructs `Admin\AdminShell` directly and calls its own
     * `run()`, a real, pre-Phase-1, still-unreconciled bypass of the
     * unified pipeline (see `runBootstrapPhase()`'s own docblock and
     * workstream C3's own Phase 3, "reconcile AdminShell/admin.php" --
     * investigation only, not designed in detail, this is not that
     * reconciliation, only the minimum fix so admin.php keeps working
     * after Phase 1 moved connect()/finalize()'s work out of
     * bootEntryPoint()). No `ExceptionHandlerMiddleware`/
     * `SecurityHeadersMiddleware`/`ServerTimingMiddleware`/
     * `SentryMiddleware` wrapping here -- `admin.php` never had those
     * benefits even before Phase 1 (Plan 3's own Context section
     * documents this as a separate, already-known gap, Phase 3's to
     * close), so omitting them here preserves that exact pre-existing
     * behavior rather than silently expanding this fix's own scope.
     *
     * @var list<class-string<MiddlewareInterface>>
     */
    public const array BOOTSTRAP_MIDDLEWARE = [
        ConfigBootstrapMiddleware::class,
        SessionMiddleware::class,
        PluginBootstrapMiddleware::class,
        LoadedPluginsMiddleware::class,
        UserResolutionMiddleware::class,
        LanguageMiddleware::class,
        FinalizeBridgeMiddleware::class,
    ];

    /**
     * @var list<class-string<MiddlewareInterface>>
     */
    public const array DEFAULT_MIDDLEWARE = [
        ExceptionHandlerMiddleware::class,
        SecurityHeadersMiddleware::class,
        ServerTimingMiddleware::class,
        SentryMiddleware::class,
        ...self::BOOTSTRAP_MIDDLEWARE,
        RoutingMiddleware::class,
        ApiErrorMiddleware::class,
        ControllerInvokerMiddleware::class,
    ];

    public static function handle(ServerRequestInterface $request): ResponseInterface
    {
        $container = Kernel::container();

        $notFound = new class() implements RequestHandlerInterface {
            #[Override]
            public function handle(ServerRequestInterface $request): ResponseInterface
            {
                return ResponseFactory::text('Not Found', 404);
            }
        };

        return new MiddlewarePipeline(
            array_map(
                static fn (string $id): MiddlewareInterface => self::resolveMiddleware($container, $id),
                self::DEFAULT_MIDDLEWARE,
            ),
            $notFound,
        )->handle($request);
    }

    /**
     * Runs just BOOTSTRAP_MIDDLEWARE for `public/admin.php`'s own benefit
     * -- the one real entry point that never calls `handle()` at all
     * (see BOOTSTRAP_MIDDLEWARE's own docblock for why). Returns `null`
     * when the chain completed normally (every real caller then proceeds
     * to build and run `Admin\AdminShell` itself, exactly as if
     * connect()/finalize() had returned normally pre-Phase-1); returns a
     * real `ResponseInterface` when a bootstrap-phase middleware
     * short-circuited (a `Http\ResponseReadyException` -- the
     * install-sentinel redirect can't reach here since `configure()`
     * already handled it inside `bootEntryPoint()`, but the gallery-locked
     * 503 check and `Http\Middleware\ConfigBootstrapMiddleware`'s own
     * DB-unreachable `fatalError()` both still can) -- the caller must
     * emit that response and stop, exactly what `bootEntryPoint()`'s own
     * combined try/catch used to do for connect()/finalize() pre-Phase-1,
     * now split across two callers since admin.php doesn't go through
     * `handle()`'s own real `MiddlewarePipeline` at all.
     *
     * Distinguishes "short-circuited" from "completed normally" by
     * identity against a private, single-use sentinel response this
     * method's own fallback handler returns -- `MiddlewarePipeline`
     * (workstream C3 Phase 0) already converts a short-circuit into a
     * normal-looking return value at every nesting level, so there is no
     * thrown exception left here to catch; the sentinel is the only
     * reliable signal that BOOTSTRAP_MIDDLEWARE's own last entry,
     * `FinalizeBridgeMiddleware`, actually reached its own trailing
     * `$handler->handle($request)` call rather than a short-circuit
     * unwinding before it.
     */
    public static function runBootstrapPhase(ServerRequestInterface $request): ?ResponseInterface
    {
        $container = Kernel::container();

        $sentinel = ResponseFactory::text('', 204);
        $completedNormally = new readonly class($sentinel) implements RequestHandlerInterface {
            public function __construct(
                private ResponseInterface $sentinel,
            ) {}

            #[Override]
            public function handle(ServerRequestInterface $request): ResponseInterface
            {
                return $this->sentinel;
            }
        };

        $response = new MiddlewarePipeline(
            array_map(
                static fn (string $id): MiddlewareInterface => self::resolveMiddleware($container, $id),
                self::BOOTSTRAP_MIDDLEWARE,
            ),
            $completedNormally,
        )->handle($request);

        return $response === $sentinel ? null : $response;
    }

    /**
     * @param class-string<MiddlewareInterface> $id
     */
    private static function resolveMiddleware(ContainerInterface $container, string $id): MiddlewareInterface
    {
        $service = $container->get($id);
        if (! $service instanceof MiddlewareInterface) {
            throw new LogicException("Container returned an unexpected type for '{$id}'.");
        }

        return $service;
    }
}
