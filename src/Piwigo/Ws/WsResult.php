<?php

declare(strict_types=1);

namespace Piwigo\Ws;

/**
 * Per-endpoint output contract — each handler that produces structured
 * data returns a {@see WsResult} instance; the dispatcher in
 * {@see PwgServer::invoke()} calls toArray() before JSON-encoding it
 * into the WS response envelope.
 *
 * Concrete result classes live next to their handler in
 * `src/Piwigo/Ws/Action/<Tag>/<Method>Result.php` and are
 * `final readonly` value objects with typed properties.
 */
interface WsResult
{
    /**
     * Convert this result to the wire-format array that PwgServer
     * JSON-encodes. Concrete classes should describe each top-level
     * key with the precise type they emit.
     *
     * @return array<string, mixed>
     */
    public function toArray(): array;
}
