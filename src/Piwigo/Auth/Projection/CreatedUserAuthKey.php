<?php

declare(strict_types=1);

namespace Piwigo\Auth\Projection;

/**
 * {@see \Piwigo\Auth\AuthService::createUserAuthKey()}'s own fixed result
 * shape -- the insert row plus the generated `authKeyId`.
 */
final readonly class CreatedUserAuthKey
{
    public function __construct(
        public string $authKey,
        public int $userId,
        public string $createdOn,
        public int $duration,
        public string $expiredOn,
        public string $keyType,
        public string $authKeyId,
    ) {}
}
