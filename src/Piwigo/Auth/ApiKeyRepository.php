<?php

declare(strict_types=1);

namespace Piwigo\Auth;

use DateTimeInterface;
use Doctrine\ORM\EntityManagerInterface;
use Piwigo\Auth\Projection\ApiKey;
use Piwigo\Common\ValueObject\SqlDateTime;
use Piwigo\Common\ValueObject\UserId;

/**
 * Persistence layer for the personal API key ("pkid-...") lifecycle --
 * the `user_auth_keys` table's `api_key`-type rows, a distinct concern
 * from Piwigo\Auth\AuthRepository's own `auth_key`-type rows (same
 * physical table, different lifecycle/owner). Owns no entity itself
 * ({@see UserAuthKeyEntity} has no `repositoryClass`, shared with
 * AuthRepository) -- holds EntityManagerInterface directly rather than
 * being resolved via getRepository(), same shape as Auth\PasswordRepository/
 * Permission\PermissionRepository holding a bare Connection for tables
 * they don't own either.
 */
final readonly class ApiKeyRepository
{
    public function __construct(
        private EntityManagerInterface $em,
    ) {}

    /**
     * @param array{
     *   auth_key: string, apikey_secret: string, apikey_name: string,
     *   user_id: int, created_on: string, duration: ?int, expired_on: string,
     *   key_type: string,
     * } $key
     */
    public function insert(array $key): void
    {
        $entity = new UserAuthKeyEntity(
            authKey: $key['auth_key'],
            apikeySecret: $key['apikey_secret'],
            userId: UserId::from($key['user_id']),
            createdOn: SqlDateTime::from($key['created_on']),
            duration: $key['duration'],
            expiredOn: SqlDateTime::from($key['expired_on']),
            apikeyName: $key['apikey_name'],
            keyType: $key['key_type'],
        );

        $this->em->persist($entity);
        $this->em->flush();
    }

    public function countByAuthKeyAndUser(string $authKey, int $userId): int
    {
        $value = $this->em->createQueryBuilder()
            ->select('COUNT(uak.authKeyId)')
            ->from(UserAuthKeyEntity::class, 'uak')
            ->where('uak.authKey = :authKey')
            ->andWhere('uak.userId = :userId')
            ->setParameter('authKey', $authKey)
            ->setParameter('userId', UserId::from($userId))
            ->getQuery()
            ->getSingleScalarResult();

        return is_numeric($value) ? (int) $value : 0;
    }

    public function revoke(string $authKey, int $userId, DateTimeInterface $revokedOn): void
    {
        $this->em->createQueryBuilder()
            ->update(UserAuthKeyEntity::class, 'uak')
            ->set('uak.revokedOn', ':revokedOn')
            ->where('uak.authKey = :authKey')
            ->andWhere('uak.userId = :userId')
            ->setParameter('revokedOn', $revokedOn->format('Y-m-d H:i:s'))
            ->setParameter('authKey', $authKey)
            ->setParameter('userId', UserId::from($userId))
            ->getQuery()
            ->execute();

        $this->em->clear();
    }

    public function updateName(string $authKey, int $userId, ?string $apiName): void
    {
        $this->em->createQueryBuilder()
            ->update(UserAuthKeyEntity::class, 'uak')
            ->set('uak.apikeyName', ':apikeyName')
            ->where('uak.authKey = :authKey')
            ->andWhere('uak.userId = :userId')
            ->setParameter('apikeyName', $apiName)
            ->setParameter('authKey', $authKey)
            ->setParameter('userId', UserId::from($userId))
            ->getQuery()
            ->execute();

        $this->em->clear();
    }

    /**
     * Records that the auth key's near-expiration notification email was
     * just sent.
     */
    public function updateLastNotifiedOn(string $authKey, int $userId, string $lastNotifiedOn): void
    {
        $this->em->createQueryBuilder()
            ->update(UserAuthKeyEntity::class, 'uak')
            ->set('uak.lastNotifiedOn', ':lastNotifiedOn')
            ->where('uak.authKey = :authKey')
            ->andWhere('uak.userId = :userId')
            ->setParameter('lastNotifiedOn', $lastNotifiedOn)
            ->setParameter('authKey', $authKey)
            ->setParameter('userId', UserId::from($userId))
            ->getQuery()
            ->execute();

        $this->em->clear();
    }

    /**
     * @return list<ApiKey>
     */
    public function findByUser(int $userId): array
    {
        $entities = $this->em->createQueryBuilder()
            ->select('uak')
            ->from(UserAuthKeyEntity::class, 'uak')
            ->where('uak.userId = :userId')
            ->andWhere("uak.keyType = 'api_key'")
            ->setParameter('userId', UserId::from($userId))
            ->getQuery()
            ->getResult();

        return array_map(ApiKey::fromEntity(...), $entities);
    }
}
