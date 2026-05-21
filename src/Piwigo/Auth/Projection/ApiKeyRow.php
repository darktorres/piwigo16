<?php

declare(strict_types=1);

namespace Piwigo\Auth\Projection;

/** A user_auth_keys row with key_type = 'api_key', as returned by AuthKeyRepository::findApiKeysByUserId(). */
final readonly class ApiKeyRow
{
    public function __construct(
        public int $authKeyId,
        public string $authKey,
        public ?string $apikeySecret,
        public int $userId,
        public string $createdOn,
        public ?int $duration,
        public string $expiredOn,
        public ?string $apikeyName,
        public ?string $keyType,
        public ?string $revokedOn,
        public ?string $lastUsedOn,
        public ?string $lastNotifiedOn,
    ) {
    }

    /** @param array<string, mixed> $row */
    public static function fromRow(array $row): self
    {
        return new self(
            authKeyId:      is_numeric($row['auth_key_id'] ?? null) ? (int) $row['auth_key_id'] : 0,
            authKey:        is_string($row['auth_key'] ?? null) ? $row['auth_key'] : '',
            apikeySecret:   is_string($row['apikey_secret'] ?? null) ? $row['apikey_secret'] : null,
            userId:         is_numeric($row['user_id'] ?? null) ? (int) $row['user_id'] : 0,
            createdOn:      is_string($row['created_on'] ?? null) ? $row['created_on'] : '',
            duration:       is_numeric($row['duration'] ?? null) ? (int) $row['duration'] : null,
            expiredOn:      is_string($row['expired_on'] ?? null) ? $row['expired_on'] : '',
            apikeyName:     is_string($row['apikey_name'] ?? null) ? $row['apikey_name'] : null,
            keyType:        is_string($row['key_type'] ?? null) ? $row['key_type'] : null,
            revokedOn:      is_string($row['revoked_on'] ?? null) ? $row['revoked_on'] : null,
            lastUsedOn:     is_string($row['last_used_on'] ?? null) ? $row['last_used_on'] : null,
            lastNotifiedOn: is_string($row['last_notified_on'] ?? null) ? $row['last_notified_on'] : null,
        );
    }
}
