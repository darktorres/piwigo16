<?php

declare(strict_types=1);

namespace Piwigo\Auth\Projection;

/**
 * {@see \Piwigo\Auth\ApiKeyService::get()}/{@see
 * \Piwigo\Auth\ApiKeyService::getAvailable()}'s own fixed, display-
 * formatted row shape -- {@see ApiKey::toArray()}'s shape (secret
 * redacted) plus the computed display fields the profile/admin API-key
 * list needs.
 */
final readonly class ApiKeySummary
{
    public function __construct(
        public string $authKey,
        public string $apikeySecret,
        public string $apikeyName,
        public string $createdOn,
        public ?int $duration,
        public string $expiredOn,
        public ?string $revokedOn,
        public ?string $lastUsedOn,
        public bool $isExpired,
        public string $expiration,
    ) {}
}
