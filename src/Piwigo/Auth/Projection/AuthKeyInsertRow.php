<?php

declare(strict_types=1);

namespace Piwigo\Auth\Projection;

/**
 * {@see \Piwigo\Auth\AuthRepository::insertAuthKey()}'s own insert-row
 * shape -- everything {@see \Piwigo\Auth\AuthService::createUserAuthKey()}
 * writes before the row gets its generated `authKeyId` back (see
 * {@see CreatedUserAuthKey}).
 */
final readonly class AuthKeyInsertRow
{
    public function __construct(
        public string $authKey,
        public int $userId,
        public string $createdOn,
        public int $duration,
        public string $expiredOn,
        public string $keyType,
    ) {}
}
