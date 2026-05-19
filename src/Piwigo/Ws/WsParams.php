<?php

declare(strict_types=1);

namespace Piwigo\Ws;

/**
 * Per-endpoint input contract — each handler that consumes structured
 * request parameters defines a {@see WsParams} subclass whose
 * `fromArray()` factory hand-validates the incoming HTTP `$params`
 * map (already pre-validated against the cheap signature shape by
 * PwgServer::invoke()) into typed properties.
 *
 * Concrete params classes live next to their handler in
 * `src/Piwigo/Ws/Action/<Tag>/<Method>Params.php` and are
 * `final readonly` value objects.
 *
 * Implementations throw {@see WsParamException} on malformed input so
 * the dispatcher can return a PwgError with the validation message.
 */
interface WsParams
{
    /**
     * Parse the raw `$params` array passed to the handler into typed
     * properties.
     *
     * @param array<int|string, mixed> $raw
     * @throws WsParamException
     */
    public static function fromArray(array $raw): static;
}
