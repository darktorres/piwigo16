<?php

declare(strict_types=1);

namespace Piwigo\Auth\Projection;

use Piwigo\Common\ValueObject\UserId;
use Piwigo\Users\UserStatus;

/**
 * Typed row shape for
 * {@see \Piwigo\Auth\AuthRepository::findByUsernameOrEmail()} (P17-23
 * Stage 1b, Auth domain) -- a `users`/`user_infos` join.
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
     * @param array<array-key, mixed> $row a {@see \Piwigo\Auth\AuthRepository::findByUsernameOrEmail()}
     *   row -- Doctrine's own `getOneOrNullResult()` return type is bare
     *   `mixed`, so this can't be declared `array<string, mixed>` the way
     *   DBAL's own `fetchAssociative()` could; every real row still has
     *   string keys (this DQL query's own explicit `AS` aliases), just not
     *   statically provable from Doctrine's return type.
     */
    public static function fromRow(array $row): self
    {
        return new self(
            // `id` is `users.id`, now a custom-Typed UserId under DQL array
            // hydration (this domain's own gotcha #4 territory) -- unwrap
            // it explicitly rather than letting the generic is_scalar()
            // check below silently treat a VO as absent.
            id: match (true) {
                ($row['id'] ?? null) instanceof UserId => (string) $row['id']->value,
                is_scalar($row['id'] ?? null) => (string) $row['id'],
                default => '',
            },
            username: is_string($row['username'] ?? null) ? $row['username'] : '',
            email: is_string($row['email'] ?? null) ? $row['email'] : '',
            password: is_string($row['password'] ?? null) ? $row['password'] : '',
            // the user may not exist in user_infos, so default to 'normal'
            // -- matches the original's `$user['status'] ??= 'normal';`.
            // Phase 5 Item 21: `ui.status` (UserInfoEntity::$status) is now
            // enumType-mapped -- DQL array hydration returns a real
            // UserStatus instance for it, not a raw string (confirmed live;
            // AbstractHydrator::buildEnum() runs for any scalar select of
            // an enumType-mapped field, not just full-entity selects).
            status: ($row['status'] ?? null) instanceof UserStatus ? $row['status']->value : 'normal',
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
