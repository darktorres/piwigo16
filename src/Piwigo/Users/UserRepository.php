<?php

declare(strict_types=1);

namespace Piwigo\Users;

use Doctrine\DBAL\ArrayParameterType;
use Doctrine\DBAL\ParameterType;
use Doctrine\DBAL\Query\QueryBuilder;
use Doctrine\ORM\EntityRepository;
use Doctrine\ORM\Query\Expr\Join;
use Piwigo\Auth\UserAuthKeyEntity;
use Piwigo\Category\UserAccessEntity;
use Piwigo\Common\Dto\PaginatedResult;
use Piwigo\Common\ValueObject\Email;
use Piwigo\Common\ValueObject\UserId;
use Piwigo\Common\ValueObject\Username;
use Piwigo\Core\ThemeEntity;
use Piwigo\Db\BatchWriter;
use Piwigo\Db\Tables;
use Piwigo\Event\Mail\GetWebmasterMailAddress;
use Piwigo\Group\UserGroupEntity;
use Piwigo\Permission\SqlCondition;
use Piwigo\Users\Projection\UserInfo;

/**
 * Persistence layer for the user domain's registration/lookup/preferences
 * slice: `users` (login/password/email), `user_infos` (profile row,
 * preferences), `user_group` (default-group assignment on registration).
 *
 * `user_infos` is ORM-mapped ({@see UserInfoEntity}). `users` is
 * deliberately NOT mapped by any entity -- every method touching it takes
 * its column names as caller-supplied parameters (\Piwigo\Config\
 * CurrentConfig::userFields(), Piwigo's multi-auth column remapping),
 * which Doctrine's compile-time column attributes cannot represent. Those
 * methods stay plain DBAL via $this->getEntityManager()->getConnection(),
 * same "not every table a repository touches gets an entity" principle
 * Group/Tag's own conversions already established.
 *
 * `build_user()`/`getuserdata()`/`check_user_favorites()` (the original
 * free-function names) ended up ported into
 * {@see \Piwigo\Users\UserService} instead of here, as OOP methods
 * (`buildUser()`/`getUserData()`/`checkUserFavorites()`) -- `user_cache`
 * itself no longer exists (deleted in gap-closure Stage 4g, see
 * {@see UserService::getUserData()}'s own comment), and the
 * Category-domain rollup they depended on (`get_computed_categories()`)
 * is now `CategoryService::getComputedCategories()`.
 *
 * @extends EntityRepository<UserInfoEntity>
 */
