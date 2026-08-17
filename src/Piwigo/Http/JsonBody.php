<?php

declare(strict_types=1);

namespace Piwigo\Http;

use JsonException;
use Psr\Http\Message\ServerRequestInterface;

/**
 * The `/api/v1` surface's own request-body decoder (P27) -- shared the
 * same way CsrfGuard/AdminGuard are, since every mutating endpoint needs
 * to turn its JSON body into a plain array before building its own typed
 * input DTO (mirroring `Ws\WsParams::fromArray()`'s own per-method shape,
 * just fed from a decoded JSON body instead of WS's already-array
 * `$params`).
 */
final class JsonBody
{
    /**
     * Empty body decodes to []  -- callers with all-optional fields (rare
     * but possible) shouldn't have to special-case a missing body.
     *
     * @return array<int|string, mixed>
     */
    public static function decode(ServerRequestInterface $request): array
    {
        $raw = (string) $request->getBody();
        if (trim($raw) === '') {
            return [];
        }

        try {
            $decoded = json_decode($raw, true, flags: JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            throw new ResponseReadyException(
                ResponseFactory::problem('Bad Request', 400, 'The request body is not valid JSON.')
            );
        }

        return is_array($decoded) ? $decoded : [];
    }
}
