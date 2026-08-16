<?php

declare(strict_types=1);

namespace Piwigo\Event\Ws;

/**
 * Typed event for the legacy `sendResponse` notification. No handler is
 * registered for it anywhere today.
 */
final readonly class SendResponse
{
    public function __construct(
        public string $encodedResponse,
    ) {}
}
