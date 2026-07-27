<?php

declare(strict_types=1);

namespace Piwigo\Auth;

use Doctrine\ORM\EntityManagerInterface;
use Piwigo\Auth\Projection\AuthKeyDetails;
use Piwigo\Auth\Projection\AuthUser;
use Piwigo\Common\ValueObject\UserId;
use Piwigo\Db\Tables;
use Piwigo\Users\UserInfoEntity;

/**
 * Persistence layer for the login/logout domain: username+password lookup
 * for the auto-login key, the language-cookie-sync write inside logUser(),
 * username-or-email lookup, and the auth_key ("remember me"/persistent
 * login) lifecycle -- distinct from Piwigo\Auth\ApiKeyRepository, which
 * covers the same physical `user_auth_keys` table's `api_key`-type rows
 * (personal API keys), a different lifecycle/concern.
 *
 * Owns no entity itself ({@see UserAuthKeyEntity} has no `repositoryClass`,
 * shared with ApiKeyRepository) -- holds EntityManagerInterface directly.
 * `users` is never ORM-mapped (Users\UserRepository's own docblock);
 * every method touching it takes caller-supplied column names
 * (\Piwigo\Config\CurrentConfig::userFields()) and stays plain DBAL via
 * $this->em->getConnection(). `user_infos` IS ORM-mapped
 * ({@see UserInfoEntity}, owned by Users\UserRepository) -- writes here
 * go through it (find+set+flush) rather than raw DBAL, since a raw write
 * would leave any UserInfoEntity already in this EntityManager's identity
 * map stale for the rest of the request (same reasoning as every other
 * repository's own identity-map-staleness fix this migration).
 *
 * Every `NOW()`/`ADDDATE(NOW(), ...)` computation the original raw SQL did
 * in MySQL is done in PHP against \Piwigo\Core\Env::now() instead --
 * matches Piwigo\Session\SessionRepository::write()/
 * Piwigo\Comment\CommentRepository::countRecentComments()'s own established
 * reasoning: SQL's NOW() is invisible to Env::now()'s PIWIGO_TEST_NOW
 * freeze, so a fixture-driven test comparing "now" against a fixture-dated
 * column would silently use two different clocks.
 */
