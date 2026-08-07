<?php

declare(strict_types=1);

namespace Piwigo\Auth;

use DateTimeInterface;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Query;
use Doctrine\ORM\Query\Expr\Join;
use Piwigo\Auth\Projection\AuthKeyDetails;
use Piwigo\Auth\Projection\AuthUser;
use Piwigo\Auth\Projection\UsernamePassword;
use Piwigo\Common\ValueObject\Email;
use Piwigo\Common\ValueObject\LangCode;
use Piwigo\Common\ValueObject\SqlDateTime;
use Piwigo\Common\ValueObject\UserId;
use Piwigo\Common\ValueObject\Username;
use Piwigo\Users\UserEntity;
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
 * `users` ({@see \Piwigo\Users\UserEntity}) and `user_infos`
 * ({@see UserInfoEntity}, owned by Users\UserRepository) are both
 * ORM-mapped. Writes to `user_infos` go through it (find+set+flush)
 * rather than raw DBAL, since a raw write would leave any UserInfoEntity
 * already in this EntityManager's identity map stale for the rest of the
 * request.
 *
 * Every `NOW()`/`ADDDATE(NOW(), ...)` computation is done in PHP against
 * \Piwigo\Core\Env::now() instead of in SQL: SQL's NOW() is invisible to
 * Env::now()'s PIWIGO_TEST_NOW freeze, so a fixture-driven test comparing
 * "now" against a fixture-dated column would otherwise use two different
 * clocks.
 */
