<?php

declare(strict_types=1);

namespace Piwigo\Http\Middleware;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;

/**
 * Ensures the PHP session is active before the rest of the pipeline runs.
 *
 * During the transition period, common.inc.php already starts the session via
 * pwg_session_start().  This middleware is a no-op when the session is already
 * active; it becomes the sole session starter once common.inc.php is removed
 * from the bootstrap.
 */
final class SessionMiddleware implements MiddlewareInterface
{
    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        if (session_status() === PHP_SESSION_NONE) {
            session_start();
        }

        return $handler->handle($request);
    }
}