final class UserRepository extends EntityRepository implements \Piwigo\Core\WebmasterMailProviderInterface
{
    /**
     * Applies a permission/filter `SqlCondition` via `andWhere()`, binding
     * every one of its parameters -- same shared-helper shape as
     * `Image\ImageRepository::applyCondition()`/`Notification\
     * NotificationRepository::applyCondition()`/`Tag\TagRepository::
     * applyCondition()`.
     */
    private static function applyCondition(QueryBuilder $qb, SqlCondition $condition): void
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
     *
     * P23 batch 8f-4: implements Piwigo\Core\WebmasterMailProviderInterface
     * (MailService's test seam for this exact lookup -- see that
     * interface's own docblock); bound in config/container.php.
     *
     * P23 batch 8d: relocated from include/functions.inc.php's
     * get_webmaster_mail_address(), unchanged logic (including its
     * trigger_change('get_webmaster_mail_address', ...) filter hook and
     * its own \Piwigo\Config\CurrentConfig::userFields() column-name resolution) -- stays
     * zero-arg, matching the original's own signature exactly, so every
     * real call site retargets as a pure rename.
     *
     * Item 14 DQL audit: stays on DBAL -- `$id_field`/`$email_field` are
     * caller-resolved dynamic column names (\Piwigo\Config\CurrentConfig::
     * userFields()) against the never-entity-mapped `users` table (see this
     * class's own docblock); DQL needs a fixed property path.
     */
    #[\Override]
    public function getWebmasterMailAddress(): string
    {

        $user_fields = \Piwigo\Config\CurrentConfig::current()->userFields();
        $email_field = $user_fields['email'];
        $id_field = $user_fields['id'];
        $webmaster_id = \Piwigo\Config\CurrentConfig::current()->webmasterId();

        $value = $this->getEntityManager()
            ->getConnection()
            ->createQueryBuilder()
            ->select($email_field)
            ->from(Tables::users())
            ->where($id_field . ' = :webmasterId')
            ->setParameter('webmasterId', $webmaster_id)
            ->executeQuery()
            ->fetchOne();

        // users.email is nullable — a webmaster without an email set is real,
        // not a bug, so this degrades to '' rather than asserting non-null.
        $email = is_string($value) ? $value : '';

        return \Piwigo\PluginConfig\EventDispatcher::get()->dispatchChange(new GetWebmasterMailAddress($email))->email;
    }

    /**
     * Item 14 DQL audit: stays on DBAL -- `$idColumn`/`$usernameColumn` are
     * caller-supplied dynamic column names against the never-entity-mapped
     * `users` table.
     */
    public function findIdByUsername(Username $username, string $idColumn, string $usernameColumn): ?UserId
    {
        $value = $this->getEntityManager()
            ->getConnection()
            ->createQueryBuilder()
            ->select($idColumn)
            ->from(Tables::users())
            ->where($usernameColumn . ' = :username')
            ->setParameter('username', $username->value)
            ->executeQuery()
            ->fetchOne();

        return UserId::tryFrom($value);
    }

    /**
     * Item 14 DQL audit: stays on DBAL -- `$idColumn`/`$emailColumn` are
     * caller-supplied dynamic column names against the never-entity-mapped
     * `users` table.
     */
    public function findIdByEmail(Email $email, string $idColumn, string $emailColumn): ?UserId
    {
        $value = $this->getEntityManager()
            ->getConnection()
            ->createQueryBuilder()
            ->select($idColumn)
            ->from(Tables::users())
            ->where('UPPER(' . $emailColumn . ') = UPPER(:email)')
            ->setParameter('email', $email->value)
            ->executeQuery()
            ->fetchOne();

        return UserId::tryFrom($value);
    }

    /**
     * @return array{id: string, username: string, email: string}|null the
     *   account matching $login (case-insensitive) or, when none matches,
     *   the account matching $email -- used both to look up an existing
     *   account for the SEC-31 duplicate-registration notice, and for the
     *   password.php-style "find by username or email" flow.
     *
     * Item 14 DQL audit: stays on DBAL -- `$idColumn`/`$usernameColumn`/
     * `$emailColumn` are caller-supplied dynamic column names against the
     * never-entity-mapped `users` table.
     */
    public function findByUsernameCaseInsensitive(string $username, string $idColumn, string $usernameColumn, string $emailColumn): ?array
    {
        $row = $this->getEntityManager()
            ->getConnection()
            ->createQueryBuilder()
            ->select($idColumn . ' AS id', $usernameColumn . ' AS username', $emailColumn . ' AS email')
            ->from(Tables::users())
            ->where('LOWER(' . $usernameColumn . ') = LOWER(:username)')
            ->setParameter('username', $username)
            ->executeQuery()
            ->fetchAssociative();

        if ($row === false) {
            return null;
        }

        return [
            'id' => is_scalar($row['id']) ? (string) $row['id'] : '',
            'username' => is_string($row['username']) ? $row['username'] : '',
            'email' => is_string($row['email']) ? $row['email'] : '',
        ];
    }

    /**
     * Item 14 DQL audit: stays on DBAL -- `$usernameColumn` is a
     * caller-supplied dynamic column name against the never-entity-mapped
     * `users` table.
     */
    public function usernameExistsCaseInsensitive(Username $username, string $usernameColumn): bool
    {
        $value = $this->getEntityManager()
            ->getConnection()
            ->createQueryBuilder()
            ->select('COUNT(*)')
            ->from(Tables::users())
            ->where('LOWER(' . $usernameColumn . ') = LOWER(:username)')
            ->setParameter('username', $username->value)
            ->executeQuery()
            ->fetchOne();

        return is_numeric($value) && (int) $value > 0;
    }

    /**
     * Item 14 DQL audit: stays on DBAL -- `$emailColumn`/`$idColumn` are
     * caller-supplied dynamic column names against the never-entity-mapped
     * `users` table.
     */
    public function emailExists(Email $email, string $emailColumn, string $idColumn, ?UserId $excludeUserId): bool
    {
        $qb = $this->getEntityManager()
            ->getConnection()
            ->createQueryBuilder()
            ->select('COUNT(*)')
            ->from(Tables::users())
            ->where('UPPER(' . $emailColumn . ') = UPPER(:email)')
            ->setParameter('email', $email->value);

        if ($excludeUserId !== null) {
            $qb->andWhere($idColumn . ' != :excludeUserId')
                ->setParameter('excludeUserId', $excludeUserId->value);
        }

        $value = $qb->executeQuery()
            ->fetchOne();

        return is_numeric($value) && (int) $value > 0;
    }

    /**
     * Item 14 DQL audit: stays on DBAL -- `$idColumn`/`$usernameColumn` are
     * caller-supplied dynamic column names against the never-entity-mapped
     * `users` table.
     */
    public function findUsernameById(UserId $userId, string $idColumn, string $usernameColumn): ?Username
    {
        $value = $this->getEntityManager()
            ->getConnection()
            ->createQueryBuilder()
            ->select($usernameColumn)
            ->from(Tables::users())
            ->where($idColumn . ' = :id')
            ->setParameter('id', $userId->value)
            ->executeQuery()
            ->fetchOne();

        return Username::tryFrom($value);
    }

    /**
     * Item 14 DQL audit: stays on DBAL -- `$usernameColumn` is a
     * caller-supplied dynamic column name against the never-entity-mapped
     * `users` table.
     *
     * @return list<string>
     */
    public function findAllUsernames(string $usernameColumn): array
    {
        $names = $this->getEntityManager()
            ->getConnection()
            ->createQueryBuilder()
            ->select($usernameColumn . ' AS username')
            ->from(Tables::users())
            ->executeQuery()
            ->fetchFirstColumn();

        return array_map(
            static fn (mixed $name): string => is_scalar($name) ? (string) $name : '',
            $names
        );
    }

    /**
     * Every username keyed by id, both via caller-supplied dynamic column
     * names -- Admin\CatPermPageRenderer's own "list every user for the
     * groups/users permission form" lookup.
     *
     * Item 14 DQL audit: stays on DBAL -- both column names are dynamic
     * against the never-entity-mapped `users` table.
     *
     * @return array<int|string, mixed> keyed by id
     */
    public function findAllUsernamesById(string $idColumn, string $usernameColumn): array
    {
        return array_column($this->getEntityManager()
            ->getConnection()
            ->createQueryBuilder()
            ->select($idColumn . ' AS id', $usernameColumn . ' AS username')
            ->from(Tables::users())
            ->executeQuery()
            ->fetchAllAssociative(), 'username', 'id');
    }

    /**
     * @param array<string, mixed> $columns generic pwgfield => real DB
     *   column-name-and-value pairs (username/password/email), matching
     *   the original's \Piwigo\Config\CurrentConfig::userFields() mapping
     *
     * Item 14 DQL audit: stays on DBAL -- `$columns` carries dynamic
     * column names against the never-entity-mapped `users` table.
     */
    public function insertUser(array $columns): UserId
    {
        $conn = $this->getEntityManager()
            ->getConnection();

        $qb = $conn->createQueryBuilder()
            ->insert(Tables::users());
        $values = [];
        foreach (array_keys($columns) as $i => $column) {
            $placeholder = ':v' . $i;
            $values[$column] = $placeholder;
        }
        $qb->values($values);
        foreach (array_values($columns) as $i => $value) {
            $qb->setParameter('v' . $i, $value);
        }
        $qb->executeStatement();

        return UserId::from((int) $conn->lastInsertId());
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

        $em = $this->getEntityManager();
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
            status: is_string($row['status'] ?? null) ? $row['status'] : 'guest',
            language: is_string($row['language'] ?? null) ? $row['language'] : 'en_UK',
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
            lastmodified: is_string($row['lastmodified'] ?? null) ? $row['lastmodified'] : \Piwigo\Core\Env::now()->format('Y-m-d H:i:s'),
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

        $this->getEntityManager()
            ->flush();
    }

    /**
     * Ported from admin/include/functions.php's get_admins() (P23 batch
     * 8d), unchanged logic.
     *
     * @return list<UserId>
     */
    public function findAdminIds(bool $includeWebmaster = true): array
    {
        $statusList = ['admin'];
        if ($includeWebmaster) {
            $statusList[] = 'webmaster';
        }

        $rows = $this->createQueryBuilder('ui')
            ->select('ui.userId')
            ->where('ui.status IN (:statuses)')
            ->setParameter('statuses', $statusList)
            ->getQuery()
            ->getResult();

        return array_map(static function (array $row): UserId {
            if (! $row['userId'] instanceof UserId) {
                throw new \RuntimeException('Expected UserId from DQL scalar hydration of ui.userId');
            }

            return $row['userId'];
        }, $rows);
    }

    /**
     * Deletes a user's row across every table referencing it. Ported from
     * admin/include/functions.php's delete_user() (P23 batch 8d),
     * unchanged logic -- pure cascade delete, no business logic (session
     * purge / activity log stay in Piwigo\Users\UserService::deleteUser(),
     * which calls this).
     *
     * Item 14 DQL audit: `user_access`/`user_group`/`user_auth_keys` are
     * each entity-mapped (Category\UserAccessEntity, Group\UserGroupEntity,
     * Auth\UserAuthKeyEntity) and each entity's own docblock documents it
     * as having no single owning repository -- other repositories already
     * query/delete them directly via DQL through their own EntityManager
     * (e.g. Permission\PermissionRepository already deletes
     * UserAccessEntity this same way), so those three convert alongside
     * UserInfoEntity's pre-existing DQL delete below. `user_feed` IS
     * entity-mapped too (Feed\FeedEntity), but unlike the three above it
     * has a single documented owner (FeedRepository) with no existing
     * cross-domain DQL precedent, so it stays on raw DBAL to avoid a new
     * coupling. `user_mail_notification`/`favorites`/`caddie` have no
     * entity anywhere in this migration, and the final `users` row delete
     * needs a caller-supplied dynamic id column (multi-auth) -- both stay
     * DBAL too.
     */
    public function deleteUser(UserId $userId): void
    {
        $em = $this->getEntityManager();
        $conn = $em->getConnection();

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

        foreach ([Tables::userMailNotification(), Tables::userFeed(), Tables::favorites(), Tables::caddie()] as $table) {
            $conn->createQueryBuilder()
                ->delete($table)
                ->where('user_id = :userId')
                ->setParameter('userId', $userId->value)
                ->executeStatement();
        }

        $em->createQueryBuilder()
            ->delete(UserInfoEntity::class, 'ui')
            ->where('ui.userId = :userId')
            ->setParameter('userId', $userId)
            ->getQuery()
            ->execute();

        // Bypassed the ORM for user_mail_notification/user_feed/favorites/
        // caddie/users above -- any entity this EntityManager already
        // loaded for this user (UserInfoEntity, UserAccessEntity,
        // UserAuthKeyEntity, UserGroupEntity) would otherwise stay stale
        // (same identity-map reasoning as GroupRepository::delete()'s own
        // comment).
        $em->clear();

        $userFields = \Piwigo\Config\CurrentConfig::current()->userFields();
        $userIdField = $userFields['id'];
        $conn->createQueryBuilder()
            ->delete(Tables::users())
            ->where($userIdField . ' = :userId')
            ->setParameter('userId', $userId->value)
            ->executeStatement();
    }

    /**
     * Every user id from the base users table -- `$userIdColumn` matches
     * {@see deleteUser()}'s own `$conf['user_fields']['id']` multi-auth
     * compat column-name lookup (the caller passes it, this method never
     * reads `$conf` itself).
     *
     * Item 14 DQL audit: stays on DBAL -- `$userIdColumn` is a
     * caller-supplied dynamic column name against the never-entity-mapped
     * `users` table.
     *
     * @return list<UserId>
     */
    public function findAllUserIds(string $userIdColumn): array
    {
        return array_values(array_filter(array_map(static fn (mixed $v): ?UserId => UserId::tryFrom($v), $this->getEntityManager()
            ->getConnection()
            ->createQueryBuilder()
            ->select($userIdColumn . ' AS id')
            ->from(Tables::users())
            ->executeQuery()
            ->fetchFirstColumn())));
    }

    /**
     * Item 14 DQL audit: stays on DBAL -- `$table` is itself a
     * caller-supplied dynamic table name, not resolvable to a single
     * mapped entity at compile time.
     *
     * @return list<UserId>
     */
    public function findDistinctUserIdsInTable(string $table): array
    {
        return array_values(array_filter(array_map(static fn (mixed $v): ?UserId => UserId::tryFrom($v), $this->getEntityManager()
            ->getConnection()
            ->createQueryBuilder()
            ->select('DISTINCT user_id')
            ->from($table)
            ->executeQuery()
            ->fetchFirstColumn())));
    }

    /**
     * Item 14 DQL audit: stays on DBAL -- `$table` is itself a
     * caller-supplied dynamic table name, not resolvable to a single
     * mapped entity at compile time.
     *
     * @param list<UserId> $userIds
     */
    public function deleteUsersFromTable(string $table, array $userIds): void
    {
        if ($userIds === []) {
            return;
        }

        $em = $this->getEntityManager();
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
     * Basic `users`-table row for UserService::getUserData() -- `$userFields`
     * is \Piwigo\Config\CurrentConfig::userFields() (pwgfield => dbfield),
     * same dynamic-column-mapping reason `users` stays unmapped by any
     * entity (see this class's own docblock).
     *
     * Item 14 DQL audit: stays on DBAL -- `$userFields` carries dynamic
     * column names against the never-entity-mapped `users` table.
     *
     * @param array<string, string> $userFields
     * @return array<string, mixed>|false
     */
    public function fetchBasicUserRow(UserId $userId, array $userFields): array|false
    {
        $columnPairs = [];
        foreach ($userFields as $pwgfield => $dbfield) {
            $columnPairs[] = "{$dbfield} AS {$pwgfield}";
        }
        $idField = $userFields['id'];

        return $this->getEntityManager()
            ->getConnection()
            ->createQueryBuilder()
            ->select(...$columnPairs)
            ->from(Tables::users())
            ->where($idField . ' = :userId')
            ->setParameter('userId', $userId->value)
            ->executeQuery()
            ->fetchAssociative();
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
        $rows = $this->createQueryBuilder('ui')
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
     * Item 14 DQL audit, re-corrected: `themes` is now mapped ({@see
     * ThemeEntity}), but the real remaining blocker survives that fix --
     * this selects `ui.*` (every `user_infos` column keyed by its raw
     * snake_case column name, e.g. `nb_image_page`/`show_nb_comments`),
     * which UserService::getUserData() then array_merge()s straight into
     * its own `$userdata`. DQL has no whole-row-as-raw-column-names select;
     * selecting the full UserInfoEntity instead would key the array by its
     * camelCase property names (`nbImagePage`/`showNbComments`), silently
     * breaking that merge and every downstream `$userdata['...']` read.
     * Stays on DBAL.
     *
     * @return array<string, mixed>|false
     */
    public function fetchUserInfosWithThemeName(UserId $userId): array|false
    {
        return $this->getEntityManager()
            ->getConnection()
            ->createQueryBuilder()
            ->select('ui.*', 't.name AS theme_name')
            ->from(Tables::userInfos(), 'ui')
            ->leftJoin('ui', Tables::themes(), 't', 't.id = ui.theme')
            ->where('ui.user_id = :userId')
            ->setParameter('userId', $userId->value)
            ->executeQuery()
            ->fetchAssociative();
    }

    /**
     * Favorite image ids for $userId restricted to $condition --
     * UserService::checkUserFavorites()'s own "images still in an
     * authorized category" half of the comparison.
     *
     * SQL-modernization audit: $forbiddenCondition (raw
     * getSqlConditionFandF() output) replaced with a bound SqlCondition,
     * migrated together with this method's one real caller
     * (UserService::checkUserFavorites()).
     *
     * Item 14 DQL audit: stays on DBAL -- joins `image_category`, which has
     * no entity anywhere in this migration (see Category\
     * CategoryRepository's own class docblock), and applies a caller-built
     * SqlCondition raw fragment via the shared applyCondition() helper
     * above (used by this method and findVisibleFavoriteImageIds()/
     * findVisibleFavoriteImages() below; converting one without the
     * others would split that shared machinery in two).
     *
     * @return list<int>
     */
    public function findAuthorizedFavoriteImageIds(UserId $userId, SqlCondition $condition): array
    {
        $qb = $this->getEntityManager()
            ->getConnection()
            ->createQueryBuilder()
            ->select('DISTINCT f.image_id')
            ->from(Tables::favorites(), 'f')
            ->innerJoin('f', Tables::imageCategory(), 'ic', 'f.image_id = ic.image_id')
            ->where('f.user_id = :userId')
            ->setParameter('userId', $userId->value);

        self::applyCondition($qb, $condition);

        return self::toIntList(array_column($qb->executeQuery()->fetchAllAssociative(), 'image_id'));
    }

    /**
     * Every favorite image id for $userId, unfiltered -- the other half of
     * UserService::checkUserFavorites()'s comparison.
     *
     * Item 14 DQL audit, re-corrected: `favorites` is now mapped ({@see
     * FavoriteEntity}). Converted to real DQL -- single-table select of
     * `f.imageId`, a plain int property (no custom Doctrine Type on
     * FavoriteEntity's imageId, so `getSingleColumnResult()`'s lack of Type
     * conversion -- see this class's own Item 14 gotcha note elsewhere in
     * this file -- doesn't apply here).
     *
     * @return list<int>
     */
    public function findFavoriteImageIds(UserId $userId): array
    {
        $rows = $this->getEntityManager()
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

        $this->getEntityManager()
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
        new BatchWriter($this->getEntityManager()->getConnection())
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
        $value = $this->getEntityManager()
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
        $this->getEntityManager()
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
     * SQL-modernization audit: $permissionCondition (raw
     * getSqlConditionFandF() output) replaced with a bound SqlCondition,
     * migrated together with this method's one real caller
     * (Section\SectionPopulator.php). $orderBySql stays a raw fragment
     * (CurrentConfig::orderBy(), trusted internal config, same "caller
     * composes trusted fragments" contract used throughout this codebase).
     *
     * Item 14 DQL audit: stays on DBAL -- `favorites` has no entity
     * anywhere in this migration, $condition is a caller-built raw SQL
     * fragment (same shared-family reasoning as
     * findAuthorizedFavoriteImageIds() above), and $orderBySql concatenates
     * a caller-composed raw ORDER BY fragment directly.
     *
     * @return list<string|null>
     */
    public function findVisibleFavoriteImageIds(UserId $userId, SqlCondition $condition, string $orderBySql): array
    {
        $favoritesTable = Tables::favorites();
        $imagesTable = Tables::images();

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

        $rows = $this->getEntityManager()
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
     * Every column of every favorite image for $userId matching
     * $condition -- Ws\PwgUsers::favoritesGetList()'s own full row
     * listing, a different contract from
     * {@see findVisibleFavoriteImageIds()} above (that one is
     * `image_id`-only, this one is `i.*`).
     *
     * SQL-modernization audit: $permissionCondition replaced with a bound
     * SqlCondition, migrated together with this method's one real caller
     * (Ws\PwgUsers::favoritesGetList()). $orderBySql stays a raw fragment,
     * same reasoning as findVisibleFavoriteImageIds() above.
     *
     * Item 14 DQL audit: stays on DBAL -- `favorites` has no entity
     * anywhere in this migration, $condition is a caller-built raw SQL
     * fragment (same shared family as findAuthorizedFavoriteImageIds()/
     * findVisibleFavoriteImageIds() above), $orderBySql concatenates a
     * caller-composed raw fragment, and it selects `i.*` (a whole-row
     * shape, not a fixed DQL property list).
     *
     * @return list<array<string, mixed>>
     */
    public function findVisibleFavoriteImages(UserId $userId, SqlCondition $condition, string $orderBySql): array
    {
        $favoritesTable = Tables::favorites();
        $imagesTable = Tables::images();

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

        return $this->getEntityManager()
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

        $this->getEntityManager()
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
     * Item 14 DQL audit: stays on DBAL -- `$updates`'s keys are dynamic
     * column names (the set() calls in the loop below), which DQL requires
     * as fixed property paths.
     *
     * @param list<UserId> $userIds
     * @param array<string, mixed> $updates
     */
    public function updateInfosForUsers(array $userIds, array $updates): void
    {
        if ($userIds === [] || $updates === []) {
            return;
        }

        $qb = $this->getEntityManager()
            ->getConnection()
            ->createQueryBuilder()
            ->update(Tables::userInfos())
            ->where('user_id IN (:userIds)')
            ->setParameter('userIds', array_map(static fn (UserId $id): int => $id->value, $userIds), ArrayParameterType::INTEGER);

        foreach ($updates as $field => $value) {
            assert(is_scalar($value));
            $placeholder = 'v_' . $field;
            $qb->set($field, ':' . $placeholder)
                ->setParameter($placeholder, $value);
        }

        $qb->executeStatement();
    }

    /**
     * Single-row `users` table update -- UserService::checkAndSaveUserInfos()'s
     * account-field branch (username/email/password, whichever changed).
     * $updates/$idColumn stay raw column => value / column-name, same
     * dynamic field-name-mapping reasoning as fetchBasicUserRow() above.
     *
     * Item 14 DQL audit: not a DQL-vs-DBAL question -- write via
     * BatchWriter; `$updates`/`$idColumn` are also dynamic column names
     * against the never-entity-mapped `users` table regardless.
     *
     * @param array<string, mixed> $updates
     */
    public function updateAccountFields(int $userId, string $idColumn, array $updates): void
    {
        if ($updates === []) {
            return;
        }

        new BatchWriter($this->getEntityManager()->getConnection())
            ->singleUpdate(Tables::users(), $updates, [
                $idColumn => $userId,
            ]);
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
        $rows = $this->createQueryBuilder('ui')
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
        $rows = $this->createQueryBuilder('ui')
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
     * column/property, plain string `language` (no custom-type hydration
     * concern).
     *
     * @return array<string, int> keyed by language
     */
    public function findLanguageUsageCounts(): array
    {
        $rows = $this->createQueryBuilder('ui')
            ->select('ui.language', 'COUNT(ui.userId) AS languageCounter')
            ->groupBy('ui.language')
            ->orderBy('ui.language')
            ->getQuery()
            ->getResult();

        $byLanguage = [];
        foreach ($rows as $row) {
            $byLanguage[$row['language']] = $row['languageCounter'];
        }

        return $byLanguage;
    }

    /**
     * Distinct "YYYY-MM" registration months among every user --
     * Admin\UserListPageRenderer's own registration-date filter dropdown.
     *
     * Item 14 DQL audit: stays on DBAL -- `month()`/`year()` are
     * MySQL-specific SQL functions with no built-in DQL equivalent (and no
     * custom DQL function is registered for either in this project).
     *
     * @return list<string>
     */
    public function findDistinctRegistrationYearMonths(): array
    {
        $userInfosTable = Tables::userInfos();

        $rows = $this->getEntityManager()
            ->getConnection()
            ->fetchAllAssociative(<<<SQL
                SELECT DISTINCT
                    month(registration_date) as registration_month,
                    year(registration_date) as registration_year
                FROM {$userInfosTable}
                ORDER BY registration_year, registration_month
                SQL);

        return array_map(
            static function (array $row): string {
                $registrationMonth = is_numeric($row['registration_month']) ? (int) $row['registration_month'] : 0;
                $registrationYear = is_scalar($row['registration_year']) ? (string) $row['registration_year'] : '';

                return $registrationYear . '-' . sprintf('%02u', $registrationMonth);
            },
            $rows
        );
    }

    /**
     * Per-status user counts, excluding $excludeUserId (the guest user) --
     * Admin\UserListPageRenderer's own status-filter counters.
     *
     * Item 14 DQL audit: converted to real DQL -- single-table, static
     * column/property, plain string `status` (no custom-type hydration
     * concern).
     *
     * @return array<string, int> keyed by status
     */
    public function findUserCountsByStatus(int $excludeUserId): array
    {
        $rows = $this->createQueryBuilder('ui')
            ->select('ui.status', 'COUNT(ui.userId) AS nbUsersOf')
            ->where('ui.userId != :excludeUserId')
            ->groupBy('ui.status')
            ->setParameter('excludeUserId', $excludeUserId, ParameterType::INTEGER)
            ->getQuery()
            ->getResult();

        $byStatus = [];
        foreach ($rows as $row) {
            $byStatus[$row['status']] = $row['nbUsersOf'];
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
        $rows = $this->createQueryBuilder('ui')
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
    private static function buildListForWsCondition(UserListCriteria $criteria, string $idColumn, string $usernameColumn, string $emailColumn): SqlCondition
    {
        $conditions = [];

        if ($criteria->userId !== null) {
            $conditions[] = new SqlCondition('u.' . $idColumn . ' IN (:userId)', [
                'userId' => $criteria->userId,
            ], [
                'userId' => ArrayParameterType::INTEGER,
            ]);
        }

        if ($criteria->username !== null) {
            $conditions[] = new SqlCondition('u.' . $usernameColumn . ' LIKE :username', [
                'username' => $criteria->username,
            ]);
        }

        if ($criteria->filter !== null) {
            $filterClause = '(u.' . $usernameColumn . ' LIKE :filterLike OR u.' . $emailColumn . ' LIKE :filterLike';
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
            $conditions[] = new SqlCondition('u.' . $idColumn . ' NOT IN (:exclude)', [
                'exclude' => $criteria->exclude,
            ], [
                'exclude' => ArrayParameterType::INTEGER,
            ]);
        }

        return SqlCondition::combine('AND', new SqlCondition('1=1'), ...$conditions);
    }

    /**
     * Further SQL-modernization audit, Item 13: Ws\PwgUsers::getList()'s
     * own paginated, dynamically-columned user listing -- $whereClauses (a
     * caller-built `list<string>` of trusted SQL fragments) replaced with
     * a typed UserListCriteria; see buildListForWsCondition() above for
     * how each field maps to its own condition. $displayColumns is the
     * already-built `field expr => alias` map (always includes at least
     * `u.<idColumn> => id`), matching the WS method's client-controlled
     * `display` param -- still caller-composed, since it shapes SELECT
     * columns, not a WHERE condition. $orderBy concatenates directly into
     * ORDER BY -- caller must validate this first (the WS method's own
     * ValidationPattern::ORDER check), same contract as
     * {@see \Piwigo\Comment\CommentRepository::findForImage()}'s own
     * $order. FOUND_ROWS() is only fetched when $includeTotalCount.
     * LIMIT/OFFSET are only applied when $limit !== null.
     *
     * Item 14 DQL audit: stays on DBAL -- joins the never-entity-mapped
     * `users` table via a caller-supplied dynamic `$idColumn`/
     * `$usernameColumn`/`$emailColumn`, `$displayColumns` is a dynamic
     * field-expression => alias map, `$orderBy` concatenates a
     * caller-validated raw fragment, `SQL_CALC_FOUND_ROWS`/`FOUND_ROWS()`
     * are MySQL-specific with no DQL equivalent, and the WHERE clause comes
     * from buildListForWsCondition() above's shared SqlCondition-building
     * machinery (also used standalone by no other method today, but kept
     * as raw SQL to stay consistent with that helper's own contract).
     *
     * @param  array<string, string>  $displayColumns
     * @return PaginatedResult<array<string, mixed>>
     */
    public function findListForWs(
        string $idColumn,
        string $usernameColumn,
        string $emailColumn,
        array $displayColumns,
        bool $includeLastVisitFromHistory,
        UserListCriteria $criteria,
        string $orderBy,
        bool $includeTotalCount,
        ?int $limit,
        int $offset
    ): PaginatedResult {
        $conn = $this->getEntityManager()
            ->getConnection();

        $usersTable = Tables::users();
        $userInfosTable = Tables::userInfos();
        $userGroupTable = Tables::userGroup();

        $columnPairs = [];
        foreach ($displayColumns as $field => $alias) {
            $columnPairs[] = $field . ' AS ' . $alias;
        }
        if ($includeLastVisitFromHistory) {
            $columnPairs[] = 'ui.last_visit_from_history AS last_visit_from_history';
        }
        $columnsSql = implode(', ', $columnPairs);
        $distinctPrefix = $includeTotalCount ? 'SQL_CALC_FOUND_ROWS ' : '';

        $combined = self::buildListForWsCondition($criteria, $idColumn, $usernameColumn, $emailColumn);
        $params = $combined->parameters;
        $types = $combined->types;

        $sql = <<<SQL
            SELECT DISTINCT {$distinctPrefix}{$columnsSql}
            FROM {$usersTable} AS u
                INNER JOIN {$userInfosTable} AS ui
                    ON u.{$idColumn} = ui.user_id
                LEFT JOIN {$userGroupTable} AS ug
                    ON u.{$idColumn} = ug.user_id
            WHERE
                {$combined->sql}
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

        $total = null;
        if ($includeTotalCount) {
            $totalRaw = $conn->fetchOne(<<<SQL
                SELECT FOUND_ROWS();
                SQL);
            $total = is_numeric($totalRaw) ? (int) $totalRaw : 0;
        }

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
        $rows = $this->createQueryBuilder('ui')
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
     * matching a dynamic $idColumn/$usernameColumn/$emailColumn mapping)
     * for $userIds -- Admin\AlbumNotificationPageRenderer's own
     * "notify these specific users" mail-merge data.
     *
     * Item 14 DQL audit: stays on DBAL -- INNER JOINs the never-entity-mapped
     * `users` table via a caller-supplied dynamic `$idColumn`/
     * `$usernameColumn`/`$emailColumn`.
     *
     * @param  list<int|string>  $userIds
     * @return list<array<string, mixed>>
     */
    public function findNotificationRecipientsByIds(array $userIds, string $idColumn, string $usernameColumn, string $emailColumn): array
    {
        if ($userIds === []) {
            return [];
        }

        return $this->getEntityManager()
            ->getConnection()
            ->createQueryBuilder()
            ->select('ui.user_id', 'ui.status', 'ui.language', 'u.' . $emailColumn . ' AS email', 'u.' . $usernameColumn . ' AS username')
            ->from(Tables::userInfos(), 'ui')
            ->innerJoin('ui', Tables::users(), 'u', 'u.' . $idColumn . ' = ui.user_id')
            ->where('ui.user_id IN (:userIds)')
            ->setParameter('userIds', array_map(strval(...), $userIds), ArrayParameterType::STRING)
            ->executeQuery()
            ->fetchAllAssociative();
    }

    /**
     * Username + id rows for $userIds, matching a dynamic $userFields
     * column mapping -- Admin\BatchManagerUnitPageRenderer's own
     * "who uploaded each of these photos" lookup, same dynamic-column
     * reasoning as fetchBasicUserRow() above.
     *
     * Item 14 DQL audit: stays on DBAL -- `$userFields` carries dynamic
     * column names against the never-entity-mapped `users` table.
     *
     * @param array<string, string> $userFields
     * @param list<string> $userIds
     * @return list<array<string, mixed>>
     */
    public function findUsernamesByIds(array $userFields, array $userIds): array
    {
        if ($userIds === []) {
            return [];
        }

        $usernameField = $userFields['username'];
        $idField = $userFields['id'];

        return $this->getEntityManager()
            ->getConnection()
            ->createQueryBuilder()
            ->select($usernameField . ' AS username', $idField . ' AS id')
            ->from(Tables::users())
            ->where($idField . ' IN (:userIds)')
            ->setParameter('userIds', array_map(strval(...), $userIds), ArrayParameterType::STRING)
            ->executeQuery()
            ->fetchAllAssociative();
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
        $value = $this->createQueryBuilder('ui')
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
     * Item 14 DQL audit: stays on DBAL -- `users` has no entity anywhere
     * in this migration (see this class's own docblock).
     */
    public function countAllUsers(): int
    {
        $usersTable = Tables::users();

        $value = $this->getEntityManager()
            ->getConnection()
            ->fetchOne(<<<SQL
                SELECT COUNT(*)
                FROM {$usersTable}
                SQL);

        return is_numeric($value) ? (int) $value : 0;
    }

    /**
     * status keyed by id for $ids -- Admin\Integrity\C13yInternal::
     * c13y_user()'s own "does the guest/default/webmaster user exist, with
     * the right status" check. A user present in `users` but missing its
     * `user_infos` row (the LEFT JOIN's own "no such row" case) still
     * appears here, with a null status.
     *
     * Item 14 DQL audit: stays on DBAL -- LEFT JOINs the never-entity-mapped
     * `users` table via a caller-supplied dynamic `$idColumn`.
     *
     * @param  list<int|string>  $ids
     * @return array<int|string, ?string>
     */
    public function findStatusByIds(string $idColumn, array $ids): array
    {
        if ($ids === []) {
            return [];
        }

        $rows = $this->getEntityManager()
            ->getConnection()
            ->createQueryBuilder()
            ->select('u.' . $idColumn . ' AS id', 'ui.status')
            ->from(Tables::users(), 'u')
            ->leftJoin('u', Tables::userInfos(), 'ui', 'u.' . $idColumn . ' = ui.user_id')
            ->where('u.' . $idColumn . ' IN (:ids)')
            ->setParameter('ids', array_map(strval(...), $ids), ArrayParameterType::STRING)
            ->executeQuery()
            ->fetchAllAssociative();

        $byId = [];
        foreach ($rows as $row) {
            $id = $row['id'];
            if (! is_int($id) && ! is_string($id)) {
                continue;
            }

            $byId[$id] = is_string($row['status'] ?? null) ? $row['status'] : null;
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
     * @return list<\Piwigo\Users\Projection\ActivationKeyRow>
     */
    public function findPendingActivationKeyRows(): array
    {
        $rows = $this->createQueryBuilder('ui')
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

            $result[] = new \Piwigo\Users\Projection\ActivationKeyRow(
                userId: $row['userId'],
                status: is_string($row['status']) ? $row['status'] : '',
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
