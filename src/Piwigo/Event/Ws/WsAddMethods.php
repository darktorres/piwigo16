<?php

declare(strict_types=1);

namespace Piwigo\Event\Ws;

/**
 * Typed event for legacy `ws_add_methods` (notify).
 *
 * Dispatched from: src/Piwigo/Ws/PwgServer.php
 */
final readonly class WsAddMethods
{
    public function __construct(
        public object $value,
    ) {
    }
}
