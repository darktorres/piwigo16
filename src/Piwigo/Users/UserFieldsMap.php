<?php

declare(strict_types=1);

namespace Piwigo\Users;

/**
 * Typed wrapper for `Config::userFields()` — the four DB column names
 * Piwigo uses to read the `users` (and joined `user_infos`) table.
 *
 * Replaces the `array<string,string>` keyed by literal `'id' /
 * 'username' / 'password' / 'email'` that was read at 91+ call sites.
 *
 * The values are *DB column names* (configurable so the admin can map
 * to a non-default schema), not user data — the default mapping is
 * `id => id, username => username, password => password, email =>
 * mail_address`.
 */
final readonly class UserFieldsMap
{
    public function __construct(
        public string $id,
        public string $username,
        public string $password,
        public string $email,
    ) {
    }

    /**
     * Build from a raw `Config::src()['user_fields']` value. Missing or
     * non-string entries fall back to the historical defaults.
     *
     * @param array<int|string, mixed> $raw
     */
    public static function fromArray(array $raw): self
    {
        return new self(
            id:       isset($raw['id'])       && is_scalar($raw['id']) ? (string) $raw['id'] : 'id',
            username: isset($raw['username']) && is_scalar($raw['username']) ? (string) $raw['username'] : 'username',
            password: isset($raw['password']) && is_scalar($raw['password']) ? (string) $raw['password'] : 'password',
            email:    isset($raw['email'])    && is_scalar($raw['email']) ? (string) $raw['email'] : 'mail_address',
        );
    }
}
