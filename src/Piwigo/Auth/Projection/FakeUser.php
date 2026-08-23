<?php

declare(strict_types=1);

namespace Piwigo\Auth\Projection;

/**
 * {@see \Piwigo\Auth\AuthService::generateFakeUser()}'s own return shape --
 * `id` is always `null` (it stands in for {@see AuthUser::$id} in
 * {@see \Piwigo\Auth\AuthService::pwgLogin()}'s constant-time comparison,
 * which needs a real fallback value, not a missing key).
 */
final readonly class FakeUser
{
    public function __construct(
        public null $id,
        public string $password,
    ) {}
}
