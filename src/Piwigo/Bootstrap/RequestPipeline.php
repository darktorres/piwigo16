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
use Piwigo\Http\MiddlewarePipeline;
use Piwigo\Http\ResponseFactory;
use Psr\Container\ContainerInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;

/**
 * Runs the real 6-middleware pipeline. Not yet reachable from any real
 * request -- index.php still only calls CommonBootstrap::run() (P7);
 * nothing routes real traffic through here until P22 has real Controllers
 * for config/routes.php to reference. Trimmed to the middleware buildable
 * without Config/CurrentUser/Session/real Controllers -- Session/Auth/
 * Csrf/Filter land in P11/P16/P16+.
 *
 * Order: Exception stays outermost to catch everything downstream.
 * SecurityHeaders next. ServerTiming/Sentry wrap everything downstream of
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
    public static function handle(ServerRequestInterface $request): ResponseInterface
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
            [
                self::resolveMiddleware($container, ExceptionHandlerMiddleware::class),
                self::resolveMiddleware($container, SecurityHeadersMiddleware::class),
                self::resolveMiddleware($container, ServerTimingMiddleware::class),
                self::resolveMiddleware($container, SentryMiddleware::class),
                self::resolveMiddleware($container, RoutingMiddleware::class),
                self::resolveMiddleware($container, ControllerInvokerMiddleware::class),
            ],
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
