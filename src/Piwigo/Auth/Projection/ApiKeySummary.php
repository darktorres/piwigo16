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
        public ?string $lastNotifiedOn,
        public string $createdOnFormat,
        public string $expiredOnFormat,
        public string $lastUsedOnSince,
        public bool $isExpired,
        public string $expiration,
        public string $expiredOnSince,
        public ?string $revokedOnSince,
        public ?string $revokedOnMessage,
    ) {}

    /**
     * {@see \Piwigo\Ws\Users\GetApiKeyHandler} returns a list of these
     * directly as the WS RPC response -- unbox to array at that
     * wire-format boundary.
     *
     * @return array{auth_key: string, apikey_secret: string, apikey_name: string, created_on: string, duration: ?int, expired_on: string, revoked_on: ?string, last_used_on: ?string, last_notified_on: ?string, created_on_format: string, expired_on_format: string, last_used_on_since: string, is_expired: bool, expiration: string, expired_on_since: string, revoked_on_since: ?string, revoked_on_message: ?string}
     */
    public function toArray(): array
    {
        return [
            'auth_key' => $this->authKey,
            'apikey_secret' => $this->apikeySecret,
            'apikey_name' => $this->apikeyName,
            'created_on' => $this->createdOn,
            'duration' => $this->duration,
            'expired_on' => $this->expiredOn,
            'revoked_on' => $this->revokedOn,
            'last_used_on' => $this->lastUsedOn,
            'last_notified_on' => $this->lastNotifiedOn,
            'created_on_format' => $this->createdOnFormat,
            'expired_on_format' => $this->expiredOnFormat,
            'last_used_on_since' => $this->lastUsedOnSince,
            'is_expired' => $this->isExpired,
            'expiration' => $this->expiration,
            'expired_on_since' => $this->expiredOnSince,
            'revoked_on_since' => $this->revokedOnSince,
            'revoked_on_message' => $this->revokedOnMessage,
        ];
    }
}
