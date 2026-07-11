<?php

declare(strict_types=1);

namespace Piwigo\Http\Middleware;

use Piwigo\Http\ResponseFactory;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;

/**
 * Minimal: catches everything downstream and returns a generic 500. No
 * logging yet -- LoggerRegistry doesn't exist; Monolog wiring is P10's job.
 */
final class ExceptionHandlerMiddleware implements MiddlewareInterface
{
    #[\Override]
    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        try {
            return $handler->handle($request);
        } catch (\Throwable) {
            return ResponseFactory::text('Internal Server Error', 500);
        }
    }
}
