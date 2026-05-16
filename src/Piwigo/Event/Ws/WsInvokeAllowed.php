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
     * @param array<mixed> $params
     */
    public function __construct(
        public bool $value,
        public readonly string $methodName,
        public readonly array $params,
    ) {
    }
}
