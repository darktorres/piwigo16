<?php

declare(strict_types=1);

namespace Piwigo\Http\Middleware;

use Piwigo\Session\Session;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;

/**
 * Ensures the PHP session is active before the rest of the pipeline runs
 * and persists Session mutations back into `$_SESSION` after the handler
 * returns.
 *
 * The Session factory in container.php hydrates from `$_SESSION` on first
 * resolution (the container is per-request, so this fires at most once per
 * request). Constructor injection here makes that resolution eager — every
 * pipeline run produces a hydrated Session.
 *
 * persistInto() is snapshot-diff: it writes only the slots whose values
 * differ from the hydration snapshot, so raw `$_SESSION` writes made by
 * still-unmigrated consumers (e.g. AuthService writing pwg_uid during
 * login) survive untouched — Session has no diff for keys it didn't
 * mutate.
 */
final readonly class SessionMiddleware implements MiddlewareInterface
{
    public function __construct(private Session $session)
    {
    }

    #[\Override]
    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        if (session_status() === PHP_SESSION_NONE) {
            session_start();
        }
        $_SESSION ??= [];

        try {
            return $handler->handle($request);
        } finally {
            $this->session->persistInto($_SESSION);
        }
    }
}
