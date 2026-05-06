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
            // In Xdebug develop mode re-throw so Xdebug can render the full
            // stack trace; the handler registered by ExceptionHandler::register()
            // is intentionally skipped in that mode, so we must propagate here.
            if (extension_loaded('xdebug') && str_contains((string) ini_get('xdebug.mode'), 'develop')) {
                throw $e;
            }

            if (LoggerRegistry::isInitialized()) {
                LoggerRegistry::current()->error('Unhandled exception', [
                    'exception' => $e,
                    'uri'       => (string) $request->getUri(),
                ]);
            } else {
                error_log($e->getMessage() . ' in ' . $e->getFile() . ':' . $e->getLine());
            }

            return ResponseFactory::html(
                '<h1>Internal Server Error</h1>',
                500,
            );
        }
    }
}
