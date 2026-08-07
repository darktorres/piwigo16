<?php

declare(strict_types=1);

namespace Piwigo\Users;

use Doctrine\DBAL\ArrayParameterType;
use Doctrine\DBAL\ParameterType;
use Doctrine\DBAL\Query\QueryBuilder;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Query;
use Doctrine\ORM\Query\Expr\Join;
use InvalidArgumentException;
use Override;
use Piwigo\Auth\UserAuthKeyEntity;
use Piwigo\Category\UserAccessEntity;
use Piwigo\Common\Dto\PaginatedResult;
use Piwigo\Common\ValueObject\Email;
use Piwigo\Common\ValueObject\LangCode;
use Piwigo\Common\ValueObject\UserId;
use Piwigo\Common\ValueObject\Username;
use Piwigo\Config\CurrentConfig;
use Piwigo\Core\Env;
use Piwigo\Core\ThemeEntity;
use Piwigo\Core\WebmasterMailProviderInterface;
use Piwigo\Db\BatchWriter;
use Piwigo\Db\SqlDialect;
use Piwigo\Db\Tables;
use Piwigo\Event\Mail\GetWebmasterMailAddress;
use Piwigo\Group\UserGroupEntity;
use Piwigo\Image\ImageCategoryEntity;
use Piwigo\Image\ImageEntity;
use Piwigo\Image\PhotoSortField;
use Piwigo\Permission\PermissionCriteria;
use Piwigo\Permission\SqlCondition;
use Piwigo\PluginConfig\EventDispatcher;
use Piwigo\Users\Projection\ActivationKeyRow;
use Piwigo\Users\Projection\UserInfo;
use RuntimeException;

/**
 * Persistence layer for the user domain's registration/lookup/preferences
 * slice: `users` (login/password/email), `user_infos` (profile row,
 * preferences), `user_group` (default-group assignment on registration).
 *
 * `user_infos` is ORM-mapped ({@see UserInfoEntity}); `users` is also
 * mapped ({@see UserEntity}) -- its columns are fixed, not
 * caller-supplied per method. Some methods below still stay on plain DBAL
 * via `$this->em->getConnection()` for their own remaining, unrelated
 * blockers (documented per-method).
 *
 * `build_user()`/`getuserdata()`/`check_user_favorites()` ended up ported
 * into {@see \Piwigo\Users\UserService} instead of here, as OOP methods
 * (`buildUser()`/`getUserData()`/`checkUserFavorites()`) -- `user_cache`
 * no longer exists ({@see UserService::getUserData()}'s own comment), and
 * the Category-domain rollup they depend on (`get_computed_categories()`)
 * is now `CategoryService::getComputedCategories()`.
 *
 * This class doesn't `extend EntityRepository` -- Doctrine's own
 * `RepositoryFactory` always constructs an `EntityRepository` subclass
 * via a fixed `(EntityManagerInterface $em, ClassMetadata $class)`
 * signature, which would block this class from taking `CurrentConfig`/
 * `EventDispatcher` (its own 2 dependencies, getWebmasterMailAddress()'s
 * `CurrentConfig::webmasterId()`/`EventDispatcher::dispatchChange()`, and
 * findVisibleFavoriteImageIds()'s `CurrentConfig::orderByCustom()`) via
 * real constructor injection. It's a plain, container-shared service
 * instead; `UserInfoEntity`'s own `#[ORM\Entity]` mapping doesn't name
 * this class as its `repositoryClass`, so
 * `$em->getRepository(UserInfoEntity::class)` returns a generic
 * `Doctrine\ORM\EntityRepository<UserInfoEntity>` instead, and every DQL
 * builder below reaches it inline
 * (`$this->em->getRepository(UserInfoEntity::class)->createQueryBuilder(...)`)
 * rather than through a private wrapper -- phpstan-doctrine's own DQL
 * result-type inference (each `getResult()`'s row shape, e.g.
 * `array{userId: UserId}`) only traces a literal
 * `getRepository(X::class)->createQueryBuilder()` chain, not a call
 * hidden behind a same-file helper method; a wrapper collapses every
 * `getResult()` below to `mixed`.
 */