final readonly class AuthRepository
{
    private const DATETIME_FORMAT = 'Y-m-d H:i:s';

    public function __construct(
        private EntityManagerInterface $em,
    ) {}

    public function findUsernameAndPassword(UserId $userId): ?UsernamePassword
    {
        $row = $this->em->createQueryBuilder()
            ->select('u.username AS username', 'u.password AS password')
            ->from(UserEntity::class, 'u')
            ->where('u.id = :id')
            ->setParameter('id', $userId)
            ->getQuery()
            ->getOneOrNullResult(Query::HYDRATE_ARRAY);

        if (! is_array($row)) {
            return null;
        }

        $username = $row['username'] ?? null;

        return new UsernamePassword(
            username: $username instanceof Username ? $username->value : '',
            password: is_string($row['password'] ?? null) ? $row['password'] : '',
        );
    }

    public function updateLanguage(UserId $userId, string $language): void
    {
        $entity = $this->em->find(UserInfoEntity::class, $userId);
        if ($entity === null) {
            return;
        }

        $entity->language = LangCode::from($language);
        $this->em->flush();
    }

    /**
     * Finds a user by username first, falling back to email, via two
     * separate queries in that order rather than a single OR'd query.
     *
     * `u.username`/`u.mailAddress` are Username/Email-typed columns --
     * binding a raw string against either comparison would either fail
     * AbstractStringVoType::convertToDatabaseValue()'s own strict
     * VO-only check or (depending on how Doctrine infers the bind type)
     * silently never match. Parsed into the real VO for each specific
     * comparison instead, skipping a query entirely when
     * $usernameOrEmail can't even be that shape -- same "skip the query
     * for an unparseable shape" pattern already established for
     * PasswordController's own dual username-or-email lookup.
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
            ->from(UserEntity::class, 'u')
            ->leftJoin(UserInfoEntity::class, 'i', Join::WITH, 'u.id = i.userId');

        $row = null;

        $usernameVo = Username::tryFrom($usernameOrEmail);
        if ($usernameVo !== null) {
            $row = (clone $qb)->where('u.username = :value')
                ->setParameter('value', $usernameVo)
                ->getQuery()
                ->getOneOrNullResult(Query::HYDRATE_ARRAY);
        }

        if (! is_array($row)) {
            $emailVo = Email::tryFrom($usernameOrEmail);
            if ($emailVo !== null) {
                $row = $qb->where('u.mailAddress = :value')
                    ->setParameter('value', $emailVo)
                    ->getQuery()
                    ->getOneOrNullResult(Query::HYDRATE_ARRAY);
            }
        }

        return is_array($row) ? AuthUser::fromRow($row) : null;
    }

    /**
     * A single auth_key row (JOINed with its owning user's username/email),
     * or null when no such key exists. Values that would be SQL-computed
     * pseudo-columns (NOW(), DATEDIFF, SUBDATE) are left to the caller to
     * compute from \Piwigo\Core\Env::now() instead.
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
            ->innerJoin(UserInfoEntity::class, 'ui', Join::WITH, 'uak.userId = ui.userId')
            ->innerJoin(UserEntity::class, 'u', Join::WITH, 'u.id = ui.userId')
            ->where('uak.authKey = :authKey')
            ->setParameter('authKey', $authKey)
            ->getQuery()
            ->getOneOrNullResult(Query::HYDRATE_ARRAY);

        return is_array($row) ? AuthKeyDetails::fromRow($row) : null;
    }

    public function touchAuthKeyLastUsed(int|string $userId, string $authKey, DateTimeInterface $lastUsedOn): void
    {
        $this->em->createQueryBuilder()
            ->update(UserAuthKeyEntity::class, 'uak')
            ->set('uak.lastUsedOn', ':lastUsedOn')
            ->where('uak.userId = :userId')
            ->andWhere('uak.authKey = :authKey')
            ->setParameter('lastUsedOn', $lastUsedOn->format(self::DATETIME_FORMAT))
            ->setParameter('userId', UserId::from((int) $userId))
            ->setParameter('authKey', $authKey)
            ->getQuery()
            ->execute();

        $this->em->clear();
    }

    /**
     * `UserInfoEntity::$status` is `UserStatus` (enum-typed) -- unwrapped
     * to `.value` here so this method's own `?string` contract stays
     * intact for callers doing string comparisons, e.g.
     * `AuthService::createUserAuthKey()`'s `in_array($status, [...], true)`.
     */
    public function findUserStatus(UserId $userId): ?string
    {
        return $this->em->find(UserInfoEntity::class, $userId)?->status?->value;
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
            userId: UserId::from($key['user_id']),
            createdOn: SqlDateTime::from($key['created_on']),
            duration: $key['duration'],
            expiredOn: SqlDateTime::from($key['expired_on']),
            apikeyName: null,
            keyType: $key['key_type'],
        );

        $this->em->persist($entity);
        $this->em->flush();

        assert($entity->authKeyId !== null);

        return (string) $entity->authKeyId;
    }

    public function deactivateAuthKeys(int $userId, DateTimeInterface $now): void
    {
        $nowStr = $now->format(self::DATETIME_FORMAT);

        $this->em->createQueryBuilder()
            ->update(UserAuthKeyEntity::class, 'uak')
            ->set('uak.expiredOn', ':now')
            ->where('uak.userId = :userId')
            ->andWhere('uak.expiredOn > :nowCompare')
            ->andWhere("uak.keyType = 'auth_key'")
            ->setParameter('now', $nowStr)
            ->setParameter('userId', UserId::from($userId))
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

    public function setActivationKey(UserId $userId, string $hash, DateTimeInterface $expire): void
    {
        $entity = $this->em->find(UserInfoEntity::class, $userId);
        if ($entity === null) {
            return;
        }

        $entity->activationKey = $hash;
        $entity->activationKeyExpire = SqlDateTime::from($expire->format(self::DATETIME_FORMAT));
        $this->em->flush();
    }

    /**
     * Delegates to $lookup (an explicit per-call parameter, not
     * constructor-injected -- AuthRepository is freshly constructed from
     * several call sites, some in domain-service code that itself can't
     * touch History\HistoryRepository directly, e.g. Users\UserService)
     * instead of reading `history` directly: `Auth` (`L2aCoreDomain`)
     * can't depend on `History` (`L2bExtendedDomain`).
     */
    public function findLastVisitFromHistory(int $userId, LastVisitLookupInterface $lookup): ?string
    {
        return $lookup->findLastVisit($userId);
    }

    /**
     * `ui.lastmodified = ui.lastmodified` is a deliberate self-assignment,
     * not a no-op leftover -- it avoids bumping an ON UPDATE
     * CURRENT_TIMESTAMP-style column while still writing the other two
     * columns. DQL's `SET` clause accepts an arbitrary right-hand
     * expression, including the same property path again.
     * `last_visit_from_history` is a `boolean` column
     * ({@see UserInfoEntity}) -- this binds the real PHP `true`, not a
     * numeric-literal proxy for it.
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

    /**
     * Delegates to $lookup (an explicit per-call parameter, same
     * reasoning as {@see findLastVisitFromHistory()} above) instead of
     * reading `activity` directly: `Auth` (`L2aCoreDomain`) can't depend
     * on `Activity` (`L2bExtendedDomain`).
     */
    public function countLoginActivity(int $userId, LoginActivityLookupInterface $lookup): int
    {
        return $lookup->countLoginActivity($userId);
    }
}
