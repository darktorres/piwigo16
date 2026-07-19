<?php

declare(strict_types=1);

namespace Piwigo\Auth;

use Piwigo\Db\AbstractRepository;
use Piwigo\Db\Tables;

/**
 * Persistence layer for the personal API key ("pkid-...") lifecycle --
 * the `user_auth_keys` table's `api_key`-type rows, a distinct concern
 * from Piwigo\Auth\AuthRepository's own `auth_key`-type rows (same
 * physical table, different lifecycle/owner).
 */
final class ApiKeyRepository extends AbstractRepository
{
    /**
     * @param array{
     *   auth_key: string, apikey_secret: string, apikey_name: string,
     *   user_id: int, created_on: string, duration: ?int, expired_on: ?string,
     *   key_type: string,
     * } $key
     */
    public function insert(array $key): void
    {
        $this->conn->createQueryBuilder()
            ->insert(Tables::userAuthKeys())
            ->values([
                'auth_key' => ':authKey',
                'apikey_secret' => ':apikeySecret',
                'apikey_name' => ':apikeyName',
                'user_id' => ':userId',
                'created_on' => ':createdOn',
                'duration' => ':duration',
                'expired_on' => ':expiredOn',
                'key_type' => ':keyType',
            ])
            ->setParameter('authKey', $key['auth_key'])
            ->setParameter('apikeySecret', $key['apikey_secret'])
            ->setParameter('apikeyName', $key['apikey_name'])
            ->setParameter('userId', $key['user_id'])
            ->setParameter('createdOn', $key['created_on'])
            ->setParameter('duration', $key['duration'])
            ->setParameter('expiredOn', $key['expired_on'])
            ->setParameter('keyType', $key['key_type'])
            ->executeStatement();
    }

    public function countByAuthKeyAndUser(string $authKey, int $userId): int
    {
        $value = $this->conn->createQueryBuilder()
            ->select('COUNT(*)')
            ->from(Tables::userAuthKeys())
            ->where('auth_key = :authKey')
            ->andWhere('user_id = :userId')
            ->setParameter('authKey', $authKey)
            ->setParameter('userId', $userId)
            ->executeQuery()
            ->fetchOne();

        return is_numeric($value) ? (int) $value : 0;
    }

    public function revoke(string $authKey, int $userId, \DateTimeInterface $revokedOn): void
    {
        $this->conn->createQueryBuilder()
            ->update(Tables::userAuthKeys())
            ->set('revoked_on', ':revokedOn')
            ->where('auth_key = :authKey')
            ->andWhere('user_id = :userId')
            ->setParameter('revokedOn', $revokedOn->format('Y-m-d H:i:s'))
            ->setParameter('authKey', $authKey)
            ->setParameter('userId', $userId)
            ->executeStatement();
    }

    public function updateName(string $authKey, int $userId, ?string $apiName): void
    {
        $this->conn->createQueryBuilder()
            ->update(Tables::userAuthKeys())
            ->set('apikey_name', ':apikeyName')
            ->where('auth_key = :authKey')
            ->andWhere('user_id = :userId')
            ->setParameter('apikeyName', $apiName)
            ->setParameter('authKey', $authKey)
            ->setParameter('userId', $userId)
            ->executeStatement();
    }

    /**
     * Records that the auth key's near-expiration notification email was
     * just sent -- Legacy Coupling Retirement: DI+DBAL migration Phase 1d,
     * retargeted from RequestBootstrap::finalize()'s own former
     * `MysqliDb::singleUpdate()` call.
     */
    public function updateLastNotifiedOn(string $authKey, int $userId, string $lastNotifiedOn): void
    {
        $this->conn->createQueryBuilder()
            ->update(Tables::userAuthKeys())
            ->set('last_notified_on', ':lastNotifiedOn')
            ->where('auth_key = :authKey')
            ->andWhere('user_id = :userId')
            ->setParameter('lastNotifiedOn', $lastNotifiedOn)
            ->setParameter('authKey', $authKey)
            ->setParameter('userId', $userId)
            ->executeStatement();
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function findByUser(int $userId): array
    {
        /** @var list<array<string, mixed>> */
        return $this->conn->createQueryBuilder()
            ->select('*')
            ->from(Tables::userAuthKeys())
            ->where('user_id = :userId')
            ->andWhere("key_type = 'api_key'")
            ->setParameter('userId', $userId)
            ->executeQuery()
            ->fetchAllAssociative();
    }
}