final class UserRepository implements WebmasterMailProviderInterface
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly EventDispatcher $eventDispatcher,
        private readonly CurrentConfig $currentConfig,
    ) {}

    private function find(UserId $userId): ?UserInfoEntity
    {
        return $this->em->find(UserInfoEntity::class, $userId);
    }

    /**
     * Applies a permission/filter `SqlCondition` via `andWhere()`, binding
     * every one of its parameters -- same shared-helper shape as
     * `Image\ImageRepository::applyCondition()`/`Notification\
     * NotificationRepository::applyCondition()`/`Tag\TagRepository::
     * applyCondition()`.
     *
     * SQL-modernization audit, Item 14 Sub-phase B3 re-investigation: this
     * helper's own 3 callers (findAuthorizedFavoriteImageIds() /
     * findVisibleFavoriteImageIds() / findVisibleFavoriteImages()) are all
     * fed a `PermissionService::getSqlConditionFandFAsCondition()` result
     * by their real caller ({@see \Piwigo\Ws\PwgUsers}/
     * {@see \Piwigo\Section\SectionPopulator}) -- confirmed by reading
     * those real call sites, same genuinely dynamic, cross-cutting
     * permission-condition blocker documented in
     * {@see \Piwigo\Image\ImageRepository::applyCondition()}'s own
     * docblock, not a small finite set of shapes a typed DTO could
     * replace. 2 of the 3 also take a caller-composed `$orderBySql`
     * tracing back to `WsHelper::stdImageSqlOrder()`/`CurrentConfig::
     * orderBy()`, a second, independent cross-cutting blocker (see
     * {@see \Piwigo\Image\ImageRepository::findIdsWithConditions()}'s own
     * docblock).
     *
     * Item 15G: `$orderBySql` still blocks findVisibleFavoriteImageIds()/
     * findVisibleFavoriteImages() from converting, so those 2 stay on DBAL.
     * findAuthorizedFavoriteImageIds() has no such blocker -- its own
     * "image_category has no entity" exclusion reason is now stale
     * ({@see \Piwigo\Image\ImageCategoryEntity} exists), so it converts to
     * DQL below. Widened to accept either builder type for that one
     * remaining DQL caller, same empirical finding as every other
     * `applyCondition()` in this migration: `SqlCondition`'s `andWhere()`/
     * `setParameter()` calls work identically on both.
     */
    private static function applyCondition(QueryBuilder|\Doctrine\ORM\QueryBuilder $qb, SqlCondition $condition): void
    {
        if ($condition->isEmpty()) {
            return;
        }

        $qb->andWhere($condition->sql);
        foreach ($condition->parameters as $name => $value) {
            $qb->setParameter($name, $value, $condition->types[$name] ?? ParameterType::STRING);
        }
    }

    /**
     * Returns the webmaster's email address (the users row whose id
     * column matches \Piwigo\Config\CurrentConfig::webmasterId()).
     * Implements Piwigo\Core\WebmasterMailProviderInterface (MailService's
     * test seam for this exact lookup -- see that interface's own
     * docblock); bound in config/container.php.
     */
    #[Override]
    public function getWebmasterMailAddress(): string
    {
        $webmaster_id = $this->currentConfig->webmasterId();

        $values = $this->em
            ->createQueryBuilder()
            ->select('u.mailAddress')
            ->from(UserEntity::class, 'u')
            ->where('u.id = :webmasterId')
            ->setMaxResults(1)
            ->setParameter('webmasterId', UserId::from($webmaster_id))
            ->getQuery()
            ->getSingleColumnResult();

        $value = $values[0] ?? null;

        // users.mail_address is nullable — a webmaster without an email set
        // is real, not a bug, so this degrades to '' rather than asserting
        // non-null.
        $email = is_string($value) ? Email::tryFrom($value) : null;

        return $this->eventDispatcher->dispatchChange(new GetWebmasterMailAddress($email))
            ->email->value ?? '';
    }

    /**
     * SQL-modernization audit, Item 14 Sub-phase C4: converted to real
     * DQL -- `users` is now mapped ({@see UserEntity}); the multi-auth
     * column indirection this used to take as `$idColumn`/`$usernameColumn`
     * parameters is gone (see this class's own docblock). Uses
     * `getOneOrNullResult(Query::HYDRATE_ARRAY)`, not
     * `getSingleColumnResult()` -- `u.id`'s custom `user_id` Doctrine Type
     * only gets applied by array/object hydration, not scalar-column
     * hydration (this audit's own gotcha #4).
     */
    public function findIdByUsername(Username $username): ?UserId
    {
        $row = $this->em
            ->createQueryBuilder()
            ->select('u.id')
            ->from(UserEntity::class, 'u')
            ->where('u.username = :username')
            ->setMaxResults(1)
            ->setParameter('username', $username->value)
            ->getQuery()
            ->getOneOrNullResult(Query::HYDRATE_ARRAY);

        $value = is_array($row) ? ($row['id'] ?? null) : null;

        return $value instanceof UserId ? $value : null;
    }

    /**
     * SQL-modernization audit, Item 14 Sub-phase C4: converted to real
     * DQL -- `users` is now mapped ({@see UserEntity}); the multi-auth
     * column indirection this used to take as `$idColumn`/`$emailColumn`
     * parameters is gone (see this class's own docblock). Same
     * `getOneOrNullResult(Query::HYDRATE_ARRAY)` reasoning as
     * {@see findIdByUsername()} above (this audit's own gotcha #4).
     */
    public function findIdByEmail(Email $email): ?UserId
    {
        $row = $this->em
            ->createQueryBuilder()
            ->select('u.id')
            ->from(UserEntity::class, 'u')
            ->where('UPPER(u.mailAddress) = UPPER(:email)')
            ->setMaxResults(1)
            ->setParameter('email', $email->value)
            ->getQuery()
            ->getOneOrNullResult(Query::HYDRATE_ARRAY);

        $value = is_array($row) ? ($row['id'] ?? null) : null;

        return $value instanceof UserId ? $value : null;
    }

    /**
     * @return array{id: string, username: string, email: string}|null the
     *   account matching $login (case-insensitive) or, when none matches,
     *   the account matching $email -- used both to look up an existing
     *   account for the SEC-31 duplicate-registration notice, and for the
     *   password.php-style "find by username or email" flow.
     *
     * SQL-modernization audit, Item 14 Sub-phase C4: converted to real
     * DQL -- `users` is now mapped ({@see UserEntity}); the multi-auth
     * column indirection this used to take as `$idColumn`/`$usernameColumn`/
     * `$emailColumn` parameters is gone (see this class's own docblock).
     */
    public function findByUsernameCaseInsensitive(string $username): ?array
    {
        $row = $this->em
            ->createQueryBuilder()
            ->select('u.id AS id', 'u.username AS username', 'u.mailAddress AS email')
            ->from(UserEntity::class, 'u')
            ->where('LOWER(u.username) = LOWER(:username)')
            ->setMaxResults(1)
            ->setParameter('username', $username)
            ->getQuery()
            ->getOneOrNullResult(Query::HYDRATE_ARRAY);

        if (! is_array($row)) {
            return null;
        }

        $id = $row['id'] ?? null;
        $username = $row['username'] ?? null;
        $email = $row['email'] ?? null;

        return [
            'id' => $id instanceof UserId ? (string) $id->value : '',
            'username' => $username instanceof Username ? $username->value : '',
            'email' => $email instanceof Email ? $email->value : '',
        ];
    }

    /**
     * SQL-modernization audit, Item 14 Sub-phase C4: converted to real
     * DQL -- `users` is now mapped ({@see UserEntity}); the multi-auth
     * column indirection this used to take as a `$usernameColumn`
     * parameter is gone (see this class's own docblock).
     */
    public function usernameExistsCaseInsensitive(Username $username): bool
    {
        $value = $this->em
            ->createQueryBuilder()
            ->select('COUNT(u.id)')
            ->from(UserEntity::class, 'u')
            ->where('LOWER(u.username) = LOWER(:username)')
            ->setParameter('username', $username->value)
            ->getQuery()
            ->getSingleScalarResult();

        return is_numeric($value) && (int) $value > 0;
    }

    /**
     * SQL-modernization audit, Item 14 Sub-phase C4: converted to real
     * DQL -- `users` is now mapped ({@see UserEntity}); the multi-auth
     * column indirection this used to take as `$emailColumn`/`$idColumn`
     * parameters is gone (see this class's own docblock).
     */
    public function emailExists(Email $email, ?UserId $excludeUserId): bool
    {
        $qb = $this->em
            ->createQueryBuilder()
            ->select('COUNT(u.id)')
            ->from(UserEntity::class, 'u')
            ->where('UPPER(u.mailAddress) = UPPER(:email)')
            ->setParameter('email', $email->value);

        if ($excludeUserId !== null) {
            $qb->andWhere('u.id != :excludeUserId')
                ->setParameter('excludeUserId', $excludeUserId);
        }

        $value = $qb->getQuery()
            ->getSingleScalarResult();

        return is_numeric($value) && (int) $value > 0;
    }

    /**
     * SQL-modernization audit, Item 14 Sub-phase C4: converted to real
     * DQL -- `users` is now mapped ({@see UserEntity}); the multi-auth
     * column indirection this used to take as `$idColumn`/`$usernameColumn`
     * parameters is gone (see this class's own docblock).
     */
    public function findUsernameById(UserId $userId): ?Username
    {
        $values = $this->em
            ->createQueryBuilder()
            ->select('u.username')
            ->from(UserEntity::class, 'u')
            ->where('u.id = :id')
            ->setMaxResults(1)
            ->setParameter('id', $userId)
            ->getQuery()
            ->getSingleColumnResult();

        $value = $values[0] ?? null;

        return is_string($value) ? Username::tryFrom($value) : null;
    }

    /**
     * SQL-modernization audit, Item 14 Sub-phase C4: converted to real
     * DQL -- `users` is now mapped ({@see UserEntity}); the multi-auth
     * column indirection this used to take as a `$usernameColumn`
     * parameter is gone (see this class's own docblock).
     *
     * @return list<string>
     */
    public function findAllUsernames(): array
    {
        $names = $this->em
            ->createQueryBuilder()
            ->select('u.username')
            ->from(UserEntity::class, 'u')
            ->getQuery()
            ->getSingleColumnResult();

        return array_values(array_map(
            static fn (mixed $name): string => is_scalar($name) ? (string) $name : '',
            $names
        ));
    }

    /**
     * Every username keyed by id -- Admin\CatPermPageRenderer's own "list
     * every user for the groups/users permission form" lookup.
     *
     * SQL-modernization audit, Item 14 Sub-phase C4: converted to real
     * DQL -- `users` is now mapped ({@see UserEntity}); the multi-auth
     * column indirection this used to take as `$idColumn`/`$usernameColumn`
     * parameters is gone (see this class's own docblock).
     *
     * @return array<int|string, mixed> keyed by id
     */
    public function findAllUsernamesById(): array
    {
        $rows = $this->em
            ->createQueryBuilder()
            ->select('u.id AS id', 'u.username AS username')
            ->from(UserEntity::class, 'u')
            ->getQuery()
            ->getArrayResult();

        $byId = [];
        foreach ($rows as $row) {
            if (! is_array($row)) {
                continue;
            }

            $id = $row['id'] ?? null;
            if (! $id instanceof UserId) {
                continue;
            }

            $username = $row['username'] ?? null;
            $byId[$id->value] = $username instanceof Username ? $username->value : null;
        }

        return $byId;
    }

    /**
     * New user row, always auto-increment -- UserService's own
     * registration flow. See {@see insertUserWithId()} below for the one
     * real caller that needs an explicit, caller-chosen id instead.
     *
     * SQL-modernization audit, Item 14 Sub-phase C4: converted to real
     * DQL -- `users` is now mapped ({@see UserEntity}); this used to take
     * a `$columns` generic pwgfield => column-name-and-value map (the
     * multi-auth indirection this class's own docblock explains), now
     * typed params directly.
     */
    public function insertUser(Username $username, ?string $password, ?Email $mailAddress): UserId
    {
        $entity = new UserEntity($username, $password, $mailAddress);
        $em = $this->em;
        $em->persist($entity);
        $em->flush();

        if ($entity->id === null) {
            throw new RuntimeException('UserEntity::$id not populated after flush()');
        }

        return $entity->id;
    }

    /**
     * New user row with an explicit, caller-chosen id -- Admin\Integrity\
     * C13yInternal's own "recreate a missing guest/default/webmaster user
     * row with its exact known id" repair step.
     *
     * Item 14 DQL audit: stays on DBAL, deliberately -- Doctrine's
     * `IDENTITY` id-generator strategy (MySQL `AUTO_INCREMENT`) always
     * overwrites a pre-set id with the driver's own `lastInsertId()` after
     * `persist()`/`flush()`, so the ORM can't express "insert with this
     * exact id" the way a raw INSERT can; not a multi-auth-column
     * question anymore, a real ORM limitation for this one caller's shape.
     */
    public function insertUserWithId(UserId $id, Username $username, ?string $password): UserId
    {
        $this->em
            ->getConnection()
            ->createQueryBuilder()
            ->insert(Tables::users())
            ->values([
                'id' => ':id',
                'username' => ':username',
                'password' => ':password',
            ])
            ->setParameter('id', $id->value)
            ->setParameter('username', $username->value)
            ->setParameter('password', $password)
            ->executeStatement();

        return $id;
    }

    /**
     * @param list<UserId> $userIds
     * @param array<string, mixed> $row default column => value pairs,
     *   copied onto every inserted row (matches create_user_infos()'s own
     *   "start from get_default_user_info(), overlay per-row fields"
     *   logic) -- keys the caller omits fall back to `user_infos`'s own
     *   schema-declared column defaults, matching the original raw INSERT's
     *   behavior when a column was left out of the value list entirely.
     */
    public function insertUserInfos(array $userIds, array $row): void
    {
        if ($userIds === []) {
            return;
        }

        $em = $this->em;
        foreach ($userIds as $userId) {
            $em->persist($this->buildUserInfoEntity($userId, $row));
        }

        $em->flush();
    }

    /**
     * @param array<string, mixed> $row
     */
    private function buildUserInfoEntity(UserId $userId, array $row): UserInfoEntity
    {
        $preferencesRaw = $row['preferences'] ?? null;

        return new UserInfoEntity(
            userId: $userId,
            nbImagePage: is_numeric($row['nb_image_page'] ?? null) ? (int) $row['nb_image_page'] : 15,
            // UserInfoEntity::$status is UserStatus (enumType-mapped) --
            // $row is a caller-supplied bag (not necessarily DB-sourced),
            // so this uses tryFrom() with a defensive fallback to guest
            // rather than Doctrine's own throw-on-mismatch from() (that
            // throw is safe only for values already read back from the
            // DB-constrained `enum(...)` column, not arbitrary caller
            // input).
            status: is_string($row['status'] ?? null) ? (UserStatus::tryFrom($row['status']) ?? UserStatus::Guest) : UserStatus::Guest,
            language: LangCode::tryFrom($row['language'] ?? null) ?? LangCode::from('en_UK'),
            expand: (bool) ($row['expand'] ?? false),
            showNbComments: (bool) ($row['show_nb_comments'] ?? false),
            showNbHits: (bool) ($row['show_nb_hits'] ?? false),
            recentPeriod: is_numeric($row['recent_period'] ?? null) ? (int) $row['recent_period'] : 7,
            theme: is_string($row['theme'] ?? null) ? $row['theme'] : 'default',
            registrationDate: is_string($row['registration_date'] ?? null) ? $row['registration_date'] : null,
            enabledHigh: (bool) ($row['enabled_high'] ?? true),
            level: is_numeric($row['level'] ?? null) ? (int) $row['level'] : 0,
            activationKey: is_string($row['activation_key'] ?? null) ? $row['activation_key'] : null,
            activationKeyExpire: is_string($row['activation_key_expire'] ?? null) ? $row['activation_key_expire'] : null,
            lastVisit: is_string($row['last_visit'] ?? null) ? $row['last_visit'] : null,
            lastVisitFromHistory: (bool) ($row['last_visit_from_history'] ?? false),
            lastmodified: is_string($row['lastmodified'] ?? null) ? $row['lastmodified'] : Env::now()->format('Y-m-d H:i:s'),
            preferences: is_array($preferencesRaw) ? array_filter($preferencesRaw, is_string(...), ARRAY_FILTER_USE_KEY) : null,
        );
    }

    public function findDefaultUserInfoRow(UserId $defaultUserId): ?UserInfo
    {
        $entity = $this->find($defaultUserId);

        return $entity === null ? null : UserInfo::fromEntity($entity);
    }

    /**
     * @param array<string, mixed> $preferences
     */
    public function savePreferences(UserId $userId, array $preferences): void
    {
        $entity = $this->find($userId);
        if ($entity === null) {
            return;
        }

        $entity->preferences = $preferences;

        $this->em
            ->flush();
    }

    /**
     * @return list<UserId>
     */
    public function findAdminIds(bool $includeWebmaster = true): array
    {
        $statusList = ['admin'];
        if ($includeWebmaster) {
            $statusList[] = 'webmaster';
        }

        $rows = $this->em->getRepository(UserInfoEntity::class)->createQueryBuilder('ui')
            ->select('ui.userId')
            ->where('ui.status IN (:statuses)')
            ->setParameter('statuses', $statusList)
            ->getQuery()
            ->getResult();

        return array_map(static function (array $row): UserId {
            if (! $row['userId'] instanceof UserId) {
                throw new RuntimeException('Expected UserId from DQL scalar hydration of ui.userId');
            }

            return $row['userId'];
        }, $rows);
    }

    /**
     * Deletes a user's row across every table referencing it -- pure
     * cascade delete, no business logic (session purge / activity log
     * stay in Piwigo\Users\UserService::deleteUser(), which calls this).
     * `user_access`/`user_auth_keys`/`user_group`/`user_infos`/`users`/
     * `favorites` are deleted here via DQL. `user_mail_notification`/
     * `user_feed`/`caddie` are not touched here -- each of those tables'
     * own `user_id` column carries a real `ON DELETE CASCADE` FK straight
     * to `users.id`, so the database cascades those rows away once the
     * `users` row itself is deleted below.
     */
    public function deleteUser(UserId $userId): void
    {
        $em = $this->em;

        $em->createQueryBuilder()
            ->delete(UserAccessEntity::class, 'ua')
            ->where('ua.userId = :userId')
            ->setParameter('userId', $userId->value)
            ->getQuery()
            ->execute();

        $em->createQueryBuilder()
            ->delete(UserAuthKeyEntity::class, 'uak')
            ->where('uak.userId = :userId')
            ->setParameter('userId', $userId->value)
            ->getQuery()
            ->execute();

        $em->createQueryBuilder()
            ->delete(UserGroupEntity::class, 'ug')
            ->where('ug.userId = :userId')
            ->setParameter('userId', $userId)
            ->getQuery()
            ->execute();

        $em->createQueryBuilder()
            ->delete(UserInfoEntity::class, 'ui')
            ->where('ui.userId = :userId')
            ->setParameter('userId', $userId)
            ->getQuery()
            ->execute();

        $em->createQueryBuilder()
            ->delete(UserEntity::class, 'u')
            ->where('u.id = :userId')
            ->setParameter('userId', $userId)
            ->getQuery()
            ->execute();

        $em->createQueryBuilder()
            ->delete(FavoriteEntity::class, 'f')
            ->where('f.userId = :userId')
            ->setParameter('userId', $userId)
            ->getQuery()
            ->execute();

        // These are DQL bulk deletes, which bypass the ORM's identity map
        // -- any entity this EntityManager already loaded for this user
        // (UserEntity, UserInfoEntity, UserAccessEntity, UserAuthKeyEntity,
        // UserGroupEntity, FavoriteEntity) would otherwise stay stale
        // (same identity-map reasoning as GroupRepository::delete()'s own
        // comment).
        $em->clear();
    }

    /**
     * Every user id from the base users table.
     *
     * SQL-modernization audit, Item 14 Sub-phase C4: converted to real
     * DQL -- `users` is now mapped ({@see UserEntity}); the multi-auth
     * column indirection this used to take as a `$userIdColumn` parameter
     * is gone (see this class's own docblock). Uses `getArrayResult()`,
     * not `getSingleColumnResult()` -- `u.id`'s custom `user_id` Doctrine
     * Type only gets applied by the former (this audit's own gotcha #4).
     *
     * @return list<UserId>
     */
    public function findAllUserIds(): array
    {
        $rows = $this->em
            ->createQueryBuilder()
            ->select('u.id')
            ->from(UserEntity::class, 'u')
            ->getQuery()
            ->getArrayResult();

        $ids = [];
        foreach ($rows as $row) {
            if (is_array($row) && ($row['id'] ?? null) instanceof UserId) {
                $ids[] = $row['id'];
            }
        }

        return $ids;
    }

    /**
     * Item 15 audit: `user_mail_notification`/`user_feed` are the only 2
     * of {@see \Piwigo\Users\UserService::syncUsers()}'s own 5-table list
     * this method still serves -- both a real `deleteSiteRow`-class
     * deptrac boundary (`Users` is `L2aCoreDomain`, those 2 tables'
     * domains are `L2bExtendedDomain`), so `$table` stays a raw runtime
     * string here permanently. The other 3 tables go through
     * {@see findDistinctUserIdsInMappedTable()}'s bounded enum instead.
     *
     * @return list<UserId>
     */
    public function findDistinctUserIdsInTable(string $table): array
    {
        return array_values(array_filter(array_map(static fn (mixed $v): ?UserId => UserId::tryFrom($v), $this->em
            ->getConnection()
            ->createQueryBuilder()
            ->select('DISTINCT user_id')
            ->from($table)
            ->executeQuery()
            ->fetchFirstColumn())));
    }

    /**
     * Item 15 audit: same permanent DBAL/deptrac reasoning as
     * {@see findDistinctUserIdsInTable()} above -- only
     * `user_mail_notification`/`user_feed` still reach this method.
     *
     * @param list<UserId> $userIds
     */
    public function deleteUsersFromTable(string $table, array $userIds): void
    {
        if ($userIds === []) {
            return;
        }

        $em = $this->em;
        $em->getConnection()
            ->createQueryBuilder()
            ->delete($table)
            ->where('user_id IN (:userIds)')
            ->setParameter('userIds', array_map(static fn (UserId $id): int => $id->value, $userIds), ArrayParameterType::INTEGER)
            ->executeStatement();

        // $table may be user_infos -- bypasses the ORM, so any
        // UserInfoEntity already in this EntityManager's identity map for
        // one of these ids would otherwise stay stale (same reasoning as
        // deleteUser()'s own comment).
        $em->clear();
    }

    /**
     * Item 15 audit: real DQL replacement for
     * {@see findDistinctUserIdsInTable()}, for the 3 of
     * {@see \Piwigo\Users\UserService::syncUsers()}'s own tables this
     * repository can actually reach via DQL -- see
     * {@see \Piwigo\Users\UserRelatedTable}'s own docblock for why the
     * other 2 stay on that raw-string method permanently.
     *
     * @return list<UserId>
     */
    public function findDistinctUserIdsInMappedTable(UserRelatedTable $table): array
    {
        [$entityClass, $alias] = match ($table) {
            UserRelatedTable::UserInfos => [UserInfoEntity::class, 'ui'],
            UserRelatedTable::UserAccess => [UserAccessEntity::class, 'ua'],
            UserRelatedTable::UserGroup => [UserGroupEntity::class, 'ug'],
        };

        $rows = $this->em
            ->createQueryBuilder()
            ->select("DISTINCT {$alias}.userId")
            ->from($entityClass, $alias)
            ->getQuery()
            ->getSingleColumnResult();

        $ids = [];
        foreach ($rows as $row) {
            $id = $row instanceof UserId ? $row : UserId::tryFrom($row);
            if ($id !== null) {
                $ids[] = $id;
            }
        }

        return $ids;
    }

    /**
     * Item 15 audit: real DQL replacement for
     * {@see deleteUsersFromTable()}, same 3-of-5-tables scope as
     * {@see findDistinctUserIdsInMappedTable()} above. `UserAccessEntity::
     * $userId` is a plain int column (unlike `UserInfoEntity`/
     * `UserGroupEntity`'s custom `user_id` Doctrine Type), so it binds
     * `$userIds` unwrapped to raw ints -- same "bind the VO for a
     * custom-typed target, unwrap for a plain-int one" pattern already
     * established by {@see deleteUser()}.
     *
     * @param list<UserId> $userIds
     */
    public function deleteUsersFromMappedTable(UserRelatedTable $table, array $userIds): void
    {
        if ($userIds === []) {
            return;
        }

        [$entityClass, $alias] = match ($table) {
            UserRelatedTable::UserInfos => [UserInfoEntity::class, 'ui'],
            UserRelatedTable::UserAccess => [UserAccessEntity::class, 'ua'],
            UserRelatedTable::UserGroup => [UserGroupEntity::class, 'ug'],
        };

        $em = $this->em;
        $em->createQueryBuilder()
            ->delete($entityClass, $alias)
            ->where("{$alias}.userId IN (:userIds)")
            ->setParameter(
                'userIds',
                $table === UserRelatedTable::UserAccess
                    ? array_map(static fn (UserId $id): int => $id->value, $userIds)
                    : $userIds,
                $table === UserRelatedTable::UserAccess ? ArrayParameterType::INTEGER : null,
            )
            ->getQuery()
            ->execute();
        $em->clear();
    }

    /**
     * Basic `users`-table row for UserService::getUserData(), keyed
     * id/username/password/email (matching the generic pwgfield names
     * `CurrentConfig::userFields()` used to map to real columns -- see
     * this class's own docblock for why that indirection is gone now).
     * `id` is `$userId->value` directly, not re-selected -- the caller
     * already has it, and every real downstream consumer of this array
     * (templates, WS responses) expects a raw scalar there, not a UserId
     * VO (this audit's own gotcha #4 territory, sidestepped entirely by
     * not selecting the custom-Typed column at all).
     *
     * SQL-modernization audit, Item 14 Sub-phase C4: converted to real
     * DQL -- `users` is now mapped ({@see UserEntity}). Declared
     * `array<string, mixed>`, not the more precise shape this method's own
     * literal return would otherwise infer -- UserService::getUserData()
     * array_merge()s this with a second, loosely-typed row and reads
     * several keys neither row declares (preferences/status, from the
     * `user_infos` half); a precise shape here would make PHPStan treat
     * those as possibly-missing after the merge, which isn't a new risk
     * this conversion introduces.
     *
     * @return array<string, mixed>|false
     */
    public function fetchBasicUserRow(UserId $userId): array|false
    {
        $row = $this->em
            ->createQueryBuilder()
            ->select('u.username AS username', 'u.password AS password', 'u.mailAddress AS email')
            ->from(UserEntity::class, 'u')
            ->where('u.id = :userId')
            ->setParameter('userId', $userId)
            ->getQuery()
            ->getOneOrNullResult(Query::HYDRATE_ARRAY);

        if (! is_array($row)) {
            return false;
        }

        $username = $row['username'] ?? null;
        $email = $row['email'] ?? null;

        return [
            'id' => $userId->value,
            'username' => $username instanceof Username ? $username->value : '',
            'password' => is_string($row['password'] ?? null) ? $row['password'] : null,
            'email' => $email instanceof Email ? $email->value : null,
        ];
    }

    /**
     * Whether exactly one `user_infos` row (LEFT JOINed with `themes`, a
     * harmless no-op for this COUNT -- see UserService::getUserData()'s own
     * comment) exists for $userId -- the externalAuthentification
     * integrity check gating whether a missing row needs creating.
     *
     * Item 14 DQL audit, re-corrected: `themes` is now mapped ({@see
     * ThemeEntity}). Converted to real DQL -- LEFT JOIN via an explicit
     * `Join::WITH` condition (no formal association between UserInfoEntity
     * and ThemeEntity), same shape as
     * {@see \Piwigo\Category\CategoryRepository::findPrivateCategoriesGrantedToUser()}'s
     * own precedent. `ui.user_id = :userId` scopes to at most one
     * `user_infos` row (its own primary key), so `getResult()` never
     * returns more than one row here regardless of the GROUP BY.
     */
    public function countUserInfosRows(UserId $userId): int
    {
        $rows = $this->em->getRepository(UserInfoEntity::class)->createQueryBuilder('ui')
            ->select('COUNT(ui.userId) AS counter')
            ->leftJoin(ThemeEntity::class, 't', Join::WITH, 't.id = ui.theme')
            ->where('ui.userId = :userId')
            ->groupBy('ui.userId')
            ->setParameter('userId', $userId)
            ->getQuery()
            ->getResult();

        $counter = $rows[0]['counter'] ?? null;

        return is_int($counter) ? $counter : 0;
    }

    /**
     * Full `user_infos` row plus the joined theme's display name --
     * UserService::getUserData()'s own merge-with-basic-row step.
     *
     * Item 16H: converted to real DQL against the full {@see UserInfoEntity}
     * -- a mapping shim right here translates its camelCase-hydrated
     * properties back into the exact snake_case-keyed array shape
     * `UserService::getUserData()`'s own `array_merge()` and every
     * downstream `$userdata['...']` read already expect, so the DQL
     * conversion needs zero changes to that consumer. A real bonus, not
     * just parity: `expand`/`show_nb_comments`/`show_nb_hits`/
     * `enabled_high`/`last_visit_from_history` come back as genuine PHP
     * bools straight from the entity (mapped `boolean` columns), where
     * the raw DBAL row gave a native tinyint `getUserData()` had to
     * explicitly re-cast.
     *
     * @return array<string, mixed>|false
     */
    public function fetchUserInfosWithThemeName(UserId $userId): array|false
    {
        $row = $this->em
            ->createQueryBuilder()
            ->select('ui', 't.name AS theme_name')
            ->from(UserInfoEntity::class, 'ui')
            ->leftJoin(ThemeEntity::class, 't', Join::WITH, 't.id = ui.theme')
            ->where('ui.userId = :userId')
            ->setParameter('userId', $userId)
            ->getQuery()
            ->getOneOrNullResult();

        if (! is_array($row) || ! $row[0] instanceof UserInfoEntity) {
            return false;
        }

        $userInfo = $row[0];
        $themeName = $row['theme_name'] ?? null;
        $themeName = is_string($themeName) ? $themeName : null;

        return [
            'user_id' => $userInfo->userId->value,
            'nb_image_page' => $userInfo->nbImagePage,
            'status' => $userInfo->status->value,
            'language' => $userInfo->language->value,
            'expand' => $userInfo->expand,
            'show_nb_comments' => $userInfo->showNbComments,
            'show_nb_hits' => $userInfo->showNbHits,
            'recent_period' => $userInfo->recentPeriod,
            'theme' => $userInfo->theme,
            'registration_date' => $userInfo->registrationDate,
            'enabled_high' => $userInfo->enabledHigh,
            'level' => $userInfo->level,
            'activation_key' => $userInfo->activationKey,
            'activation_key_expire' => $userInfo->activationKeyExpire,
            'last_visit' => $userInfo->lastVisit,
            'last_visit_from_history' => $userInfo->lastVisitFromHistory,
            'lastmodified' => $userInfo->lastmodified,
            'preferences' => $userInfo->preferences,
            'theme_name' => $themeName,
        ];
    }

    /**
     * Favorite image ids for $userId restricted to $criteria --
     * UserService::checkUserFavorites()'s own "images still in an
     * authorized category" half of the comparison. The one real caller
     * only ever applies forbiddenCategoryIds, against `ic.category_id`.
     *
     * @return list<int>
     */
    public function findAuthorizedFavoriteImageIds(UserId $userId, PermissionCriteria $criteria): array
    {
        $qb = $this->em
            ->createQueryBuilder()
            ->select('DISTINCT f.imageId')
            ->from(FavoriteEntity::class, 'f')
            ->innerJoin(ImageCategoryEntity::class, 'ic', Join::WITH, 'f.imageId = ic.imageId')
            ->where('f.userId = :userId')
            ->setParameter('userId', $userId);

        self::applyCondition($qb, $criteria->forbiddenCategoriesCondition('ic.categoryId'));

        return self::toIntList(array_values($qb->getQuery()->getSingleColumnResult()));
    }

    /**
     * Every favorite image id for $userId, unfiltered -- the other half of
     * UserService::checkUserFavorites()'s comparison.
     *
     * Item 14 DQL audit, re-corrected: `favorites` is now mapped ({@see
     * FavoriteEntity}). Converted to real DQL -- single-table select of
     * `f.imageId` via `getSingleColumnResult()` (Gotcha #4, `HYDRATE_
     * SCALAR_COLUMN`) never applies FavoriteEntity::$imageId's own
     * `image_id` custom Type, so this stays a plain int read regardless.
     *
     * @return list<int>
     */
    public function findFavoriteImageIds(UserId $userId): array
    {
        $rows = $this->em
            ->createQueryBuilder()
            ->select('f.imageId')
            ->from(FavoriteEntity::class, 'f')
            ->where('f.userId = :userId')
            ->setParameter('userId', $userId)
            ->getQuery()
            ->getSingleColumnResult();

        return self::toIntList(array_values($rows));
    }

    /**
     * Item 14 DQL audit, re-corrected: `favorites` is now mapped ({@see
     * FavoriteEntity}). Converted to real DQL bulk DELETE -- still bypasses
     * the identity map the same way the previous raw-DBAL DELETE did (a
     * DQL bulk DELETE also skips the ORM's own cascade/lifecycle handling),
     * so this is not a behavior change either way.
     *
     * @param list<int> $imageIds
     */
    public function deleteFavoritesForImages(UserId $userId, array $imageIds): void
    {
        if ($imageIds === []) {
            return;
        }

        $this->em
            ->createQueryBuilder()
            ->delete(FavoriteEntity::class, 'f')
            ->where('f.imageId IN (:imageIds)')
            ->andWhere('f.userId = :userId')
            ->setParameter('imageIds', $imageIds, ArrayParameterType::INTEGER)
            ->setParameter('userId', $userId)
            ->getQuery()
            ->execute();
    }

    /**
     * Adds a single favorite -- Controller\PictureController's own
     * "add_to_favorites" action, unlike deleteFavoritesForImages() above
     * which is a bulk removal. $ignoreDuplicate matches
     * Category\CategoryRepository::massInsertGroupAccess()'s own `ignore`
     * convention -- Ws\PwgUsers::favoritesAdd() needs INSERT IGNORE so a
     * repeat "add favorite" call for an already-favorited image (the
     * `(user_id, image_id)` primary key) stays a no-op instead of a
     * duplicate-key error; PictureController's own call keeps the
     * pre-existing plain-INSERT default unchanged.
     *
     * Item 14 DQL audit: not a DQL-vs-DBAL question -- single-row write via
     * BatchWriter (its own `ignore`-flag INSERT IGNORE support has no ORM
     * persist() equivalent); `favorites` also has no entity anywhere in
     * this migration regardless.
     */
    public function addFavorite(UserId $userId, int $imageId, bool $ignoreDuplicate = false): void
    {
        new BatchWriter($this->em->getConnection())
            ->singleInsert(
                Tables::favorites(),
                [
                    'image_id' => $imageId,
                    'user_id' => $userId->value,
                ],
                [
                    'ignore' => $ignoreDuplicate,
                ]
            );
    }

    /**
     * Whether $imageId is already among $userId's favorites --
     * Controller\PictureController's own favorite-icon toggle state.
     *
     * Item 14 DQL audit, re-corrected: `favorites` is now mapped ({@see
     * FavoriteEntity}). Converted to real DQL -- `COUNT()` with no GROUP BY
     * always returns exactly one row, same
     * {@see findMinRegistrationDateAfter()}-established reasoning for why
     * `getSingleScalarResult()` is safe here (never
     * NonUniqueResultException territory).
     */
    public function isFavorite(UserId $userId, int $imageId): bool
    {
        $value = $this->em
            ->createQueryBuilder()
            ->select('COUNT(f.imageId)')
            ->from(FavoriteEntity::class, 'f')
            ->where('f.imageId = :imageId')
            ->andWhere('f.userId = :userId')
            ->setParameter('imageId', $imageId, ParameterType::INTEGER)
            ->setParameter('userId', $userId)
            ->getQuery()
            ->getSingleScalarResult();

        return is_numeric($value) && (int) $value !== 0;
    }

    /**
     * Removes every favorite for $userId regardless of image -- Section\
     * SectionPopulator's own "remove_all_from_favorites" action, unlike
     * deleteFavoritesForImages() above which is scoped to a given image set.
     *
     * Item 14 DQL audit, re-corrected: `favorites` is now mapped ({@see
     * FavoriteEntity}). Converted to real DQL bulk DELETE -- same
     * not-a-behavior-change reasoning as
     * {@see deleteFavoritesForImages()} above.
     */
    public function deleteAllFavorites(UserId $userId): void
    {
        $this->em
            ->createQueryBuilder()
            ->delete(FavoriteEntity::class, 'f')
            ->where('f.userId = :userId')
            ->setParameter('userId', $userId)
            ->getQuery()
            ->execute();
    }

    /**
     * Visible favorite image ids for $userId, in $orderBySql order --
     * Section\SectionPopulator's own "favorites" section listing. Returns
     * list<string|null> (not list<int>, this class's usual convention) to
     * match SectionRepository::queryColumn()'s own shape, since the caller
     * merges this directly alongside that repository's sibling section
     * queries into the same $page['items'] slot.
     *
     * SQL-modernization audit, Item 14 Sub-phase C1: converted to a typed
     * {@see PermissionCriteria} -- the one real caller only ever applies
     * visibleImageIds against the unqualified `id` (only `images` has a
     * bare `id` column in this join, no alias needed). It also passed
     * `visible_images => 'id'` to the old `getSqlConditionFandFAsCondition()`,
     * whose own `visible_images` case falls through into `forbidden_images`
     * with no `break` -- with fieldName `'id'`, that's the images-table's
     * own `level <= x` check, so maxLevel applies here too, against the
     * unqualified `level`. $orderBySql stays a raw fragment
     * (CurrentConfig::orderBy(), trusted internal config, same "caller
     * composes trusted fragments" contract used throughout this codebase).
     *
     * Item 14 DQL audit: stayed on DBAL -- `favorites` has no entity
     * anywhere in this migration, and $orderBySql concatenates a
     * caller-composed raw ORDER BY fragment directly.
     *
     * Item 16J: `favorites` is now mapped ({@see FavoriteEntity}), and
     * this method runs real DQL whenever $orderBySql parses against the
     * bounded `$sort_fields` vocabulary, falling back to the original raw
     * DBAL query -- unchanged below -- otherwise. Never offers an
     * `image_category` alias for `Rank`: unlike {@see \Piwigo\Category\
     * CategoryRepository::findImageIdsForCategories()}, a favorites
     * listing has no single-category context to make "the" rank
     * well-defined.
     *
     * @return list<string|null>
     */
    public function findVisibleFavoriteImageIds(UserId $userId, PermissionCriteria $criteria, string $orderBySql): array
    {
        $dqlOrderBy = $this->resolveFavoritesDqlOrderBy($orderBySql);
        if ($dqlOrderBy !== null) {
            $qb = $this->em
                ->createQueryBuilder()
                ->select('i.id')
                ->from(FavoriteEntity::class, 'f')
                ->innerJoin(ImageEntity::class, 'i', Join::WITH, 'f.imageId = i.id')
                ->where('f.userId = :userId')
                ->setParameter('userId', $userId);
            self::applyCondition($qb, SqlCondition::combine(
                'AND',
                $criteria->visibleImagesCondition('i.id'),
                $criteria->maxLevelCondition('i.level'),
            ));
            foreach ($dqlOrderBy as $entry) {
                $qb->addOrderBy($entry['property'], $entry['dir']);
            }

            $ids = $qb->getQuery()
                ->getSingleColumnResult();

            return array_values(array_map(
                static fn (mixed $v): ?string => is_scalar($v) ? (string) $v : null,
                $ids
            ));
        }

        $favoritesTable = Tables::favorites();
        $imagesTable = Tables::images();

        $condition = SqlCondition::combine(
            'AND',
            $criteria->visibleImagesCondition('id'),
            $criteria->maxLevelCondition('level'),
        );

        $whereSql = 'WHERE user_id = :userId';
        $params = [
            'userId' => $userId->value,
        ];
        $types = [];
        if (! $condition->isEmpty()) {
            $whereSql .= ' AND ' . $condition->sql;
            $params = array_merge($params, $condition->parameters);
            $types = $condition->types;
        }

        $orderBySql = self::normalizeOrderBySql($orderBySql);
        $rows = $this->em
            ->getConnection()
            ->executeQuery(
                <<<SQL
                SELECT image_id
                FROM {$favoritesTable}
                    INNER JOIN {$imagesTable} ON image_id = id
                {$whereSql}
                {$orderBySql}
                SQL
                ,
                $params,
                $types
            )->fetchFirstColumn();

        return array_map(
            static fn (mixed $v): ?string => is_scalar($v) ? (string) $v : null,
            $rows
        );
    }

    /**
     * @return list<array{property: string, dir: 'ASC'|'DESC'}>|null
     */
    private function resolveFavoritesDqlOrderBy(string $orderBySql): ?array
    {
        if ($this->currentConfig->orderByCustom() !== null) {
            return null;
        }

        return PhotoSortField::resolveDqlOrderBy($orderBySql, 'i');
    }

    /**
     * pgsql support pass: real bug found live -- $orderBySql is raw,
     * sysadmin-settable SQL text (order_by/order_by_custom, or a plain
     * "ORDER BY RAND()" fallback both real callers and this class's own
     * unparseable-order-by test fixture use), commonly containing the
     * well-known "RAND()" random-order value. Same real gap already fixed
     * once for {@see \Piwigo\Category\CategoryRepository}'s own raw-DBAL
     * fallback -- confirmed live: "function rand() does not exist"
     * against a real Postgres server otherwise.
     */
    private static function normalizeOrderBySql(string $orderBySql): string
    {
        return str_ireplace('RAND()', SqlDialect::randomFunction() . '()', $orderBySql);
    }

    /**
     * Every column of every favorite image for $userId matching
     * $condition -- Ws\PwgUsers::favoritesGetList()'s own full row
     * listing, a different contract from
     * {@see findVisibleFavoriteImageIds()} above (that one is
     * `image_id`-only, this one is `i.*`).
     *
     * SQL-modernization audit, Item 14 Sub-phase C1: converted to a typed
     * {@see PermissionCriteria} -- the one real caller only ever applies
     * visibleImageIds against `i.id`. It also passed `visible_images =>
     * 'id'` to the old `getSqlConditionFandFAsCondition()`, whose own
     * `visible_images` case falls through into `forbidden_images` with no
     * `break` -- with fieldName `'id'`, that's the images-table's own
     * `level <= x` check, so maxLevel applies here too, against `i.level`.
     * $orderBySql stays a raw fragment, same reasoning as
     * findVisibleFavoriteImageIds() above.
     *
     * Item 14 DQL audit: stays on DBAL -- `favorites` has no entity
     * anywhere in this migration, $orderBySql concatenates a
     * caller-composed raw fragment, and it selects `i.*` (a whole-row
     * shape, not a fixed DQL property list).
     *
     * @return list<array<string, mixed>>
     */
    public function findVisibleFavoriteImages(UserId $userId, PermissionCriteria $criteria, string $orderBySql): array
    {
        $favoritesTable = Tables::favorites();
        $imagesTable = Tables::images();

        $condition = SqlCondition::combine(
            'AND',
            $criteria->visibleImagesCondition('i.id'),
            $criteria->maxLevelCondition('i.level'),
        );

        $whereSql = 'WHERE user_id = :userId';
        $params = [
            'userId' => $userId->value,
        ];
        $types = [];
        if (! $condition->isEmpty()) {
            $whereSql .= ' AND ' . $condition->sql;
            $params = array_merge($params, $condition->parameters);
            $types = $condition->types;
        }

        $orderBySql = self::normalizeOrderBySql($orderBySql);

        return $this->em
            ->getConnection()
            ->executeQuery(
                <<<SQL
                SELECT
                    i.*
                FROM {$favoritesTable}
                    INNER JOIN {$imagesTable} i ON image_id = i.id
                {$whereSql}
                {$orderBySql}
                SQL
                ,
                $params,
                $types
            )->fetchAllAssociative();
    }

    /**
     * Bulk `user_infos.status` update -- UserService::checkAndSaveUserInfos()'s
     * own status-change branch, applied to every id in $userIds at once.
     *
     * Item 14 DQL audit: converted to real DQL -- single-table bulk UPDATE,
     * static column/property. Still bypasses the identity map the same way
     * the previous raw-DBAL UPDATE did (a DQL bulk UPDATE also skips
     * persist()/flush()'s change tracking), so this is not a behavior
     * change either way.
     *
     * @param list<UserId> $userIds
     */
    public function updateStatusForUsers(array $userIds, string $status): void
    {
        if ($userIds === []) {
            return;
        }

        $this->em
            ->createQueryBuilder()
            ->update(UserInfoEntity::class, 'ui')
            ->set('ui.status', ':status')
            ->where('ui.userId IN (:userIds)')
            ->setParameter('status', $status)
            ->setParameter('userIds', array_map(static fn (UserId $id): int => $id->value, $userIds), ArrayParameterType::INTEGER)
            ->getQuery()
            ->execute();
    }

    /**
     * Bulk `user_infos` field update -- UserService::checkAndSaveUserInfos()'s
     * own dynamic-fields branch (nb_image_page/language/recent_period/etc,
     * whichever the caller actually changed), applied to every id in
     * $userIds at once. $updates stays a raw column => scalar-value map,
     * same dynamic-column reasoning as fetchBasicUserRow() above.
     *
     * Item 15 audit: `$updates`'s keys are now validated against
     * {@see UserInfoField}'s bounded enum before reaching the `set()`
     * calls below.
     *
     * Item 16I: converted to real DQL -- see that enum's own docblock
     * for why the earlier boolean-column-coercion reasoning was
     * reconsidered.
     *
     * @param list<UserId> $userIds
     * @param array<string, mixed> $updates
     */
    public function updateInfosForUsers(array $userIds, array $updates): void
    {
        if ($userIds === [] || $updates === []) {
            return;
        }

        $em = $this->em;
        $qb = $em->createQueryBuilder()
            ->update(UserInfoEntity::class, 'ui')
            ->where('ui.userId IN (:userIds)')
            ->setParameter('userIds', array_map(static fn (UserId $id): int => $id->value, $userIds), ArrayParameterType::INTEGER);

        foreach ($updates as $field => $value) {
            $fieldEnum = UserInfoField::fromToken($field);
            if ($fieldEnum === null) {
                throw new InvalidArgumentException("Unknown user_infos field: {$field}");
            }
            assert(is_scalar($value));
            [$property, $isBoolean] = $fieldEnum->dqlPropertyAndIsBoolean();
            $placeholder = 'v_' . $field;
            $boundValue = match (true) {
                $isBoolean => (bool) $value,
                $fieldEnum === UserInfoField::Language => LangCode::from((string) $value),
                default => $value,
            };
            $qb->set('ui.' . $property, ':' . $placeholder)
                ->setParameter($placeholder, $boundValue);
        }

        $qb->getQuery()
            ->execute();
        $em->clear();
    }

    /**
     * Single-row `users` table update -- UserService::checkAndSaveUserInfos()'s
     * account-field branch (username/email/password, whichever changed).
     * Confirmed at both real callers: never more than these 3 fields, each
     * independently optional.
     *
     * SQL-modernization audit, Item 14 Sub-phase C4: converted to real
     * DQL (`find()` + mutate + `flush()`) -- `users` is now mapped
     * ({@see UserEntity}); this used to take `$updates`/`$idColumn` as
     * dynamic column-name-and-value pairs (see this class's own
     * docblock).
     */
    public function updateAccountFields(UserId $userId, ?Username $username, ?string $password, ?Email $mailAddress): void
    {
        if ($username === null && $password === null && $mailAddress === null) {
            return;
        }

        $em = $this->em;
        $entity = $em->find(UserEntity::class, $userId);
        if ($entity === null) {
            return;
        }

        if ($username !== null) {
            $entity->username = $username;
        }

        if ($password !== null) {
            $entity->password = $password;
        }

        if ($mailAddress !== null) {
            $entity->mailAddress = $mailAddress;
        }

        $em->flush();
    }

    /**
     * The earliest non-null `registration_date` among every user --
     * Admin\PhotosAddDirectPageRenderer's own "how old is this install"
     * check for the mobile-app promotion banner.
     *
     * Item 14 DQL audit: converted to real DQL -- single-table, static
     * column/property, plain string `registrationDate` (no custom-type
     * hydration concern). setMaxResults(1) reproduces the original's LIMIT
     * 1.
     */
    public function findEarliestRegistrationDate(): ?string
    {
        $rows = $this->em->getRepository(UserInfoEntity::class)->createQueryBuilder('ui')
            ->select('ui.registrationDate')
            ->where('ui.registrationDate IS NOT NULL')
            ->orderBy('ui.userId', 'ASC')
            ->setMaxResults(1)
            ->getQuery()
            ->getSingleColumnResult();

        $value = $rows[0] ?? null;

        return is_string($value) ? $value : null;
    }

    /**
     * Every distinct `theme` value's user count -- Admin\
     * PiwigoInfosSender's own telemetry theme-usage breakdown.
     *
     * Item 14 DQL audit: converted to real DQL -- single-table, static
     * column/property, plain string `theme` (no custom-type hydration
     * concern).
     *
     * @return array<string, int> keyed by theme
     */
    public function findThemeUsageCounts(): array
    {
        $rows = $this->em->getRepository(UserInfoEntity::class)->createQueryBuilder('ui')
            ->select('ui.theme', 'COUNT(ui.userId) AS themeCounter')
            ->groupBy('ui.theme')
            ->orderBy('ui.theme')
            ->getQuery()
            ->getResult();

        $byTheme = [];
        foreach ($rows as $row) {
            $byTheme[$row['theme']] = $row['themeCounter'];
        }

        return $byTheme;
    }

    /**
     * Every distinct `language` value's user count -- Admin\
     * PiwigoInfosSender's own telemetry language-usage breakdown.
     *
     * Item 14 DQL audit: converted to real DQL -- single-table, static
     * column/property.
     *
     * UserInfoEntity::$language is LangCode-typed -- `ui.language`
     * array-hydrates as a LangCode instance, not a raw string; using it
     * directly as an array key would throw ("Illegal offset type"), so
     * this unwraps ->value explicitly.
     *
     * @return array<string, int> keyed by language
     */
    public function findLanguageUsageCounts(): array
    {
        $rows = $this->em->getRepository(UserInfoEntity::class)->createQueryBuilder('ui')
            ->select('ui.language', 'COUNT(ui.userId) AS languageCounter')
            ->groupBy('ui.language')
            ->orderBy('ui.language')
            ->getQuery()
            ->getResult();

        $byLanguage = [];
        foreach ($rows as $row) {
            if (! $row['language'] instanceof LangCode) {
                continue;
            }
            $byLanguage[$row['language']->value] = $row['languageCounter'];
        }

        return $byLanguage;
    }

    /**
     * Distinct "YYYY-MM" registration months among every user --
     * Admin\UserListPageRenderer's own registration-date filter dropdown.
     *
     * SQL-modernization audit, Item 14 Sub-phase B5 Tier 2: converted to
     * real DQL -- MySQL's `month()`/`year()` have no portable DQL
     * equivalent, and DISTINCT over both computed columns has the same
     * "can't express the grouping in DQL" shape as a GROUP BY alias.
     * Fetches `registrationDate` per row instead and computes the distinct
     * set in PHP -- an admin-only dropdown filter, not a hot path.
     * `registrationDate` is a `Y-m-d H:i:s` string
     * (`UserInfoEntity`'s own `length: 19`), so slicing it directly
     * reproduces `MONTH()`/`YEAR()`'s output exactly, already zero-padded.
     * A null `registrationDate` (real for some users --
     * {@see findEarliestRegistrationDate()}'s own docblock) reproduces the
     * original's own `MONTH(NULL)`/`YEAR(NULL)` -> `'-00'` formatting
     * exactly (confirmed against `16.x-rewrite`'s reference
     * `findRegistrationMonthsYears()` -- same unfiltered-null behavior
     * there, not something to correct here).
     *
     * @return list<string>
     */
    public function findDistinctRegistrationYearMonths(): array
    {
        $rows = $this->em->getRepository(UserInfoEntity::class)->createQueryBuilder('ui')
            ->select('ui.registrationDate AS registration_date')
            ->getQuery()
            ->getArrayResult();

        $yearMonths = [];
        foreach ($rows as $row) {
            if (! is_array($row)) {
                continue;
            }

            $registrationDate = $row['registration_date'] ?? null;
            $yearMonth = is_string($registrationDate)
                ? substr($registrationDate, 0, 4) . '-' . substr($registrationDate, 5, 2)
                : '-00';

            $yearMonths[$yearMonth] = true;
        }

        $result = array_keys($yearMonths);
        sort($result);

        return $result;
    }

    /**
     * Per-status user counts, excluding $excludeUserId (the guest user) --
     * Admin\UserListPageRenderer's own status-filter counters. `status` is
     * `UserStatus` (enumType-mapped) -- array hydration returns a real
     * UserStatus instance per row, unwrapped to `.value` for the array key
     * (a PHP array key can't be an object).
     *
     * @return array<string, int> keyed by status
     */
    public function findUserCountsByStatus(int $excludeUserId): array
    {
        $rows = $this->em->getRepository(UserInfoEntity::class)->createQueryBuilder('ui')
            ->select('ui.status', 'COUNT(ui.userId) AS nbUsersOf')
            ->where('ui.userId != :excludeUserId')
            ->groupBy('ui.status')
            ->setParameter('excludeUserId', $excludeUserId, ParameterType::INTEGER)
            ->getQuery()
            ->getResult();

        $byStatus = [];
        foreach ($rows as $row) {
            $byStatus[$row['status']->value] = $row['nbUsersOf'];
        }

        return $byStatus;
    }

    /**
     * Per-level user counts, excluding $excludeUserId (the guest user) --
     * Admin\UserListPageRenderer's own level-filter counters.
     *
     * Item 14 DQL audit: converted to real DQL -- single-table, static
     * column/property, plain smallint `level` (no custom-type hydration
     * concern).
     *
     * @return array<int, int> keyed by level
     */
    public function findUserCountsByLevel(int $excludeUserId): array
    {
        $rows = $this->em->getRepository(UserInfoEntity::class)->createQueryBuilder('ui')
            ->select('ui.level', 'COUNT(ui.userId) AS nbUsersOf')
            ->where('ui.userId != :excludeUserId')
            ->groupBy('ui.level')
            ->setParameter('excludeUserId', $excludeUserId, ParameterType::INTEGER)
            ->getQuery()
            ->getResult();

        $byLevel = [];
        foreach ($rows as $row) {
            $byLevel[$row['level']] = $row['nbUsersOf'];
        }

        return $byLevel;
    }

    /**
     * The condition list shared by findListForWs() below -- a `1=1` base
     * plus one condition per non-null UserListCriteria field, mirroring
     * Ws\PwgUsers::getList()'s own original "appended only when its
     * corresponding $params key is present" chain.
     */
    private static function buildListForWsCondition(UserListCriteria $criteria): SqlCondition
    {
        $conditions = [];

        if ($criteria->userId !== null) {
            $conditions[] = new SqlCondition('u.id IN (:userId)', [
                'userId' => array_map(static fn (UserId $userId): int => $userId->value, $criteria->userId),
            ], [
                'userId' => ArrayParameterType::INTEGER,
            ]);
        }

        if ($criteria->username !== null) {
            $conditions[] = new SqlCondition('u.username LIKE :username', [
                'username' => $criteria->username,
            ]);
        }

        if ($criteria->filter !== null) {
            $filterClause = '(u.username LIKE :filterLike OR u.mail_address LIKE :filterLike';
            $filterParams = [
                'filterLike' => '%' . $criteria->filter . '%',
            ];
            $filterTypes = [];

            if ($criteria->filteredGroupIds !== null && $criteria->filteredGroupIds !== []) {
                $filterClause .= ' OR ug.group_id IN (:filteredGroups)';
                $filterParams['filteredGroups'] = $criteria->filteredGroupIds;
                $filterTypes['filteredGroups'] = ArrayParameterType::INTEGER;
            }

            $conditions[] = new SqlCondition($filterClause . ')', $filterParams, $filterTypes);
        }

        if ($criteria->minRegister !== null) {
            $conditions[] = new SqlCondition('ui.registration_date >= :minRegister', [
                'minRegister' => $criteria->minRegister,
            ]);
        }

        if ($criteria->maxRegister !== null) {
            $conditions[] = new SqlCondition('ui.registration_date <= :maxRegister', [
                'maxRegister' => $criteria->maxRegister,
            ]);
        }

        if ($criteria->status !== null) {
            $conditions[] = new SqlCondition('ui.status IN (:status)', [
                'status' => $criteria->status,
            ], [
                'status' => ArrayParameterType::STRING,
            ]);
        }

        if ($criteria->minLevel !== null) {
            $conditions[] = new SqlCondition('ui.level >= :minLevel', [
                'minLevel' => $criteria->minLevel,
            ], [
                'minLevel' => ParameterType::INTEGER,
            ]);
        }

        if ($criteria->maxLevel !== null) {
            $conditions[] = new SqlCondition('ui.level <= :maxLevel', [
                'maxLevel' => $criteria->maxLevel,
            ], [
                'maxLevel' => ParameterType::INTEGER,
            ]);
        }

        if ($criteria->groupId !== null) {
            $conditions[] = new SqlCondition('ug.group_id IN (:groupId)', [
                'groupId' => $criteria->groupId,
            ], [
                'groupId' => ArrayParameterType::INTEGER,
            ]);
        }

        if ($criteria->exclude !== null) {
            $conditions[] = new SqlCondition('u.id NOT IN (:exclude)', [
                'exclude' => $criteria->exclude,
            ], [
                'exclude' => ArrayParameterType::INTEGER,
            ]);
        }

        return SqlCondition::combine('AND', new SqlCondition('1=1'), ...$conditions);
    }

    /**
     * Ws\PwgUsers::getList()'s own paginated, dynamically-columned user
     * listing; see buildListForWsCondition() above for how each criteria
     * field maps to its own WHERE condition. $displayColumns is the
     * already-built `field expr => alias` map (always includes at least
     * `u.<idColumn> => id`), matching the WS method's client-controlled
     * `display` param -- still caller-composed, since it shapes SELECT
     * columns, not a WHERE condition. $orderBy concatenates directly into
     * ORDER BY -- caller must validate this first (the WS method's own
     * ValidationPattern::ORDER check), same contract as
     * {@see \Piwigo\Comment\CommentRepository::findForImage()}'s own
     * $order. The total count is only fetched when $includeTotalCount.
     * LIMIT/OFFSET are only applied when $limit !== null.
     *
     * Stays on raw DBAL, not DQL -- `$displayColumns` is a dynamic
     * field-expression => alias map, `$orderBy` concatenates a
     * caller-validated raw fragment, and the WHERE clause comes from
     * buildListForWsCondition() above's SqlCondition-building machinery.
     *
     * Groups by `u.id` rather than using `SELECT DISTINCT`: `u.id` (the
     * `users` table's own primary key) is exactly the dedup key this
     * query needs -- every column it projects is functionally dependent
     * on it (`u.*`/`ui.*`, joined 1:1 via `ui.user_id = u.id`), and the
     * only real source of row multiplication is the `LEFT JOIN
     * user_group` (one row per group membership), never itself part of
     * the SELECT list. `GROUP BY`'s functional-dependency exception,
     * unlike `DISTINCT`'s strict same-row-literal requirement, also
     * permits the `ORDER BY LOWER(username)`-style expressions
     * Ws\PwgUsers::getList() can build, which aren't literally present in
     * the SELECT list. The total count uses a separate `COUNT(DISTINCT
     * u.id)` query rather than a window function or
     * `SQL_CALC_FOUND_ROWS`/`FOUND_ROWS()` (MySQL-only, no Postgres
     * equivalent, and a window function would execute logically before
     * `DISTINCT`/`GROUP BY`, over-counting the pre-deduplication rows).
     *
     * PostgreSQL's `GROUP BY` functional-dependency rule only ever
     * recognizes a table's *own* primary key, never transitively through
     * a join the way MySQL's optimizer does -- so every `ui.*` column
     * $displayColumns can carry is wrapped in `ANY_VALUE()` below. This is
     * genuinely correct, not just error-quieting: `ui` is a real
     * one-row-per-user INNER JOIN (`ui.user_id` is user_infos' own
     * primary key), so `ANY_VALUE()` only ever has exactly one real
     * candidate value to return, same reasoning as
     * {@see \Piwigo\Comment\CommentRepository::findAllWithConditions()}'s
     * own `ANY_VALUE(u.mail_address)`.
     *
     * @param  array<string, string>  $displayColumns
     * @return PaginatedResult<array<string, mixed>>
     */
    public function findListForWs(
        array $displayColumns,
        bool $includeLastVisitFromHistory,
        UserListCriteria $criteria,
        string $orderBy,
        bool $includeTotalCount,
        ?int $limit,
        int $offset
    ): PaginatedResult {
        $conn = $this->em
            ->getConnection();

        $usersTable = Tables::users();
        $userInfosTable = Tables::userInfos();
        $userGroupTable = Tables::userGroup();

        $columnPairs = [];
        foreach ($displayColumns as $field => $alias) {
            $columnPairs[] = (str_starts_with($field, 'u.') ? $field : 'ANY_VALUE(' . $field . ')') . ' AS ' . $alias;
        }
        if ($includeLastVisitFromHistory) {
            $columnPairs[] = 'ANY_VALUE(ui.last_visit_from_history) AS last_visit_from_history';
        }
        $columnsSql = implode(', ', $columnPairs);

        $combined = self::buildListForWsCondition($criteria);
        $params = $combined->parameters;
        $types = $combined->types;

        $joinSql = <<<SQL
            FROM {$usersTable} AS u
                INNER JOIN {$userInfosTable} AS ui
                    ON u.id = ui.user_id
                LEFT JOIN {$userGroupTable} AS ug
                    ON u.id = ug.user_id
            WHERE
                {$combined->sql}
            SQL;

        $total = null;
        if ($includeTotalCount) {
            $totalRaw = $conn->fetchOne(
                "SELECT COUNT(DISTINCT u.id)\n{$joinSql}",
                $params,
                $types
            );
            $total = is_numeric($totalRaw) ? (int) $totalRaw : 0;
        }

        $sql = <<<SQL
            SELECT {$columnsSql}
            {$joinSql}
            GROUP BY u.id
            ORDER BY {$orderBy}
            SQL;

        if ($limit !== null) {
            $sql .= <<<SQL

                    LIMIT :limit
                    OFFSET :offset;

                SQL;
            $params['limit'] = $limit;
            $params['offset'] = $offset;
            $types['limit'] = ParameterType::INTEGER;
            $types['offset'] = ParameterType::INTEGER;
        }

        $sql .= ';';

        $rows = $conn->fetchAllAssociative($sql, $params, $types);

        return new PaginatedResult($rows, $total);
    }

    /**
     * user_id for every `user_infos` row NOT matching $excludedStatus --
     * Admin\AlbumNotificationPageRenderer's own "every non-guest user"
     * pool, further intersected with album-access ids for private albums.
     *
     * Item 14 DQL audit: converted to real DQL -- single-table, static
     * column/property. getSingleColumnResult() uses Doctrine's
     * HYDRATE_SCALAR_COLUMN mode (ScalarColumnHydrator), which does a raw
     * Statement::fetchFirstColumn() with NO per-field Type conversion at
     * all -- unlike getArrayResult()/getResult(), it does NOT hydrate
     * ui.userId into a UserId VO despite the custom `user_id` Doctrine
     * Type, so this reads the raw numeric-string/int directly.
     *
     * @return list<string>
     */
    public function findUserIdsExcludingStatus(string $excludedStatus): array
    {
        $rows = $this->em->getRepository(UserInfoEntity::class)->createQueryBuilder('ui')
            ->select('ui.userId')
            ->where('ui.status != :status')
            ->setParameter('status', $excludedStatus)
            ->getQuery()
            ->getSingleColumnResult();

        $ids = [];
        foreach ($rows as $row) {
            if (! is_numeric($row)) {
                continue;
            }

            $ids[] = (string) (int) $row;
        }

        return $ids;
    }

    /**
     * user_id/status/language, plus email/username (JOINed from `users`,
     * matching column mapping) for $userIds -- Admin\
     * AlbumNotificationPageRenderer's own "notify these specific users"
     * mail-merge data.
     *
     * SQL-modernization audit, Item 14 Sub-phase C4: converted to real
     * DQL -- `users` is now mapped ({@see UserEntity}); the multi-auth
     * column indirection this used to take as `$idColumn`/
     * `$usernameColumn`/`$emailColumn` parameters is gone (see this
     * class's own docblock). Rows carry `user_id` as a raw int, not a
     * UserId VO -- unwrapped explicitly after hydration (this audit's own
     * gotcha #4 territory: array hydration WOULD apply `ui.userId`'s
     * custom Type, but every real consumer of this row shape expects a
     * plain scalar there).
     *
     * @param  list<int|string>  $userIds
     * @return list<array<string, mixed>>
     */
    public function findNotificationRecipientsByIds(array $userIds): array
    {
        if ($userIds === []) {
            return [];
        }

        $rows = $this->em
            ->createQueryBuilder()
            ->select('ui.userId AS user_id', 'ui.status AS status', 'ui.language AS language', 'u.mailAddress AS email', 'u.username AS username')
            ->from(UserInfoEntity::class, 'ui')
            ->innerJoin(UserEntity::class, 'u', Join::WITH, 'u.id = ui.userId')
            ->where('ui.userId IN (:userIds)')
            ->setParameter('userIds', array_map(static fn (int|string $id): int => (int) $id, $userIds), ArrayParameterType::INTEGER)
            ->getQuery()
            ->getArrayResult();

        $result = [];
        foreach ($rows as $row) {
            if (! is_array($row)) {
                continue;
            }

            $userId = $row['user_id'] ?? null;

            $result[] = [
                'user_id' => $userId instanceof UserId ? $userId->value : null,
                // `status` is UserStatus (enumType-mapped) -- array
                // hydration returns a real instance, unwrapped to
                // `.value` here.
                'status' => ($row['status'] ?? null) instanceof UserStatus ? $row['status']->value : null,
                // `language` is LangCode-typed -- array hydration returns
                // a real instance, unwrapped to `.value` here, same
                // reasoning as `status`.
                'language' => ($row['language'] ?? null) instanceof LangCode ? $row['language']->value : null,
                'email' => ($row['email'] ?? null) instanceof Email ? $row['email']->value : null,
                'username' => ($row['username'] ?? null) instanceof Username ? $row['username']->value : null,
            ];
        }

        return $result;
    }

    /**
     * Username + id rows for $userIds -- Admin\BatchManagerUnitPageRenderer's
     * own "who uploaded each of these photos" lookup.
     *
     * SQL-modernization audit, Item 14 Sub-phase C4: converted to real
     * DQL -- `users` is now mapped ({@see UserEntity}); the multi-auth
     * column indirection this used to take as a `$userFields` parameter
     * is gone (see this class's own docblock). Rows carry `id` as a raw
     * int, not a UserId VO -- same gotcha #4 reasoning as
     * {@see findNotificationRecipientsByIds()} above.
     *
     * @param list<string> $userIds
     * @return list<array<string, mixed>>
     */
    public function findUsernamesByIds(array $userIds): array
    {
        if ($userIds === []) {
            return [];
        }

        $rows = $this->em
            ->createQueryBuilder()
            ->select('u.username AS username', 'u.id AS id')
            ->from(UserEntity::class, 'u')
            ->where('u.id IN (:userIds)')
            ->setParameter('userIds', array_map(static fn (string $id): int => (int) $id, $userIds), ArrayParameterType::INTEGER)
            ->getQuery()
            ->getArrayResult();

        $result = [];
        foreach ($rows as $row) {
            if (! is_array($row)) {
                continue;
            }

            $id = $row['id'] ?? null;
            $username = $row['username'] ?? null;

            $result[] = [
                'username' => $username instanceof Username ? $username->value : null,
                'id' => $id instanceof UserId ? $id->value : null,
            ];
        }

        return $result;
    }

    /**
     * registration_date for a single user id -- Admin\InstallationStats::
     * getInstallationDate()'s own "when was the first real (non-guest,
     * non-default) user, id 2, registered" candidate.
     *
     * Item 14 DQL audit: converted to real DQL -- a `user_infos` lookup by
     * its own primary key is exactly `$this->find()`'s contract; `$userId`
     * stays a plain int in this method's own signature (its one real
     * caller passes the literal id 2), so an invalid/non-positive value is
     * turned into "no such row" via UserId::tryFrom() rather than letting
     * UserId::from()'s own exception change this method's "not found ->
     * null" behavior.
     */
    public function findRegistrationDateById(int $userId): ?string
    {
        $id = UserId::tryFrom($userId);
        if ($id === null) {
            return null;
        }

        return $this->find($id)?->registrationDate;
    }

    /**
     * Earliest registration_date after $afterDate -- Admin\
     * InstallationStats::getInstallationDate()'s own fallback candidate
     * when user id 2's own registration_date predates Piwigo's own
     * "origin of times".
     *
     * Item 14 DQL audit: converted to real DQL -- single-table, static
     * column/property. MIN() is a standard DQL function, and an unGROUPed
     * aggregate always returns exactly one row (never NonUniqueResultException
     * territory), so getSingleScalarResult() is safe here.
     */
    public function findMinRegistrationDateAfter(string $afterDate): ?string
    {
        $value = $this->em->getRepository(UserInfoEntity::class)->createQueryBuilder('ui')
            ->select('MIN(ui.registrationDate)')
            ->where('ui.registrationDate > :afterDate')
            ->setParameter('afterDate', $afterDate)
            ->getQuery()
            ->getSingleScalarResult();

        return is_string($value) ? $value : null;
    }

    /**
     * Total row count of `users` -- Admin\UserActivityPageRenderer's own
     * "nb_users" summary figure.
     *
     * SQL-modernization audit, Item 14 Sub-phase C4: converted to real
     * DQL -- `users` is now mapped ({@see UserEntity}).
     */
    public function countAllUsers(): int
    {
        $value = $this->em
            ->createQueryBuilder()
            ->select('COUNT(u.id)')
            ->from(UserEntity::class, 'u')
            ->getQuery()
            ->getSingleScalarResult();

        return is_numeric($value) ? (int) $value : 0;
    }

    /**
     * status keyed by id for $ids -- Admin\Integrity\C13yInternal::
     * c13y_user()'s own "does the guest/default/webmaster user exist, with
     * the right status" check. A user present in `users` but missing its
     * `user_infos` row (the LEFT JOIN's own "no such row" case) still
     * appears here, with a null status.
     *
     * SQL-modernization audit, Item 14 Sub-phase C4: converted to real
     * DQL -- `users` is now mapped ({@see UserEntity}); the multi-auth
     * column indirection this used to take as a `$idColumn` parameter is
     * gone (see this class's own docblock).
     *
     * @param  list<int|string>  $ids
     * @return array<int|string, ?string>
     */
    public function findStatusByIds(array $ids): array
    {
        if ($ids === []) {
            return [];
        }

        $rows = $this->em
            ->createQueryBuilder()
            ->select('u.id AS id', 'ui.status AS status')
            ->from(UserEntity::class, 'u')
            ->leftJoin(UserInfoEntity::class, 'ui', Join::WITH, 'u.id = ui.userId')
            ->where('u.id IN (:ids)')
            ->setParameter('ids', array_map(static fn (int|string $id): int => (int) $id, $ids), ArrayParameterType::INTEGER)
            ->getQuery()
            ->getArrayResult();

        $byId = [];
        foreach ($rows as $row) {
            if (! is_array($row)) {
                continue;
            }

            $id = $row['id'] ?? null;
            if (! $id instanceof UserId) {
                continue;
            }

            // `status` is UserStatus (enumType-mapped) -- array hydration
            // returns a real instance, unwrapped to `.value` here.
            $byId[$id->value] = ($row['status'] ?? null) instanceof UserStatus ? $row['status']->value : null;
        }

        return $byId;
    }

    /**
     * Every `user_infos` row with a non-expired activation key --
     * Controller\PasswordController::checkPasswordResetKey()'s own reset-
     * key scan.
     *
     * Item 14 DQL audit: converted to real DQL -- single-table, static
     * columns/properties. DQL's CURRENT_TIMESTAMP() compiles to MySQL's
     * NOW() (Doctrine\DBAL\Platforms\MySQLPlatform::getCurrentTimestampSQL()),
     * same comparison as the original raw `activation_key_expire > NOW()`
     * against this still-plain-string column. `ui.userId` maps through the
     * `user_id` custom Doctrine Type, so getArrayResult() hydrates it as a
     * UserId value object, not a raw scalar -- per the Item 14 gotcha, this
     * builds ActivationKeyRow directly with an instanceof check instead of
     * reusing ActivationKeyRow::fromRow() (which does a raw-DBAL-shaped
     * UserId::tryFrom($row['user_id']) check that would silently treat a
     * UserId object as invalid and throw).
     *
     * @return list<ActivationKeyRow>
     */
    public function findPendingActivationKeyRows(): array
    {
        $rows = $this->em->getRepository(UserInfoEntity::class)->createQueryBuilder('ui')
            ->select('ui.userId', 'ui.status', 'ui.activationKey')
            ->where('ui.activationKey IS NOT NULL')
            ->andWhere('ui.activationKeyExpire > CURRENT_TIMESTAMP()')
            ->getQuery()
            ->getArrayResult();

        $result = [];
        foreach ($rows as $row) {
            if (! is_array($row) || ! $row['userId'] instanceof UserId) {
                continue;
            }

            $result[] = new ActivationKeyRow(
                userId: $row['userId'],
                // `status` is UserStatus (enumType-mapped) -- array
                // hydration returns a real instance, unwrapped to
                // `.value` here.
                status: ($row['status'] ?? null) instanceof UserStatus ? $row['status']->value : '',
                activationKey: is_string($row['activationKey']) ? $row['activationKey'] : '',
            );
        }

        return $result;
    }

    /**
     * @param list<mixed> $values
     * @return list<int>
     */
    private static function toIntList(array $values): array
    {
        return array_map(
            static fn (mixed $value): int => is_numeric($value) ? (int) $value : 0,
            $values
        );
    }
}
