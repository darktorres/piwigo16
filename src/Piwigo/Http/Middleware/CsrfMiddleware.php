<?php

declare(strict_types=1);

namespace Piwigo\Http\Middleware;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;

/**
 * CSRF token verification for state-changing POST requests.
 *
 * Verifies the pwg_token body parameter on every POST request except:
 *   /ws*          — web service API (manages its own auth)
 *   /admin*       — admin pages: already auth-gated and manage their own token checks
 *   /install      — installer (no session yet)
 *   /upgrade      — upgrader (no session yet)
 *   /identification — login form (no token in the standard form)
 *   /register     — registration form (no token in the standard form)
 *
 * If the token is present but wrong, check_pwg_token() calls access_denied().
 * If the token is missing, check_pwg_token() calls bad_request().
 */
final class CsrfMiddleware implements MiddlewareInterface
{
    private const array EXEMPT_PREFIXES = [
        '/ws',
        '/admin',
        '/install',
        '/upgrade',
        '/identification',
        '/register',
    ];

    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        if ($request->getMethod() === 'POST') {
            $path = is_string($request->getAttribute('_route_path'))
                ? $request->getAttribute('_route_path')
                : '/';

            if (!$this->isExempt($path)) {
                check_pwg_token();
            }
        }

        return $handler->handle($request);
    }

    private function isExempt(string $path): bool
    {
        return array_any(self::EXEMPT_PREFIXES, fn ($prefix): bool => $path === $prefix || str_starts_with($path, $prefix . '/'));
    }
}
