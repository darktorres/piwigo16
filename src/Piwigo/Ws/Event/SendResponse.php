<?php

declare(strict_types=1);

namespace Piwigo\Ws\Event;

/**
 * Typed event for the legacy `sendResponse` notification. No handler is
 * registered for it anywhere today. Co-located here from `Piwigo\Event\Ws\SendResponse` (P32 Stage A5 -- see `docs/events-legacy-map.md`).
 */
final readonly class SendResponse
{
    public function __construct(
        public string $encodedResponse,
    ) {}
}
