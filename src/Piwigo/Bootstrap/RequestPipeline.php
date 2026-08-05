<?php

declare(strict_types=1);

namespace Piwigo\Bootstrap;

use LogicException;
use Override;
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
 * Runs the real middleware pipeline -- 7 middleware, every root file's own
 * call shape: `RequestPipeline::handle($request)`.
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