final readonly class AuthRepository
{
    private const DATETIME_FORMAT = 'Y-m-d H:i:s';

    public function __construct(
        private EntityManagerInterface $em,
    ) {}

    /**
     * @return array{username: string, password: string}|null
     */
    public function findUsernameAndPassword(
        int|string $userId,
        string $idColumn,
        string $usernameColumn,
        string $passwordColumn
    ): ?array {
        $row = $this->em->getConnection()
            ->createQueryBuilder()
            ->select($usernameColumn . ' AS username', $passwordColumn . ' AS password')
            ->from(Tables::users())
            ->where($idColumn . ' = :id')
            ->setParameter('id', $userId)
            ->executeQuery()
            ->fetchAssociative();

        if ($row === false) {
            return null;
        }

        return [
            'username' => is_string($row['username']) ? $row['username'] : '',
            'password' => is_string($row['password']) ? $row['password'] : '',
        ];
    }

    public function updateLanguage(UserId $userId, string $language): void
    {
        $entity = $this->em->find(UserInfoEntity::class, $userId);
        if ($entity === null) {
            return;
        }

        $entity->language = $language;
        $this->em->flush();
    }

    /**
     * Finds a user by username first, falling back to email -- matches the
     * original's own two-query try-username-then-email order (needed
     * because $usernameColumn/$emailColumn are runtime-configurable via
     * \Piwigo\Config\CurrentConfig::userFields(), not always literally 'username'/'email').
     */
    public function findByUsernameOrEmail(
        string $usernameOrEmail,
        string $idColumn,
        string $usernameColumn,
        string $emailColumn,
        string $passwordColumn
    ): ?AuthUser {
        $qb = $this->em->getConnection()
            ->createQueryBuilder()
            ->select(
                'u.' . $idColumn . ' AS id',
                'u.' . $usernameColumn . ' AS username',
                'u.' . $emailColumn . ' AS email',
                'u.' . $passwordColumn . ' AS password',
                'i.status AS status',
            )
            ->from(Tables::users(), 'u')
            ->leftJoin('u', Tables::userInfos(), 'i', 'u.' . $idColumn . ' = i.user_id')
            ->setParameter('value', $usernameOrEmail);

        $row = (clone $qb)->where('u.' . $usernameColumn . ' = :value')
            ->executeQuery()
            ->fetchAssociative();

        if ($row === false) {
            $row = $qb->where('u.' . $emailColumn . ' = :value')
                ->executeQuery()
                ->fetchAssociative();
        }

        return $row === false ? null : AuthUser::fromRow($row);
    }

    /**
     * A single auth_key row (JOINed with its owning user's username/email),
     * or null when no such key exists. Every value that was a SQL-computed
     * pseudo-column in the original (NOW(), DATEDIFF, SUBDATE) is left to
     * the caller to compute from \Piwigo\Core\Env::now() instead.
     */
    public function findAuthKeyDetails(string $authKey, string $idColumn, string $usernameColumn, string $emailColumn): ?AuthKeyDetails
    {
        $row = $this->em->getConnection()
            ->createQueryBuilder()
            ->select(
                'uak.auth_key_id',
                'uak.user_id',
                'uak.auth_key',
                'uak.expired_on',
                'uak.revoked_on',
                'uak.last_used_on',
                'uak.last_notified_on',
                'uak.apikey_secret',
                'ui.status',
                'u.' . $usernameColumn . ' AS username',
                'u.' . $emailColumn . ' AS email',
            )
            ->from(Tables::userAuthKeys(), 'uak')
            ->join('uak', Tables::userInfos(), 'ui', 'uak.user_id = ui.user_id')
            ->join('uak', Tables::users(), 'u', 'u.' . $idColumn . ' = ui.user_id')
            ->where('uak.auth_key = :authKey')
            ->setParameter('authKey', $authKey)
            ->executeQuery()
            ->fetchAssociative();

        return $row === false ? null : AuthKeyDetails::fromRow($row);
    }

    public function touchAuthKeyLastUsed(int|string $userId, string $authKey, \DateTimeInterface $lastUsedOn): void
    {
        $this->em->createQueryBuilder()
            ->update(UserAuthKeyEntity::class, 'uak')
            ->set('uak.lastUsedOn', ':lastUsedOn')
            ->where('uak.userId = :userId')
            ->andWhere('uak.authKey = :authKey')
            ->setParameter('lastUsedOn', $lastUsedOn->format(self::DATETIME_FORMAT))
            ->setParameter('userId', $userId)
            ->setParameter('authKey', $authKey)
            ->getQuery()
            ->execute();

        $this->em->clear();
    }

    public function findUserStatus(UserId $userId): ?string
    {
        return $this->em->find(UserInfoEntity::class, $userId)?->status;
    }

    public function authKeyCandidateExists(string $candidate): bool
    {
        $value = $this->em->createQueryBuilder()
            ->select('COUNT(uak.authKeyId)')
            ->from(UserAuthKeyEntity::class, 'uak')
            ->where('uak.authKey = :candidate')
            ->setParameter('candidate', $candidate)
            ->getQuery()
            ->getSingleScalarResult();

        return is_numeric($value) && (int) $value > 0;
    }

    /**
     * @param array{
     *   auth_key: string, user_id: int, created_on: string, duration: int,
     *   expired_on: string, key_type: string,
     * } $key
     */
    public function insertAuthKey(array $key): string
    {
        $entity = new UserAuthKeyEntity(
            authKey: $key['auth_key'],
            apikeySecret: null,
            userId: $key['user_id'],
            createdOn: $key['created_on'],
            duration: $key['duration'],
            expiredOn: $key['expired_on'],
            apikeyName: null,
            keyType: $key['key_type'],
        );

        $this->em->persist($entity);
        $this->em->flush();

        assert($entity->authKeyId !== null);

        return (string) $entity->authKeyId;
    }

    public function deactivateAuthKeys(int $userId, \DateTimeInterface $now): void
    {
        $nowStr = $now->format(self::DATETIME_FORMAT);

        $this->em->createQueryBuilder()
            ->update(UserAuthKeyEntity::class, 'uak')
            ->set('uak.expiredOn', ':now')
            ->where('uak.userId = :userId')
            ->andWhere('uak.expiredOn > :nowCompare')
            ->andWhere("uak.keyType = 'auth_key'")
            ->setParameter('now', $nowStr)
            ->setParameter('userId', $userId)
            ->setParameter('nowCompare', $nowStr)
            ->getQuery()
            ->execute();

        $this->em->clear();
    }

    public function clearActivationKey(UserId $userId): void
    {
        $entity = $this->em->find(UserInfoEntity::class, $userId);
        if ($entity === null) {
            return;
        }

        $entity->activationKey = null;
        $entity->activationKeyExpire = null;
        $this->em->flush();
    }

    public function setActivationKey(UserId $userId, string $hash, \DateTimeInterface $expire): void
    {
        $entity = $this->em->find(UserInfoEntity::class, $userId);
        if ($entity === null) {
            return;
        }

        $entity->activationKey = $hash;
        $entity->activationKeyExpire = $expire->format(self::DATETIME_FORMAT);
        $this->em->flush();
    }

    /**
     * Most recent 'date time' visit string from the history table, or null
     * when the user has no history rows. The original looped over every
     * row returned by its query, but the query itself was already
     * `ORDER BY id DESC LIMIT 1` -- so at most one iteration ever ran; a
     * single-row fetch is behaviorally identical.
     */
    public function findLastVisitFromHistory(int $userId): ?string
    {
        $row = $this->em->getConnection()
            ->createQueryBuilder()
            ->select('date', 'time')
            ->from(Tables::history())
            ->where('user_id = :userId')
            ->orderBy('id', 'DESC')
            ->setMaxResults(1)
            ->setParameter('userId', $userId)
            ->executeQuery()
            ->fetchAssociative();

        if ($row === false) {
            return null;
        }

        $date = is_scalar($row['date']) ? (string) $row['date'] : '';
        $time = is_scalar($row['time']) ? (string) $row['time'] : '';

        return $date . ' ' . $time;
    }

    /**
     * `lastmodified = lastmodified` is a deliberate self-assignment in the
     * original -- not a no-op leftover, it's how the original avoids
     * bumping an ON UPDATE CURRENT_TIMESTAMP-style column while still
     * writing the other two columns; a raw SQL fragment (not a bound
     * parameter) is required to reference the column itself rather than a
     * value, which a mapped entity property write can't express (a plain
     * property assignment either changes lastmodified's own value or
     * leaves it out of the UPDATE entirely, letting the schema's own ON
     * UPDATE trigger fire regardless) -- stays raw DBAL, clearing the
     * identity map afterward since this bypasses the ORM for a table
     * {@see UserInfoEntity} maps. `last_visit_from_history` is a real
     * tinyint column now (User domain Stage 1a) -- the literal unquoted
     * `1` below is the numeric equivalent of the old `'true'` string
     * literal, not a bound value, same reasoning as `lastmodified`.
     */
    public function saveLastVisitFromHistory(int $userId, ?string $lastVisit): void
    {
        $qb = $this->em->getConnection()
            ->createQueryBuilder()
            ->update(Tables::userInfos())
            ->set('last_visit_from_history', '1')
            ->set('lastmodified', 'lastmodified')
            ->where('user_id = :userId')
            ->setParameter('userId', $userId);

        if ($lastVisit === null) {
            $qb->set('last_visit', 'NULL');
        } else {
            $qb->set('last_visit', ':lastVisit')
                ->setParameter('lastVisit', $lastVisit);
        }

        $qb->executeStatement();

        $this->em->clear();
    }

    public function countLoginActivity(int $userId): int
    {
        $value = $this->em->getConnection()
            ->createQueryBuilder()
            ->select('COUNT(*)')
            ->from(Tables::activity())
            ->where("action = 'login'")
            ->andWhere('performed_by = :userId')
            ->setParameter('userId', $userId)
            ->executeQuery()
            ->fetchOne();

        return is_numeric($value) ? (int) $value : 0;
    }
}
