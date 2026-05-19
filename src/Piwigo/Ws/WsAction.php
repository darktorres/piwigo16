<?php

declare(strict_types=1);

namespace Piwigo\Ws;

/**
 * Per-endpoint action contract — replaces the legacy *Endpoints god-classes.
 *
 * Concrete actions look like:
 *
 *   final readonly class SessionLoginHandler implements WsAction
 *   {
 *       public function __construct(private AuthService $auth) {}
 *
 *       #[\Override]
 *       public function __invoke(array $params, PwgServer $server): mixed
 *       {
 *           // …
 *       }
 *   }
 *
 * Dispatch lives in {@see PwgServer::invoke()} — it resolves the action from
 * the container and calls it. The historical *Endpoints god-classes are being
 * replaced one method at a time per F5-h.
 */
interface WsAction
{
    /** @param array<mixed> $params */
    public function __invoke(array $params, PwgServer $server): mixed;
}
