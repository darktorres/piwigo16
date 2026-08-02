<?php

declare(strict_types=1);

namespace Piwigo\Event\Ws;

/**
 * Typed event for the legacy `sendResponse` notification. No handler is
 * registered for it anywhere today. $encodedResponse is `string|false`,
 * not the reference's plain `string` -- PwgServer::sendResponse()'s real
 * dispatch site goes through the abstract
 * PwgResponseEncoder::encodeResponse(): string|false interface (only
 * PwgJsonEncoder's json_encode() call can genuinely produce false; the
 * other 3 encoders are declared plain string), and the static type
 * PHPStan sees at the dispatch site is the interface's, not any one
 * concrete encoder's.
 */
final readonly class SendResponse
{
    public function __construct(
        public string|false $encodedResponse,
    ) {}
}
