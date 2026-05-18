<?php

declare(strict_types=1);

namespace Piwigo\Http;

/**
 * Tracks whether the current request authenticated via an HTTP API key
 * (header `X-Piwigo-API`, processed in {@see \Piwigo\Users\UserBootstrap}).
 *
 * Replaces the legacy `defined('PWG_API_KEY_REQUEST')` check. The flag is
 * orthogonal to {@see RequestContext} — an API-key request is always in
 * the `Ws` context, but session-login requests in the same context are
 * not API-key.
 *
 * Matches the established `RequestContextRegistry` /
 * `SectionContextRegistry` pattern: a thin static registry, one instance
 * per SAPI request.
 */
final class ApiKeyAuthRegistry
{
    private static bool $isApiKey = false;

    public static function markApiKeyAuth(): void
    {
        self::$isApiKey = true;
    }

    public static function isApiKeyAuth(): bool
    {
        return self::$isApiKey;
    }

    public static function reset(): void
    {
        self::$isApiKey = false;
    }
}
