<?php

declare(strict_types=1);

namespace Piwigo\Http\Middleware;

use Piwigo\Session\Session;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;

/**
 * Starts the PHP session (skipping if legacy common.inc.php already
 * started one -- it already registers PwgSession as the save handler on
 * every real request via include/functions_session.inc.php), then
 * hydrates/persists a Session VO as a request attribute.
 *
 * Deliberately does not register a save handler itself: reconciling
 * "legacy already called session_set_save_handler" vs "new pipeline also
 * wants to" is a real question for whichever phase wires RequestPipeline
 * into live traffic (P22+), not this one's to answer prematurely --
 * calling session_set_save_handler() after a session is already active is
 * a hard PHP error, not just a warning.
 *
 * session_start() can silently fail to activate (e.g. once any output has
 * already been sent -- confirmed empirically under CLI, not just
 * documented behavior) without raising an exception, leaving $_SESSION
 * unset. $_SESSION ??= [] guards that regardless of cause, not just for
 * CLI/test contexts.
 */
final class SessionMiddleware implements MiddlewareInterface
{
    #[\Override]
    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        if (session_status() !== PHP_SESSION_ACTIVE) {
            session_start();
        }
        $_SESSION ??= [];

        $session = Session::fromSuperglobal($_SESSION);

        $response = $handler->handle($request->withAttribute(Session::class, $session));

        $session->persistInto($_SESSION);

        return $response;
    }
}
