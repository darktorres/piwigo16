<?php

declare(strict_types=1);

namespace Piwigo\Auth\Projection;

/**
 * Typed row shape for
 * {@see \Piwigo\Auth\AuthRepository::findAuthKeyDetails()} (P17-23 Stage 1b,
 * Auth domain) -- a `user_auth_keys`/`user_infos`/`users` join.
 * `fromRow()` centralises the narrowing
 * {@see \Piwigo\Auth\AuthService::authKeyLogin()} used to duplicate for
 * itself across many `$key['x']` accesses in this security-sensitive
 * auth_key/api_key login path.
 *
 * `userId`/`authKeyId` stay `string`, matching every real consumer's own
 * `is_numeric($x)`/`(int) $x` on-use narrowing (e.g. `logUser()`'s own
 * numeric-string param contract) -- not upgraded to `int` here, since doing
 * so would just move that same narrowing to a different call site instead
 * of removing it.
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
            authKeyId: is_scalar($row['auth_key_id'] ?? null) ? (string) $row['auth_key_id'] : '',
            userId: is_scalar($row['user_id'] ?? null) ? (string) $row['user_id'] : '',
            authKey: is_scalar($row['auth_key'] ?? null) ? (string) $row['auth_key'] : '',
            expiredOn: is_scalar($row['expired_on'] ?? null) ? (string) $row['expired_on'] : '',
            revokedOn: is_scalar($row['revoked_on'] ?? null) ? (string) $row['revoked_on'] : null,
            lastUsedOn: is_scalar($row['last_used_on'] ?? null) ? (string) $row['last_used_on'] : null,
            lastNotifiedOn: is_scalar($row['last_notified_on'] ?? null) ? (string) $row['last_notified_on'] : null,
            apikeySecret: is_scalar($row['apikey_secret'] ?? null) ? (string) $row['apikey_secret'] : null,
            // Phase 5 Item 21: see \Piwigo\Auth\Projection\AuthUser::fromRow()'s
            // own comment -- `ui.status` array-hydrates as a UserStatus
            // instance now, not a raw string.
            status: ($row['status'] ?? null) instanceof \Piwigo\Users\UserStatus ? $row['status']->value : '',
            username: is_string($row['username'] ?? null) ? $row['username'] : '',
            email: is_string($row['email'] ?? null) ? $row['email'] : '',
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
