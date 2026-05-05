<?php

declare(strict_types=1);

namespace Piwigo\Http\Middleware;

use Piwigo\Core\LoggerRegistry;
use Piwigo\Exception\PiwigoException;
use Piwigo\Http\ResponseFactory;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;

/**
 * Top-level exception catcher.
 *
 * Converts PiwigoException to structured error responses and logs all others
 * via PSR-3.  Prevents raw stack traces from leaking to the browser.
 */
final class ExceptionHandlerMiddleware implements MiddlewareInterface
{
    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        try {
            return $handler->handle($request);
        } catch (PiwigoException $e) {
            return ResponseFactory::html(
                sprintf('<h1>Error %d</h1><p>%s</p>', $e->getCode() ?: 500, htmlspecialchars($e->getMessage())),
                $e->getCode() ?: 500,
            );
        } catch (\Throwable $e) {
            LoggerRegistry::current()->error('Unhandled exception', [
                'exception' => $e,
                'uri'       => (string) $request->getUri(),
            ]);
            return ResponseFactory::html(
                '<h1>Internal Server Error</h1>',
                500,
            );
        }
    }
}
