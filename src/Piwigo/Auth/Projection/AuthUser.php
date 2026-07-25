<?php

declare(strict_types=1);

namespace Piwigo\Auth\Projection;

/**
 * Typed row shape for
 * {@see \Piwigo\Auth\AuthRepository::findByUsernameOrEmail()} (P17-23
 * Stage 1b, Auth domain) -- a `users`/`user_infos` join, keyed by
 * runtime-configurable column names (see `CurrentConfig::userFields()`).
 * `fromRow()` centralises the narrowing
 * {@see \Piwigo\Auth\AuthService::pwgLogin()} used to duplicate for itself
 * across several `$user_found['x']` accesses in this security-sensitive,
 * timing-attack-mitigated login path.
 *
 * `id` stays `string`, not `int` -- {@see \Piwigo\Auth\AuthService::pwgLogin()}'s
 * own constant-time comparison against a fake user's `id: null` relies on
 * being able to fall back to that `null` via `?->id`, and every real
 * consumer already re-parses it with `is_numeric()`/`(int)` on use.
 */
final readonly class AuthUser
{
    public function __construct(
        public string $id,
        public string $username,
        public string $email,
        public string $password,
        public string $status,
    ) {}

    /**
     * @param array<string, mixed> $row a {@see \Piwigo\Auth\AuthRepository::findByUsernameOrEmail()} row
     */
    public static function fromRow(array $row): self
    {
        return new self(
            id: is_scalar($row['id'] ?? null) ? (string) $row['id'] : '',
            username: is_string($row['username'] ?? null) ? $row['username'] : '',
            email: is_string($row['email'] ?? null) ? $row['email'] : '',
            password: is_string($row['password'] ?? null) ? $row['password'] : '',
            // the user may not exist in user_infos, so default to 'normal'
            // -- matches the original's `$user['status'] ??= 'normal';`
            status: is_string($row['status'] ?? null) ? $row['status'] : 'normal',
        );
    }

    /**
     * @return array{id: string, username: string, email: string, password: string, status: string}
     */
    public function toArray(): array
    {
        return [
            'id' => $this->id,
            'username' => $this->username,
            'email' => $this->email,
            'password' => $this->password,
            'status' => $this->status,
        ];
    }
}
