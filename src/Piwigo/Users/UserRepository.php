<?php

declare(strict_types=1);

namespace Piwigo\Users;

use Doctrine\ORM\EntityRepository;
use Piwigo\Db\Tables;
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
 * Scoped to what's genuinely self-contained: build_user()/getuserdata()'s
 * user_cache generation and check_user_favorites() stay procedural for now
 * -- both are deeply coupled to Category/Image domain internals
 * (get_computed_categories(), Tables::imageCategory(), image-count-based
 * cache invalidation) that don't exist as typed modules yet (P19).
 *
 * @extends EntityRepository<UserInfoEntity>
 */
final class UserRepository extends EntityRepository implements \Piwigo\Core\WebmasterMailProviderInterface
{
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

        $user_fields = \Piwigo\Config\CurrentConfig::userFields();
        $email_field = $user_fields['email'];
        $id_field = $user_fields['id'];
        $webmaster_id = \Piwigo\Config\CurrentConfig::webmasterId();

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

        $email = \Piwigo\PluginConfig\EventDispatcher::get()->triggerChange('get_webmaster_mail_address', $email);

        return is_string($email) ? $email : '';
    }

    public function findIdByUsername(string $username, string $idColumn, string $usernameColumn): int|false
    {
        $value = $this->getEntityManager()
            ->getConnection()
            ->createQueryBuilder()
            ->select($idColumn)
            ->from(Tables::users())
            ->where($usernameColumn . ' = :username')
            ->setParameter('username', $username)
            ->executeQuery()
            ->fetchOne();

        return is_numeric($value) ? (int) $value : false;
    }

    public function findIdByEmail(string $email, string $idColumn, string $emailColumn): int|false
    {
        $value = $this->getEntityManager()
            ->getConnection()
            ->createQueryBuilder()
            ->select($idColumn)
            ->from(Tables::users())
            ->where('UPPER(' . $emailColumn . ') = UPPER(:email)')
            ->setParameter('email', $email)
            ->executeQuery()
            ->fetchOne();

        return is_numeric($value) ? (int) $value : false;
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

    public function usernameExistsCaseInsensitive(string $username, string $usernameColumn): bool
    {
        $value = $this->getEntityManager()
            ->getConnection()
            ->createQueryBuilder()
            ->select('COUNT(*)')
            ->from(Tables::users())
            ->where('LOWER(' . $usernameColumn . ') = LOWER(:username)')
            ->setParameter('username', $username)
            ->executeQuery()
            ->fetchOne();

        return is_numeric($value) && (int) $value > 0;
    }

    public function emailExists(string $email, string $emailColumn, string $idColumn, ?int $excludeUserId): bool
    {
        $qb = $this->getEntityManager()
            ->getConnection()
            ->createQueryBuilder()
            ->select('COUNT(*)')
            ->from(Tables::users())
            ->where('UPPER(' . $emailColumn . ') = UPPER(:email)')
            ->setParameter('email', $email);

        if ($excludeUserId !== null) {
            $qb->andWhere($idColumn . ' != :excludeUserId')
                ->setParameter('excludeUserId', $excludeUserId);
        }

        $value = $qb->executeQuery()
            ->fetchOne();

        return is_numeric($value) && (int) $value > 0;
    }

    public function findUsernameById(int $userId, string $idColumn, string $usernameColumn): ?string
    {
        $value = $this->getEntityManager()
            ->getConnection()
            ->createQueryBuilder()
            ->select($usernameColumn)
            ->from(Tables::users())
            ->where($idColumn . ' = :id')
            ->setParameter('id', $userId)
            ->executeQuery()
            ->fetchOne();

        return is_string($value) ? $value : null;
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
     * @param array<string, mixed> $columns generic pwgfield => real DB
     *   column-name-and-value pairs (username/password/email), matching
     *   the original's \Piwigo\Config\CurrentConfig::userFields() mapping
     */
    public function insertUser(array $columns): int
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

        return (int) $conn->lastInsertId();
    }

    /**
     * @param array<int|string, int|string> $userIds
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
            $em->persist($this->buildUserInfoEntity((int) $userId, $row));
        }

        $em->flush();
    }

    /**
     * @param array<string, mixed> $row
     */
    private function buildUserInfoEntity(int $userId, array $row): UserInfoEntity
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
            theme: is_string($row['theme'] ?? null) ? $row['theme'] : 'modus',
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

    public function findDefaultUserInfoRow(int $defaultUserId): ?UserInfo
    {
        $entity = $this->find($defaultUserId);

        return $entity === null ? null : UserInfo::fromEntity($entity);
    }

    /**
     * @param array<string, mixed> $preferences
     */
    public function savePreferences(int|string $userId, array $preferences): void
    {
        $entity = $this->find((int) $userId);
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
     * @return list<int>
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

        return array_map(static fn (array $row): int => $row['userId'], $rows);
    }

    /**
     * Deletes a user's row across every table referencing it. Ported from
     * admin/include/functions.php's delete_user() (P23 batch 8d),
     * unchanged logic -- pure cascade delete, no business logic (session
     * purge / activity log stay in Piwigo\Users\UserService::deleteUser(),
     * which calls this).
     */
    public function deleteUser(int $userId): void
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
                ->setParameter('userId', $userId)
                ->executeStatement();
        }

        // Bypassed the ORM for every table above except UserInfoEntity's own
        // DQL delete -- any UserInfoEntity this EntityManager already loaded
        // for this user would otherwise stay stale (same identity-map
        // reasoning as GroupRepository::delete()'s own comment).
        $em->clear();

        $userFields = \Piwigo\Config\CurrentConfig::userFields();
        $userIdField = $userFields['id'];
        $conn->createQueryBuilder()
            ->delete(Tables::users())
            ->where($userIdField . ' = :userId')
            ->setParameter('userId', $userId)
            ->executeStatement();
    }

    /**
     * Every user id from the base users table -- `$userIdColumn` matches
     * {@see deleteUser()}'s own `$conf['user_fields']['id']` multi-auth
     * compat column-name lookup (the caller passes it, this method never
     * reads `$conf` itself).
     *
     * @return list<int>
     */
    public function findAllUserIds(string $userIdColumn): array
    {
        return array_map(static fn (mixed $v): int => is_numeric($v) ? (int) $v : 0, $this->getEntityManager()
            ->getConnection()
            ->createQueryBuilder()
            ->select($userIdColumn . ' AS id')
            ->from(Tables::users())
            ->executeQuery()
            ->fetchFirstColumn());
    }

    /**
     * @return list<int>
     */
    public function findDistinctUserIdsInTable(string $table): array
    {
        return array_map(static fn (mixed $v): int => is_numeric($v) ? (int) $v : 0, $this->getEntityManager()
            ->getConnection()
            ->createQueryBuilder()
            ->select('DISTINCT user_id')
            ->from($table)
            ->executeQuery()
            ->fetchFirstColumn());
    }

    /**
     * @param  array<int>  $userIds  array_diff()'s result doesn't guarantee a list
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
            ->setParameter('userIds', $userIds, \Doctrine\DBAL\ArrayParameterType::INTEGER)
            ->executeStatement();

        // $table may be user_infos -- bypasses the ORM, so any
        // UserInfoEntity already in this EntityManager's identity map for
        // one of these ids would otherwise stay stale (same reasoning as
        // deleteUser()'s own comment).
        $em->clear();
    }
}
