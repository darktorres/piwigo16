<?php

declare(strict_types=1);

namespace Piwigo\Auth\Projection;

use Piwigo\Common\ValueObject\AuthKeyId;
use Piwigo\Common\ValueObject\Email;
use Piwigo\Common\ValueObject\SqlDateTime;
use Piwigo\Common\ValueObject\UserId;
use Piwigo\Common\ValueObject\Username;
use Piwigo\Users\UserStatus;

/**
 * Typed row shape for
 * {@see \Piwigo\Auth\AuthRepository::findAuthKeyDetails()} -- a
 * `user_auth_keys`/`user_infos`/`users` join. `fromRow()` centralises the
 * narrowing that {@see \Piwigo\Auth\AuthService::authKeyLogin()} would
 * otherwise have to repeat across many `$key['x']` accesses in this
 * security-sensitive auth_key/api_key login path.
 *
 * `userId`/`authKeyId` stay `string`, matching every real consumer's own
 * `is_numeric($x)`/`(int) $x` on-use narrowing (e.g. `logUser()`'s own
 * numeric-string param contract) -- not upgraded to `int` here, since doing
 * so would just move that same narrowing to a different call site instead
 * of removing it.
 *
 * UserAuthKeyEntity::$userId/$authKeyId are UserId-/AuthKeyId-typed --
 * `uak.userId AS user_id`/`uak.authKeyId AS auth_key_id` array-hydrate as
 * real VO instances, not scalars, so `fromRow()`'s own narrowing checks
 * `instanceof UserId`/`instanceof AuthKeyId` rather than `is_scalar()`
 * (which would always be false and silently break every real login
 * through this security-sensitive path). `UserAuthKeyEntity::$expiredOn`
 * is `SqlDateTime`-typed too -- `expiredOn` narrows the same way.
 */
final readonly class AuthKeyDetails
{
    public function __construct(
        public string $authKeyId,
        public string $userId,
        public string $authKey,
        public string $expiredOn,
        public ?string $revokedOn,
        public ?string $lastUsedOn,
        public ?string $lastNotifiedOn,
        public ?string $apikeySecret,
        public string $status,
        public string $username,
        public string $email,
    ) {}

    /**
     * @param array<array-key, mixed> $row a {@see \Piwigo\Auth\AuthRepository::findAuthKeyDetails()}
     *   row -- Doctrine's own `getOneOrNullResult()` return type is bare
     *   `mixed`, so this can't be declared `array<string, mixed>` the way
     *   DBAL's own `fetchAssociative()` could; every real row still has
     *   string keys (this DQL query's own explicit `AS` aliases), just not
     *   statically provable from Doctrine's return type.
     */
    public static function fromRow(array $row): self
    {
        return new self(
            authKeyId: ($row['auth_key_id'] ?? null) instanceof AuthKeyId ? (string) $row['auth_key_id']->value : '',
            userId: ($row['user_id'] ?? null) instanceof UserId ? (string) $row['user_id']->value : '',
            authKey: is_scalar($row['auth_key'] ?? null) ? (string) $row['auth_key'] : '',
            expiredOn: ($row['expired_on'] ?? null) instanceof SqlDateTime
                ? $row['expired_on']->value
                : (is_scalar($row['expired_on'] ?? null) ? (string) $row['expired_on'] : ''),
            revokedOn: is_scalar($row['revoked_on'] ?? null) ? (string) $row['revoked_on'] : null,
            lastUsedOn: is_scalar($row['last_used_on'] ?? null) ? (string) $row['last_used_on'] : null,
            lastNotifiedOn: is_scalar($row['last_notified_on'] ?? null) ? (string) $row['last_notified_on'] : null,
            apikeySecret: is_scalar($row['apikey_secret'] ?? null) ? (string) $row['apikey_secret'] : null,
            // `ui.status` array-hydrates as a UserStatus instance, not a
            // raw string -- see \Piwigo\Auth\Projection\AuthUser::fromRow().
            status: ($row['status'] ?? null) instanceof UserStatus ? $row['status']->value : '',
            username: ($row['username'] ?? null) instanceof Username ? $row['username']->value : '',
            email: ($row['email'] ?? null) instanceof Email ? $row['email']->value : '',
        );
    }

    /**
     * @return array{auth_key_id: string, user_id: string, auth_key: string,
     *   expired_on: string, revoked_on: ?string, last_used_on: ?string,
     *   last_notified_on: ?string, apikey_secret: ?string, status: string,
     *   username: string, email: string}
     */
    public function toArray(): array
    {
        return [
            'auth_key_id' => $this->authKeyId,
            'user_id' => $this->userId,
            'auth_key' => $this->authKey,
            'expired_on' => $this->expiredOn,
            'revoked_on' => $this->revokedOn,
            'last_used_on' => $this->lastUsedOn,
            'last_notified_on' => $this->lastNotifiedOn,
            'apikey_secret' => $this->apikeySecret,
            'status' => $this->status,
            'username' => $this->username,
            'email' => $this->email,
        ];
    }
}
