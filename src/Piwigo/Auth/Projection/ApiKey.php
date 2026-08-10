<?php

declare(strict_types=1);

namespace Piwigo\Auth\Projection;

use InvalidArgumentException;
use Piwigo\Auth\UserAuthKeyEntity;
use Piwigo\Common\ValueObject\SqlDateTime;
use Piwigo\Common\ValueObject\UserId;

/**
 * Typed row shape for `user_auth_keys`. `fromRow()` centralises
 * the `is_string($row['x']) ? ... : default` narrowing
 * {@see \Piwigo\Auth\ApiKeyRepository}'s own
 * caller ({@see \Piwigo\Auth\ApiKeyService::get()}) used to do inline,
 * same shape as {@see \Piwigo\Category\Projection\Category}.
 *
 * `createdOn`/`expiredOn` stay non-nullable `string` -- both are genuine
 * `NOT NULL` columns (unlike most other domains' own now-nullable
 * timestamp columns), so `fromRow()`'s own fallback default is a
 * defensive floor, not an expected real value. `UserAuthKeyEntity`'s own
 * `$createdOn`/`$expiredOn` are `SqlDateTime`-typed --
 * `fromEntity()` unwraps `->value`, `fromRow()` accepts either a real
 * `SqlDateTime` instance (a `getArrayResult()`-hydrated row) or a raw
 * string.
 *
 * `userId` is `UserId`, not `?UserId` -- `user_auth_keys.user_id` is
 * `NOT NULL` with a real `fk_user_auth_keys_user_id` FOREIGN KEY onto
 * `users.id` (`ON DELETE CASCADE`), so a real fetched row can't
 * carry a missing/invalid value, same reasoning as
 * {@see \Piwigo\Rate\Projection\Rate}'s `userId`/`elementId`.
 */
final readonly class ApiKey
{
    public function __construct(
        public int $authKeyId,
        public string $authKey,
        public ?string $apikeySecret,
        public ?string $apikeyName,
        public UserId $userId,
        public string $createdOn,
        public ?int $duration,
        public string $expiredOn,
        public ?string $keyType,
        public ?string $revokedOn,
        public ?string $lastUsedOn,
        public ?string $lastNotifiedOn,
    ) {}

    public static function fromEntity(UserAuthKeyEntity $entity): self
    {
        return new self(
            authKeyId: $entity->authKeyId ?? 0,
            authKey: $entity->authKey,
            apikeySecret: $entity->apikeySecret,
            apikeyName: $entity->apikeyName,
            userId: $entity->userId,
            createdOn: $entity->createdOn->value,
            duration: $entity->duration,
            expiredOn: $entity->expiredOn->value,
            keyType: $entity->keyType,
            revokedOn: $entity->revokedOn,
            lastUsedOn: $entity->lastUsedOn,
            lastNotifiedOn: $entity->lastNotifiedOn,
        );
    }

    /**
     * @param array<string, mixed> $row a `SELECT *` (or equivalent) row from `user_auth_keys`
     */
    public static function fromRow(array $row): self
    {
        $userIdValue = $row['user_id'] ?? null;
        $userId = $userIdValue instanceof UserId ? $userIdValue : UserId::tryFrom($userIdValue);
        if ($userId === null) {
            throw new InvalidArgumentException(sprintf('Expected a positive user id, got %s', get_debug_type($userIdValue)));
        }

        return new self(
            authKeyId: is_numeric($row['auth_key_id'] ?? null) ? (int) $row['auth_key_id'] : 0,
            authKey: is_string($row['auth_key'] ?? null) ? $row['auth_key'] : '',
            apikeySecret: is_string($row['apikey_secret'] ?? null) ? $row['apikey_secret'] : null,
            apikeyName: is_string($row['apikey_name'] ?? null) ? $row['apikey_name'] : null,
            userId: $userId,
            createdOn: ($row['created_on'] ?? null) instanceof SqlDateTime
                ? $row['created_on']->value
                : (is_string($row['created_on'] ?? null) ? $row['created_on'] : ''),
            duration: is_numeric($row['duration'] ?? null) ? (int) $row['duration'] : null,
            expiredOn: ($row['expired_on'] ?? null) instanceof SqlDateTime
                ? $row['expired_on']->value
                : (is_string($row['expired_on'] ?? null) ? $row['expired_on'] : ''),
            keyType: is_string($row['key_type'] ?? null) ? $row['key_type'] : null,
            revokedOn: is_string($row['revoked_on'] ?? null) ? $row['revoked_on'] : null,
            lastUsedOn: is_string($row['last_used_on'] ?? null) ? $row['last_used_on'] : null,
            lastNotifiedOn: is_string($row['last_notified_on'] ?? null) ? $row['last_notified_on'] : null,
        );
    }

    /**
     * @return array{auth_key_id: int, auth_key: string, apikey_secret: ?string,
     *   apikey_name: ?string, user_id: int, created_on: string, duration: ?int,
     *   expired_on: string, key_type: ?string, revoked_on: ?string,
     *   last_used_on: ?string, last_notified_on: ?string}
     */
    public function toArray(): array
    {
        return [
            'auth_key_id' => $this->authKeyId,
            'auth_key' => $this->authKey,
            'apikey_secret' => $this->apikeySecret,
            'apikey_name' => $this->apikeyName,
            'user_id' => $this->userId->value,
            'created_on' => $this->createdOn,
            'duration' => $this->duration,
            'expired_on' => $this->expiredOn,
            'key_type' => $this->keyType,
            'revoked_on' => $this->revokedOn,
            'last_used_on' => $this->lastUsedOn,
            'last_notified_on' => $this->lastNotifiedOn,
        ];
    }
}
