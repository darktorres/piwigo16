<?php

declare(strict_types=1);

namespace Piwigo\Http\Middleware;

use Piwigo\Users\CurrentUser;
use Piwigo\Users\UserBootstrap;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;

/**
 * Resolves the current user from session / cookie / Apache auth / auth-key
 * and attaches the CurrentUser instance as the '_current_user' request attribute.
 *
 * Calls UserBootstrap::bootstrap() which populates $GLOBALS['user'] and
 * calls CurrentUser::attachGlobals(). Downstream controllers and templates
 * read $GLOBALS['user'] as before; typed code reads the request attribute.
 */
final class AuthMiddleware implements MiddlewareInterface
{
    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        UserBootstrap::bootstrap();
        return $handler->handle($request->withAttribute('_current_user', CurrentUser::get()));
    }
}
