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
 * and persists Session-owned flash messages back into `$_SESSION` after
 * the handler returns.
 *
 * The Session factory in container.php hydrates from `$_SESSION` on first
 * resolution (the container is per-request, so this fires at most once per
 * request). Constructor injection here makes that resolution eager — every
 * pipeline run produces a hydrated Session, so downstream consumers that
 * still touch raw `$_SESSION` are not racing with the typed VO.
 *
 * During the F5-c migration window the middleware only calls
 * persistFlashInto() — Session's flash bag is the one slot exclusively
 * owned by the typed VO. A full persistInto() would clobber `$_SESSION`
 * writes still made by unmigrated consumers (e.g. AuthService writing
 * pwg_uid directly during login). Once F5-c is complete this swaps to
 * persistInto() in one line.
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

        try {
            return $handler->handle($request);
        } finally {
            $this->session->persistFlashInto($_SESSION);
        }
    }
}
