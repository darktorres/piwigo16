<?php

declare(strict_types=1);

namespace Piwigo\Event\Ws;

/**
 * Typed event for legacy `sendResponse` (notify).
 *
 * Dispatched from: src/Piwigo/Ws/PwgServer.php
 */
final readonly class SendResponse
{
    public function __construct(
        public string $encodedResponse,
    ) {
    }
}
