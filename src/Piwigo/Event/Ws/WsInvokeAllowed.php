<?php

declare(strict_types=1);

namespace Piwigo\Event\Ws;

/**
 * Typed event for legacy `ws_invoke_allowed` (dispatch).
 *
 * Dispatched from: src/Piwigo/Ws/PwgServer.php
 */
final class WsInvokeAllowed
{
    /**
     * Listeners flip $value to a PwgError instance to block invocation.
     * The dispatch caller treats `$value === true` as "allowed" and any
     * other value as a block (legacy callers returned the PwgError directly).
     *
     * @param array<mixed> $params
     */
    public function __construct(
        public bool|\Piwigo\Ws\PwgError $value,
        public readonly string $methodName,
        public readonly array $params,
    ) {
    }
}
