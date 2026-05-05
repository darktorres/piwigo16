<?php

declare(strict_types=1);

namespace Piwigo\Http\Middleware;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;

/**
 * CSRF token verification for state-changing requests.
 *
 * Phase-3 stub: no-op while legacy entry-scripts each verify pwg_token
 * themselves.  Wave-A+ will enable enforcement by reading the '_csrf_exempt'
 * request attribute and verifying the pwg_token body parameter.
 */
final class CsrfMiddleware implements MiddlewareInterface
{
    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        return $handler->handle($request);
    }
}
