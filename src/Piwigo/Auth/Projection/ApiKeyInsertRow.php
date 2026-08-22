<?php

declare(strict_types=1);

namespace Piwigo\Auth\Projection;

/**
 * {@see \Piwigo\Auth\ApiKeyRepository::insert()}'s own insert-row shape --
 * the one real caller ({@see \Piwigo\Auth\ApiKeyService::create()}) always
 * supplies a real `duration`, so unlike
 * {@see \Piwigo\Auth\UserAuthKeyEntity::$duration} (nullable, since
 * `Auth\AuthRepository`'s own `auth_key`-type rows share the column but
 * don't always set it) this stays non-nullable.
 */
final readonly class ApiKeyInsertRow
{
    public function __construct(
        public string $authKey,
        public string $apikeySecret,
        public string $apikeyName,
        public int $userId,
        public string $createdOn,
        public int $duration,
        public string $expiredOn,
        public string $keyType,
    ) {}
}
