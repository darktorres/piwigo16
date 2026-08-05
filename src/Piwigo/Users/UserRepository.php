<?php

declare(strict_types=1);

namespace Piwigo\Users;

use Doctrine\DBAL\ArrayParameterType;
use Doctrine\DBAL\ParameterType;
use Doctrine\DBAL\Query\QueryBuilder;
use Doctrine\ORM\EntityRepository;
use Piwigo\Common\Dto\PaginatedResult;
use Piwigo\Common\ValueObject\Email;
use Piwigo\Common\ValueObject\UserId;
use Piwigo\Common\ValueObject\Username;
use Piwigo\Db\BatchWriter;
use Piwigo\Db\Tables;
use Piwigo\Event\Mail\GetWebmasterMailAddress;
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
     */
    public function deleteUser(UserId $userId): void
    {

        $tables = [
            Tables::userAccess(),
            Tables::userMailNotification(),
            Tables::userFeed(),
            Tables::userGroup(),
            Tables::favorites(),
            Tables::caddie(),
            Tables::userInfos(),
            Tables::userAuthKeys(),
        ];

        $em = $this->getEntityManager();
        $conn = $em->getConnection();

        foreach ($tables as $table) {
            if ($table === Tables::userInfos()) {
                $em->createQueryBuilder()
                    ->delete(UserInfoEntity::class, 'ui')
                    ->where('ui.userId = :userId')
                    ->setParameter('userId', $userId)
                    ->getQuery()
                    ->execute();

                continue;
            }

            $conn->createQueryBuilder()
                ->delete($table)
                ->where('user_id = :userId')
                ->setParameter('userId', $userId->value)
                ->executeStatement();
        }

        // Bypassed the ORM for every table above except UserInfoEntity's own
        // DQL delete -- any UserInfoEntity this EntityManager already loaded
        // for this user would otherwise stay stale (same identity-map
        // reasoning as GroupRepository::delete()'s own comment).
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
     */
    public function countUserInfosRows(UserId $userId): int
    {
        $row = $this->getEntityManager()
            ->getConnection()
            ->createQueryBuilder()
            ->select('COUNT(1) AS counter')
            ->from(Tables::userInfos(), 'ui')
            ->leftJoin('ui', Tables::themes(), 't', 't.id = ui.theme')
            ->where('ui.user_id = :userId')
            ->groupBy('ui.user_id')
            ->setParameter('userId', $userId->value)
            ->executeQuery()
            ->fetchNumeric();

        return $row !== false && is_numeric($row[0]) ? (int) $row[0] : 0;
    }

    /**
     * Full `user_infos` row plus the joined theme's display name --
     * UserService::getUserData()'s own merge-with-basic-row step.
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
     * @return list<int>
     */
    public function findFavoriteImageIds(UserId $userId): array
    {
        $rows = $this->getEntityManager()
            ->getConnection()
            ->createQueryBuilder()
            ->select('image_id')
            ->from(Tables::favorites())
            ->where('user_id = :userId')
            ->setParameter('userId', $userId->value)
            ->executeQuery()
            ->fetchAllAssociative();

        return self::toIntList(array_column($rows, 'image_id'));
    }

    /**
     * @param list<int> $imageIds
     */
    public function deleteFavoritesForImages(UserId $userId, array $imageIds): void
    {
        if ($imageIds === []) {
            return;
        }

        $this->getEntityManager()
            ->getConnection()
            ->createQueryBuilder()
            ->delete(Tables::favorites())
            ->where('image_id IN (:imageIds)')
            ->andWhere('user_id = :userId')
            ->setParameter('imageIds', $imageIds, ArrayParameterType::INTEGER)
            ->setParameter('userId', $userId->value)
            ->executeStatement();
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
     */
    public function isFavorite(UserId $userId, int $imageId): bool
    {
        $value = $this->getEntityManager()
            ->getConnection()
            ->createQueryBuilder()
            ->select('COUNT(*)')
            ->from(Tables::favorites())
            ->where('image_id = :imageId')
            ->andWhere('user_id = :userId')
            ->setParameter('imageId', $imageId, ParameterType::INTEGER)
            ->setParameter('userId', $userId->value)
            ->executeQuery()
            ->fetchOne();

        return is_numeric($value) && (int) $value !== 0;
    }

    /**
     * Removes every favorite for $userId regardless of image -- Section\
     * SectionPopulator's own "remove_all_from_favorites" action, unlike
     * deleteFavoritesForImages() above which is scoped to a given image set.
     */
    public function deleteAllFavorites(UserId $userId): void
    {
        $this->getEntityManager()
            ->getConnection()
            ->createQueryBuilder()
            ->delete(Tables::favorites())
            ->where('user_id = :userId')
            ->setParameter('userId', $userId->value)
            ->executeStatement();
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
     * @param list<UserId> $userIds
     */
    public function updateStatusForUsers(array $userIds, string $status): void
    {
        if ($userIds === []) {
            return;
        }

        $this->getEntityManager()
            ->getConnection()
            ->createQueryBuilder()
            ->update(Tables::userInfos())
            ->set('status', ':status')
            ->where('user_id IN (:userIds)')
            ->setParameter('status', $status)
            ->setParameter('userIds', array_map(static fn (UserId $id): int => $id->value, $userIds), ArrayParameterType::INTEGER)
            ->executeStatement();
    }

    /**
     * Bulk `user_infos` field update -- UserService::checkAndSaveUserInfos()'s
     * own dynamic-fields branch (nb_image_page/language/recent_period/etc,
     * whichever the caller actually changed), applied to every id in
     * $userIds at once. $updates stays a raw column => scalar-value map,
     * same dynamic-column reasoning as fetchBasicUserRow() above.
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
     */
    public function findEarliestRegistrationDate(): ?string
    {
        $userInfosTable = Tables::userInfos();

        $value = $this->getEntityManager()
            ->getConnection()
            ->fetchOne(<<<SQL
                SELECT registration_date
                FROM {$userInfosTable}
                WHERE registration_date IS NOT NULL
                ORDER BY user_id ASC
                LIMIT 1
                SQL);

        return is_string($value) ? $value : null;
    }

    /**
     * Every distinct `theme` value's user count -- Admin\
     * PiwigoInfosSender's own telemetry theme-usage breakdown.
     *
     * @return array<string, int> keyed by theme
     */
    public function findThemeUsageCounts(): array
    {
        $userInfosTable = Tables::userInfos();

        $rows = $this->getEntityManager()
            ->getConnection()
            ->fetchAllAssociative(<<<SQL
                SELECT
                    theme,
                    COUNT(*) AS theme_counter
                FROM {$userInfosTable}
                GROUP BY theme
                ORDER BY theme
                SQL);

        $byTheme = [];
        foreach ($rows as $row) {
            if (is_string($row['theme'] ?? null) && is_numeric($row['theme_counter'] ?? null)) {
                $byTheme[$row['theme']] = (int) $row['theme_counter'];
            }
        }

        return $byTheme;
    }

    /**
     * Every distinct `language` value's user count -- Admin\
     * PiwigoInfosSender's own telemetry language-usage breakdown.
     *
     * @return array<string, int> keyed by language
     */
    public function findLanguageUsageCounts(): array
    {
        $userInfosTable = Tables::userInfos();

        $rows = $this->getEntityManager()
            ->getConnection()
            ->fetchAllAssociative(<<<SQL
                SELECT
                    language,
                    COUNT(*) AS language_counter
                FROM {$userInfosTable}
                GROUP BY language
                ORDER BY language
                SQL);

        $byLanguage = [];
        foreach ($rows as $row) {
            if (is_string($row['language'] ?? null) && is_numeric($row['language_counter'] ?? null)) {
                $byLanguage[$row['language']] = (int) $row['language_counter'];
            }
        }

        return $byLanguage;
    }

    /**
     * Distinct "YYYY-MM" registration months among every user --
     * Admin\UserListPageRenderer's own registration-date filter dropdown.
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
     * @return array<string, int> keyed by status
     */
    public function findUserCountsByStatus(int $excludeUserId): array
    {
        $rows = $this->getEntityManager()
            ->getConnection()
            ->createQueryBuilder()
            ->select('status', 'COUNT(*) AS nb_users_of')
            ->from(Tables::userInfos())
            ->where('user_id != :excludeUserId')
            ->groupBy('status')
            ->setParameter('excludeUserId', $excludeUserId, ParameterType::INTEGER)
            ->executeQuery()
            ->fetchAllAssociative();

        $byStatus = [];
        foreach ($rows as $row) {
            $status = $row['status'];
            if (! is_string($status)) {
                continue;
            }

            $byStatus[$status] = is_numeric($row['nb_users_of']) ? (int) $row['nb_users_of'] : 0;
        }

        return $byStatus;
    }

    /**
     * Per-level user counts, excluding $excludeUserId (the guest user) --
     * Admin\UserListPageRenderer's own level-filter counters.
     *
     * @return array<int, int> keyed by level
     */
    public function findUserCountsByLevel(int $excludeUserId): array
    {
        $rows = $this->getEntityManager()
            ->getConnection()
            ->createQueryBuilder()
            ->select('level', 'COUNT(*) AS nb_users_of')
            ->from(Tables::userInfos())
            ->where('user_id != :excludeUserId')
            ->groupBy('level')
            ->setParameter('excludeUserId', $excludeUserId, ParameterType::INTEGER)
            ->executeQuery()
            ->fetchAllAssociative();

        $byLevel = [];
        foreach ($rows as $row) {
            $level = $row['level'];
            if (! is_numeric($level)) {
                continue;
            }

            $byLevel[(int) $level] = is_numeric($row['nb_users_of']) ? (int) $row['nb_users_of'] : 0;
        }

        return $byLevel;
    }

    /**
     * Ws\PwgUsers::getList()'s own paginated, dynamically-columned user
     * listing -- $displayColumns is the already-built `field expr => alias`
     * map (always includes at least `u.<idColumn> => id`), matching the WS
     * method's client-controlled `display` param; $whereClauses are
     * already-built, trusted SQL fragments, same "caller composes trusted
     * fragments" contract as {@see \Piwigo\Comment\CommentRepository::findAllWithConditions()}.
     * $orderBy concatenates directly into ORDER BY -- caller must validate
     * this first (the WS method's own ValidationPattern::ORDER check), same
     * contract as {@see \Piwigo\Comment\CommentRepository::findForImage()}'s
     * own $order. FOUND_ROWS() is only fetched when $includeTotalCount.
     * LIMIT/OFFSET are only applied when $limit !== null.
     *
     * @param  array<string, string>  $displayColumns
     * @param  list<string>  $whereClauses
     * @return PaginatedResult<array<string, mixed>>
     */
    /**
     * SQL-modernization audit: $params/$types widened additively (both
     * default `[]`) so a caller building a bound fragment into
     * $whereClauses can bind its own values instead of splicing them;
     * $limit/$offset (previously spliced) always bound directly.
     *
     * @param  array<string, string>  $displayColumns
     * @param  list<string>  $whereClauses
     * @param array<string, mixed> $params
     * @param array<string, ArrayParameterType|ParameterType> $types
     * @return PaginatedResult<array<string, mixed>>
     */
    public function findListForWs(
        string $idColumn,
        array $displayColumns,
        bool $includeLastVisitFromHistory,
        array $whereClauses,
        string $orderBy,
        bool $includeTotalCount,
        ?int $limit,
        int $offset,
        array $params = [],
        array $types = []
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
        $whereSql = implode(' AND ', $whereClauses);
        $distinctPrefix = $includeTotalCount ? 'SQL_CALC_FOUND_ROWS ' : '';

        $sql = <<<SQL
            SELECT DISTINCT {$distinctPrefix}{$columnsSql}
            FROM {$usersTable} AS u
                INNER JOIN {$userInfosTable} AS ui
                    ON u.{$idColumn} = ui.user_id
                LEFT JOIN {$userGroupTable} AS ug
                    ON u.{$idColumn} = ug.user_id
            WHERE
                {$whereSql}
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
     * @return list<string>
     */
    public function findUserIdsExcludingStatus(string $excludedStatus): array
    {
        $userInfosTable = Tables::userInfos();

        return array_map(
            static fn (mixed $v): string => is_scalar($v) ? (string) $v : '',
            $this->getEntityManager()
                ->getConnection()
                ->executeQuery(<<<SQL
                    SELECT user_id
                    FROM {$userInfosTable}
                    WHERE status != :status
                    SQL
                    , [
                        'status' => $excludedStatus,
                    ])
                ->fetchFirstColumn()
        );
    }

    /**
     * user_id/status/language, plus email/username (JOINed from `users`,
     * matching a dynamic $idColumn/$usernameColumn/$emailColumn mapping)
     * for $userIds -- Admin\AlbumNotificationPageRenderer's own
     * "notify these specific users" mail-merge data.
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
     */
    public function findRegistrationDateById(int $userId): ?string
    {
        $rows = $this->getEntityManager()
            ->getConnection()
            ->createQueryBuilder()
            ->select('registration_date')
            ->from(Tables::userInfos())
            ->where('user_id = :userId')
            ->setParameter('userId', $userId, ParameterType::INTEGER)
            ->executeQuery()
            ->fetchAllAssociative();

        if ($rows === []) {
            return null;
        }

        $registrationDate = $rows[0]['registration_date'];

        return is_string($registrationDate) ? $registrationDate : null;
    }

    /**
     * Earliest registration_date after $afterDate -- Admin\
     * InstallationStats::getInstallationDate()'s own fallback candidate
     * when user id 2's own registration_date predates Piwigo's own
     * "origin of times".
     */
    public function findMinRegistrationDateAfter(string $afterDate): ?string
    {
        $rows = $this->getEntityManager()
            ->getConnection()
            ->createQueryBuilder()
            ->select('MIN(registration_date) AS min_registration_date')
            ->from(Tables::userInfos())
            ->where('registration_date > :afterDate')
            ->setParameter('afterDate', $afterDate)
            ->executeQuery()
            ->fetchAllAssociative();

        if ($rows === []) {
            return null;
        }

        $minRegistrationDate = $rows[0]['min_registration_date'];

        return is_string($minRegistrationDate) ? $minRegistrationDate : null;
    }

    /**
     * Total row count of `users` -- Admin\UserActivityPageRenderer's own
     * "nb_users" summary figure.
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
     * @return list<\Piwigo\Users\Projection\ActivationKeyRow>
     */
    public function findPendingActivationKeyRows(): array
    {
        $userInfosTable = Tables::userInfos();

        $rows = $this->getEntityManager()
            ->getConnection()
            ->fetchAllAssociative(<<<SQL
                SELECT
                    user_id,
                    status,
                    activation_key
                FROM {$userInfosTable}
                WHERE activation_key IS NOT NULL
                    AND activation_key_expire > NOW()
                SQL);

        return array_map(\Piwigo\Users\Projection\ActivationKeyRow::fromRow(...), $rows);
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
