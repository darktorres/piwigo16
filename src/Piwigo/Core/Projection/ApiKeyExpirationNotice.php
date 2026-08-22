<?php

declare(strict_types=1);

namespace Piwigo\Core\Projection;

/**
 * {@see \Piwigo\Core\PageState::$notifyApiKeyExpiration}'s own fixed
 * `{days_left, dbnow, auth_key}` shape -- set by {@see
 * \Piwigo\Auth\AuthService}'s login flow when a user's API key is about to
 * expire, read back by {@see \Piwigo\Http\Middleware\LanguageMiddleware}
 * once language is loaded, to send the actual notification email.
 */
final readonly class ApiKeyExpirationNotice
{
    public function __construct(
        public int $daysLeft,
        public string $dbnow,
        public string $authKey,
    ) {}
}
