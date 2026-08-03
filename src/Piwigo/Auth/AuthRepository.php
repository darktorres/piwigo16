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
 * `users` is now ORM-mapped too ({@see \Piwigo\Users\UserEntity}, Item 14
 * Sub-phase C4 -- the multi-auth column indirection every method touching
 * it used to take as caller-supplied column names is gone, confirmed
 * dead code). `user_infos` IS ORM-mapped
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
     * SQL-modernization audit, Item 14 Sub-phase C4: converted to real
     * DQL -- `users` is now mapped ({@see \Piwigo\Users\UserEntity}); the
     * multi-auth column indirection this used to take as `$idColumn`/
     * `$usernameColumn`/`$passwordColumn` parameters is gone (see this
     * class's own docblock).
     *
     * @return array{username: string, password: string}|null
     */
    public function findUsernameAndPassword(UserId $userId): ?array
    {
        $row = $this->em->createQueryBuilder()
            ->select('u.username AS username', 'u.password AS password')
            ->from(\Piwigo\Users\UserEntity::class, 'u')
            ->where('u.id = :id')
            ->setParameter('id', $userId)
            ->getQuery()
            ->getOneOrNullResult(\Doctrine\ORM\Query::HYDRATE_ARRAY);

        if (! is_array($row)) {
            return null;
        }

        return [
            'username' => is_string($row['username'] ?? null) ? $row['username'] : '',
            'password' => is_string($row['password'] ?? null) ? $row['password'] : '',
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
     * original's own two-query try-username-then-email order.
     *
     * SQL-modernization audit, Item 14 Sub-phase C4: converted to real
     * DQL -- `users` is now mapped ({@see \Piwigo\Users\UserEntity}); the
     * multi-auth column indirection this used to take as `$idColumn`/
     * `$usernameColumn`/`$emailColumn`/`$passwordColumn` parameters is
     * gone (see this class's own docblock).
     */
    public function findByUsernameOrEmail(string $usernameOrEmail): ?AuthUser
    {
        $qb = $this->em->createQueryBuilder()
            ->select(
                'u.id AS id',
                'u.username AS username',
                'u.mailAddress AS email',
                'u.password AS password',
                'i.status AS status',
            )
            ->from(\Piwigo\Users\UserEntity::class, 'u')
            ->leftJoin(UserInfoEntity::class, 'i', \Doctrine\ORM\Query\Expr\Join::WITH, 'u.id = i.userId')
            ->setParameter('value', $usernameOrEmail);

        $row = (clone $qb)->where('u.username = :value')
            ->getQuery()
            ->getOneOrNullResult(\Doctrine\ORM\Query::HYDRATE_ARRAY);

        if (! is_array($row)) {
            $row = $qb->where('u.mailAddress = :value')
                ->getQuery()
                ->getOneOrNullResult(\Doctrine\ORM\Query::HYDRATE_ARRAY);
        }

        return is_array($row) ? AuthUser::fromRow($row) : null;
    }

    /**
     * A single auth_key row (JOINed with its owning user's username/email),
     * or null when no such key exists. Every value that was a SQL-computed
     * pseudo-column in the original (NOW(), DATEDIFF, SUBDATE) is left to
     * the caller to compute from \Piwigo\Core\Env::now() instead.
     */
    /**
     * SQL-modernization audit, Item 14 Sub-phase C4: converted to real
     * DQL -- `users` is now mapped ({@see \Piwigo\Users\UserEntity}); the
     * multi-auth column indirection this used to take as `$idColumn`/
     * `$usernameColumn`/`$emailColumn` parameters is gone (see this
     * class's own docblock).
     */
    public function findAuthKeyDetails(string $authKey): ?AuthKeyDetails
    {
        $row = $this->em->createQueryBuilder()
            ->select(
                'uak.authKeyId AS auth_key_id',
                'uak.userId AS user_id',
                'uak.authKey AS auth_key',
                'uak.expiredOn AS expired_on',
                'uak.revokedOn AS revoked_on',
                'uak.lastUsedOn AS last_used_on',
                'uak.lastNotifiedOn AS last_notified_on',
                'uak.apikeySecret AS apikey_secret',
                'ui.status AS status',
                'u.username AS username',
                'u.mailAddress AS email',
            )
            ->from(UserAuthKeyEntity::class, 'uak')
            ->innerJoin(UserInfoEntity::class, 'ui', \Doctrine\ORM\Query\Expr\Join::WITH, 'uak.userId = ui.userId')
            ->innerJoin(\Piwigo\Users\UserEntity::class, 'u', \Doctrine\ORM\Query\Expr\Join::WITH, 'u.id = ui.userId')
            ->where('uak.authKey = :authKey')
            ->setParameter('authKey', $authKey)
            ->getQuery()
            ->getOneOrNullResult(\Doctrine\ORM\Query::HYDRATE_ARRAY);

        return is_array($row) ? AuthKeyDetails::fromRow($row) : null;
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
     * writing the other two columns.
     *
     * Item 15 audit, re-verified: this docblock previously claimed "a
     * mapped entity property write can't express [the self-assignment]"
     * -- wrong once actually tried. DQL's `SET` clause accepts an
     * arbitrary right-hand expression, including the same property path
     * again (`ui.lastmodified = ui.lastmodified`), same as
     * {@see \Piwigo\History\HistoryRepository::updateLastVisitNow()}'s own
     * converted form. `last_visit_from_history` is a real `boolean`
     * column now ({@see UserInfoEntity}) -- binds the real PHP `true`,
     * not a numeric-literal proxy for it.
     */
    public function saveLastVisitFromHistory(int $userId, ?string $lastVisit): void
    {
        $qb = $this->em->createQueryBuilder()
            ->update(UserInfoEntity::class, 'ui')
            ->set('ui.lastVisitFromHistory', ':true')
            ->set('ui.lastmodified', 'ui.lastmodified')
            ->where('ui.userId = :userId')
            ->setParameter('true', true)
            ->setParameter('userId', UserId::from($userId));

        if ($lastVisit === null) {
            $qb->set('ui.lastVisit', 'NULL');
        } else {
            $qb->set('ui.lastVisit', ':lastVisit')
                ->setParameter('lastVisit', $lastVisit);
        }

        $qb->getQuery()
            ->execute();

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
