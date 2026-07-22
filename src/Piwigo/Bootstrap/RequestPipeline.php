<?php

declare(strict_types=1);

namespace Piwigo\Bootstrap;

use Piwigo\Core\Kernel;
use Piwigo\Http\Middleware\ControllerInvokerMiddleware;
use Piwigo\Http\Middleware\ExceptionHandlerMiddleware;
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
 * Runs the real middleware pipeline -- 7 middleware by default (every P22
 * root file's own call shape, unparametrized: `RequestPipeline::
 * handle($request)`).
 *
 * Order: Exception stays outermost to catch everything downstream.
 * SecurityHeaders next. Session before ServerTiming/Sentry so a session
 * exists before anything downstream might want request-scoped state
 * (nothing does yet). ServerTiming/Sentry wrap everything downstream of
 * security headers so they measure/trace the real work (routing +
 * controller invocation), not header assembly.
 *
 * Lives in Bootstrap/ (L4Integration), not Kernel (L1Infrastructure) --
 * orchestrating Http/Routing/Container together is genuinely an
 * integration concern, not infrastructure; deptrac.yaml only allows L1 to
 * depend on L0. Uses Kernel::container(), which an existing arch test
 * already restricts to Bootstrap/ + index.php -- this class is exactly the
 * kind of caller that boundary anticipated.
 *
 * Workstream C3 Part III: the optional $middleware parameter is the "per-
 * route middleware selection" this endpoint's own leaner bootstrap needs --
 * deliberately an explicit override the calling *entry file* opts into
 * (i.php passes self::WITHOUT_SESSION), not a config/routes.php `_middleware`
 * route default resolved dynamically. A route-default approach was
 * considered and rejected: RoutingMiddleware (which resolves the matched
 * route) is itself one of the 7 middleware being selected between, so
 * deciding the middleware set *from* the route match would need dispatching
 * every request twice (once to pick the middleware list, once for real) or
 * restructuring routing to run outside the pipeline entirely -- real
 * complexity this app has exactly one real use case for today. An
 * entry-file-level override needs neither: i.php already makes its own
 * request-shape decisions this way (see its own docblock), the same
 * pattern admin/popuphelp.php already uses for RequestMountDepth::set().
 */
final class RequestPipeline
{
    /**
     * @var list<class-string<MiddlewareInterface>>
     */
    public const array DEFAULT_MIDDLEWARE = [
        ExceptionHandlerMiddleware::class,
        SecurityHeadersMiddleware::class,
        SessionMiddleware::class,
        ServerTimingMiddleware::class,
        SentryMiddleware::class,
        RoutingMiddleware::class,
        ControllerInvokerMiddleware::class,
    ];

    /**
     * DEFAULT_MIDDLEWARE minus SessionMiddleware -- its own session_start()
     * call is pure overhead on a route whose permission check is already a
     * direct, DB-backed lookup that never touches native $_SESSION (see
     * Controller\ImageDerivativeController's own docblock).
     *
     * @var list<class-string<MiddlewareInterface>>
     */
    public const array WITHOUT_SESSION = [
        ExceptionHandlerMiddleware::class,
        SecurityHeadersMiddleware::class,
        ServerTimingMiddleware::class,
        SentryMiddleware::class,
        RoutingMiddleware::class,
        ControllerInvokerMiddleware::class,
    ];

    /**
     * @param ?list<class-string<MiddlewareInterface>> $middleware defaults
     *   to DEFAULT_MIDDLEWARE (the real 7-middleware list) when omitted
     */
    public static function handle(ServerRequestInterface $request, ?array $middleware = null): ResponseInterface
    {
        $container = Kernel::container();

        $notFound = new class() implements RequestHandlerInterface {
            #[\Override]
            public function handle(ServerRequestInterface $request): ResponseInterface
            {
                return ResponseFactory::text('Not Found', 404);
            }
        };

        return new MiddlewarePipeline(
            array_map(
                static fn (string $id): MiddlewareInterface => self::resolveMiddleware($container, $id),
                $middleware ?? self::DEFAULT_MIDDLEWARE,
            ),
            $notFound,
        )->handle($request);
    }

    /**
     * @param class-string<MiddlewareInterface> $id
     */
    private static function resolveMiddleware(ContainerInterface $container, string $id): MiddlewareInterface
    {
        $service = $container->get($id);
        if (! $service instanceof MiddlewareInterface) {
            throw new \LogicException("Container returned an unexpected type for '{$id}'.");
        }

        return $service;
    }
}
