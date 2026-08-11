<?php

declare(strict_types=1);

namespace Piwigo\Http\Middleware;

use Override;
use Piwigo\Http\ResponseFactory;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;
use Psr\Log\LoggerInterface;
use Throwable;
use function Sentry\captureException;

/**
 * Catches everything downstream, logs it, reports it to Sentry, and returns
 * a generic 500. LoggerInterface resolves to the Monolog "app" channel
 * (config/container.php). captureException() is a safe no-op when the
 * Sentry SDK isn't initialized (no DSN configured), per the installed SDK
 * source.
 */
final readonly class ExceptionHandlerMiddleware implements MiddlewareInterface
{
    public function __construct(
        private LoggerInterface $logger
    ) {}

    #[Override]
    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        try {
            return $handler->handle($request);
        } catch (Throwable $e) {
            $this->logger->error('Unhandled exception', [
                'exception' => $e::class,
                'message' => $e->getMessage(),
                'path' => $request->getUri()
                    ->getPath(),
            ]);
            captureException($e);

            return ResponseFactory::text('Internal Server Error', 500);
        }
    }
}
