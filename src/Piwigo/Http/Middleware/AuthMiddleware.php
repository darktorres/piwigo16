<?php

declare(strict_types=1);

namespace Piwigo\Http\Middleware;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;

/**
 * Resolves the current user and attaches identity to the request.
 *
 * Phase-3 bridge: during the transition the user bootstrap is handled by
 * include/user.inc.php (loaded via common.inc.php before Kernel::handle() is
 * called) so this middleware is intentionally a pass-through.
 *
 * When include/user.inc.php is migrated to UserBootstrap (Wave A, Phase 4),
 * this middleware will call UserBootstrap::resolve() and attach the resulting
 * CurrentUser instance as a request attribute.
 */
final class AuthMiddleware implements MiddlewareInterface
{
    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        return $handler->handle($request);
    }
}
