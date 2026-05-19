<?php

declare(strict_types=1);

namespace Piwigo\Ws;

/**
 * Marker interface for per-endpoint action classes.
 *
 * Concrete actions look like:
 *
 *   final readonly class SessionLoginHandler implements WsAction
 *   {
 *       public function __construct(private AuthService $auth) {}
 *
 *       public function handle(SessionLoginParams $p): SessionLoginResult|PwgError
 *       {
 *           // …
 *       }
 *   }
 *
 * Dispatch lives in {@see PwgServer::invoke()} — it resolves the action
 * from the container, calls Params::fromArray() (throws WsParamException
 * on bad input → returns PwgError), invokes handle(), then toArray()s the
 * Result before JSON-encoding. The historical *Endpoints god-classes are
 * being replaced one method at a time per F5-h.
 */
interface WsAction
{
}
