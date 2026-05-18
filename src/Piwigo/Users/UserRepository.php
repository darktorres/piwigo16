<?php

declare(strict_types=1);

namespace Piwigo\Users;

use Doctrine\DBAL\ArrayParameterType;
use Doctrine\DBAL\ParameterType;
use Piwigo\Db\AbstractRepository;

/** Persistence layer for the user domain. */
final class UserRepository extends AbstractRepository
{
    /**
     * Delete all favorites for the given user (when removing all at once from the favorites page).
     */
    public function deleteAllFavoritesByUserId(int $userId): void
    {
        $this->conn->createQueryBuilder()
            ->delete($this->table('favorites'))
            ->where('user_id = :userId')
            ->setParameter('userId', $userId)
            ->executeStatement();
    }

    /**
     * Delete favorites entries for the given image ids.
     *
     * @param int[] $imageIds
     */
    public function deleteFavoritesByImageIds(array $imageIds): void
    {
        if ($imageIds === []) {
            return;
        }
        $qb = $this->conn->createQueryBuilder()
            ->delete($this->table('favorites'));
        $qb->where($qb->expr()->in('image_id', ':imageIds'))
           ->setParameter('imageIds', $imageIds, ArrayParameterType::INTEGER);
        $qb->executeStatement();
    }

    /**
     * Delete the user row from the users table.
     *
     * $usersTable and $idField come from admin config (Config::usersTable,
     * Config::userFields), not from user input — safe to embed in SQL.
     */
    public function deleteByUserId(int $userId, string $usersTable, string $idField): void
    {
        $this->conn->executeStatement(
            'DELETE FROM ' . $usersTable . ' WHERE ' . $idField . ' = ?',
            [$userId]
        );
    }

    /**
     * Set last_visit to now for the given user.
     * Uses PHP time so the repository stays DB-engine agnostic.
     */
    public function updateLastVisit(int $userId): void
    {
        $now = new \DateTimeImmutable()->format('Y-m-d H:i:s');
        $this->conn->createQueryBuilder()
            ->update($this->table('user_infos'))
            ->set('last_visit', ':now')
            ->where('user_id = :userId')
            ->setParameter('now', $now)
            ->setParameter('userId', $userId)
            ->executeStatement();
    }

    /**
     * Return the webmaster's email address.
     * $emailField, $idField, $usersTable come from Config — admin-configured,
     * not user-supplied, safe to embed in SQL.
     */
    public function getWebmasterEmail(string $emailField, string $idField, string $usersTable, int $webmasterId): string
    {
        $value = $this->conn->executeQuery(
            "SELECT $emailField FROM $usersTable WHERE $idField = ?",
            [$webmasterId]
        )->fetchOne();
        return is_scalar($value) ? (string) $value : '';
    }

    /**
     * Delete rows from any user-related table where user_id is not in the base
     * users table.  $tableName is a `Piwigo\Db\Tables` accessor result
     * (e.g. `Tables::userInfos()`) — not user-supplied, safe to embed directly.
     *
     * @param int[] $userIds
     */
    public function deleteOrphanedFromTable(string $tableName, array $userIds): void
    {
        if ($userIds === []) {
            return;
        }
        $qb = $this->conn->createQueryBuilder()
            ->delete($tableName);
        $qb->where($qb->expr()->in('user_id', ':ids'))
           ->setParameter('ids', $userIds, ArrayParameterType::INTEGER);
        $qb->executeStatement();
    }


    /** Total number of registered users. */
    public function countAll(string $usersTable): int
    {
        $value = $this->conn->createQueryBuilder()
            ->select('COUNT(*)')
            ->from($usersTable)
            ->executeQuery()
            ->fetchOne();
        return is_numeric($value) ? (int) $value : 0;
    }

    /**
     * Count users whose status is one of the given statuses.
     *
     * @param string[] $statuses
     */
    public function countByStatus(array $statuses): int
    {
        if ($statuses === []) {
            return 0;
        }
        $qb = $this->conn->createQueryBuilder()
            ->select('COUNT(*)')
            ->from($this->table('user_infos'));
        $qb->where($qb->expr()->in('status', ':statuses'))
           ->setParameter('statuses', $statuses, ArrayParameterType::STRING);
        $value = $qb->executeQuery()->fetchOne();
        return is_numeric($value) ? (int) $value : 0;
    }

    /** Total number of groups. */
    public function countGroups(): int
    {
        $value = $this->conn->createQueryBuilder()
            ->select('COUNT(*)')
            ->from($this->table('groups'))
            ->executeQuery()
            ->fetchOne();
        return is_numeric($value) ? (int) $value : 0;
    }

    /**
     * Return distinct (registration_year, registration_month) rows ordered by
     * registration_date. Uses MySQL MONTH()/YEAR() — project is MySQL-only.
     *
     * @return list<array{registration_month: int, registration_year: int}>
     */
    public function findRegistrationMonthsYears(): array
    {
        $rows = $this->conn->executeQuery(
            'SELECT DISTINCT MONTH(registration_date) AS registration_month,
                    YEAR(registration_date) AS registration_year
             FROM ' . $this->table('user_infos') . '
             ORDER BY registration_date'
        )->fetchAllAssociative();
        return array_map(fn (array $r): array => [
            'registration_month' => is_numeric($r['registration_month']) ? (int) $r['registration_month'] : 0,
            'registration_year'  => is_numeric($r['registration_year']) ? (int) $r['registration_year'] : 0,
        ], $rows);
    }

    /**
     * Return (status → count) distribution for all users except the guest.
     *
     * @return array<string, int>
     */
    public function findStatusDistribution(int $guestId): array
    {
        $rows = $this->conn->createQueryBuilder()
            ->select('status', 'COUNT(*) AS nb_users_of')
            ->from($this->table('user_infos'))
            ->where('user_id != :guestId')
            ->setParameter('guestId', $guestId)
            ->groupBy('status')
            ->executeQuery()
            ->fetchAllAssociative();
        $result = [];
        foreach ($rows as $row) {
            $result[is_string($row['status'] ?? null) ? $row['status'] : ''] = is_numeric($row['nb_users_of']) ? (int) $row['nb_users_of'] : 0;
        }
        return $result;
    }

    /**
     * Return (level → count) distribution for all users except the guest.
     *
     * @return array<int, int>
     */
    public function findLevelDistribution(int $guestId): array
    {
        $rows = $this->conn->createQueryBuilder()
            ->select('level', 'COUNT(*) AS nb_users_of')
            ->from($this->table('user_infos'))
            ->where('user_id != :guestId')
            ->setParameter('guestId', $guestId)
            ->groupBy('level')
            ->executeQuery()
            ->fetchAllAssociative();
        $result = [];
        foreach ($rows as $row) {
            $result[is_numeric($row['level']) ? (int) $row['level'] : 0] = is_numeric($row['nb_users_of']) ? (int) $row['nb_users_of'] : 0;
        }
        return $result;
    }

    /**
     * Return the earliest registration_date among all users (excluding NULL rows),
     * ordered by user_id ascending. Returns null if no rows found.
     */
    public function findEarliestRegistrationDate(): ?string
    {
        $value = $this->conn->createQueryBuilder()
            ->select('registration_date')
            ->from($this->table('user_infos'))
            ->where('registration_date IS NOT NULL')
            ->orderBy('user_id', 'ASC')
            ->setMaxResults(1)
            ->executeQuery()
            ->fetchOne();
        return is_string($value) ? $value : null;
    }

    /**
     * Update status to $status for the given user ids.
     * Called by UserService::get()->checkAndSaveUserInfos() when changing user status.
     *
     * @param int[] $userIds
     */
    public function updateStatusForUsers(string $status, array $userIds): void
    {
        if ($userIds === []) {
            return;
        }
        $qb = $this->conn->createQueryBuilder()
            ->update($this->table('user_infos'))
            ->set('status', ':status')
            ->setParameter('status', $status);
        $qb->where($qb->expr()->in('user_id', ':userIds'))
           ->setParameter('userIds', $userIds, ArrayParameterType::INTEGER);
        $qb->executeStatement();
    }

    /**
     * Update last_visit and last_visit_from_history for a user.
     * Called by get_last_visit_from_history() after reading from the history table.
     */
    public function updateLastVisitFromHistory(int $userId, ?string $lastVisit): void
    {
        $qb = $this->conn->createQueryBuilder()
            ->update($this->table('user_infos'))
            ->set('last_visit_from_history', '1')
            ->set('lastmodified', 'lastmodified')
            ->where('user_id = :userId')
            ->setParameter('userId', $userId);

        if ($lastVisit === null) {
            $qb->set('last_visit', 'NULL');
        } else {
            $qb->set('last_visit', ':lastVisit')->setParameter('lastVisit', $lastVisit);
        }

        $qb->executeStatement();
    }

    /**
     * Persist the JSON-encoded user preferences blob to user_infos. Called by
     * {@see PreferencesService::userprefsSave()} after updating the in-memory
     * preferences array.
     */
    public function updatePreferences(int $userId, string $encodedPreferences): void
    {
        $this->conn->createQueryBuilder()
            ->update($this->table('user_infos'))
            ->set('preferences', ':prefs')
            ->where('user_id = :userId')
            ->setParameter('prefs', $encodedPreferences)
            ->setParameter('userId', $userId)
            ->executeStatement();
    }

    /**
     * Return the status field from user_infos for the given user, or null if not found.
     * Used by the upgrade access check to verify webmaster status.
     */
    public function findStatusByUserId(int $userId): ?string
    {
        $value = $this->conn->createQueryBuilder()
            ->select('status')
            ->from($this->table('user_infos'))
            ->where('user_id = :userId')
            ->setParameter('userId', $userId)
            ->executeQuery()
            ->fetchOne();
        return is_string($value) ? $value : null;
    }

    /** Truncate user_cache_categories (full cache invalidation). */
    public function truncateCategoryCache(): void
    {
        $this->conn->executeStatement('TRUNCATE TABLE ' . $this->table('user_cache_categories'));
    }

    /** Truncate user_cache (full cache invalidation). */
    public function truncateUserCache(): void
    {
        $this->conn->executeStatement('TRUNCATE TABLE ' . $this->table('user_cache'));
    }

    /** Mark all user cache entries as needing update (soft invalidation). */
    public function markAllCachesForUpdate(): void
    {
        $this->conn->createQueryBuilder()
            ->update($this->table('user_cache'))
            ->set('need_update', '1')
            ->executeStatement();
    }

    /** Clear nb_available_tags for all users. */
    public function clearNbAvailableTags(): void
    {
        $this->conn->createQueryBuilder()
            ->update($this->table('user_cache'))
            ->set('nb_available_tags', 'NULL')
            ->executeStatement();
    }

    /**
     * Clear user_representative_picture_id for the given category in user cache.
     * Called when the category's representative is changed via WS.
     */
    public function clearUserRepresentativeForCategory(int $catId): void
    {
        $this->conn->createQueryBuilder()
            ->update($this->table('user_cache_categories'))
            ->set('user_representative_picture_id', 'NULL')
            ->where('cat_id = :catId')
            ->setParameter('catId', $catId)
            ->executeStatement();
    }

    /** Delete notification feed rows that were never used (last_check IS NULL). */
    public function deleteNeverUsedFeeds(): void
    {
        $this->conn->createQueryBuilder()
            ->delete($this->table('user_feed'))
            ->where('last_check IS NULL')
            ->executeStatement();
    }

    /**
     * Return all session rows from the sessions table.
     * Used by maintenance to find sessions belonging to deleted users.
     *
     * @return list<array<string, mixed>>
     */
    public function findAllSessions(): array
    {
        return $this->conn->createQueryBuilder()
            ->select('id', 'data')
            ->from($this->table('sessions'))
            ->executeQuery()
            ->fetchAllAssociative();
    }

    /**
     * Return all user ids as a set (associative array keyed by id string).
     * Used by maintenance to cross-check session user ids.
     *
     * @return array<string, null>
     */
    public function findAllUserIdsAsSet(string $idField, string $usersTable): array
    {
        $rows = $this->conn->executeQuery(
            "SELECT $idField FROM $usersTable"
        )->fetchFirstColumn();
        $result = [];
        foreach ($rows as $id) {
            $result[is_scalar($id) ? (string) $id : ''] = null;
        }
        return $result;
    }

    /**
     * Return a map of user_id → username for all users.
     * $idField, $usernameField, $usersTable are admin-configured — not user-supplied.
     *
     * @return array<int, string>
     */
    public function findAllUserIdNameMap(string $idField, string $usernameField, string $usersTable): array
    {
        $rows = $this->conn->executeQuery(
            "SELECT $idField AS id, $usernameField AS username FROM $usersTable"
        )->fetchAllAssociative();
        $result = [];
        foreach ($rows as $row) {
            $result[is_numeric($row['id']) ? (int) $row['id'] : 0] = is_string($row['username'] ?? null) ? $row['username'] : '';
        }
        return $result;
    }

    /**
     * Return all users with their status from user_infos.
     *
     * @return list<array<string, mixed>>
     */
    public function findAllWithStatus(string $idField, string $usernameField, string $usersTable): array
    {
        return $this->conn->executeQuery(
            "SELECT DISTINCT u.$idField AS id, u.$usernameField AS username, ui.status
             FROM $usersTable AS u
             JOIN " . $this->table('user_infos') . " AS ui ON u.$idField = ui.user_id"
        )->fetchAllAssociative();
    }

    /**
     * Return the hashed password for a single user, or null if not found.
     * Used by pwg.users.php to verify the current password before changing it.
     */
    public function findPasswordById(
        string $passwordField,
        string $idField,
        string $usersTable,
        int $userId
    ): ?string {
        $value = $this->conn->createQueryBuilder()
            ->select($passwordField)
            ->from($usersTable)
            ->where("$idField = :userId")
            ->setParameter('userId', $userId)
            ->executeQuery()
            ->fetchOne();
        return is_string($value) ? $value : null;
    }

    /**
     * Delete a single favorite entry for the given user+image combination.
     * Used by pwg.users.favorites_remove.
     */
    public function deleteFavorite(int $userId, int $imageId): void
    {
        $this->conn->createQueryBuilder()
            ->delete($this->table('favorites'))
            ->where('user_id = :userId')
            ->andWhere('image_id = :imageId')
            ->setParameter('userId', $userId)
            ->setParameter('imageId', $imageId)
            ->executeStatement();
    }

    /**
     * Return the username for a single user id, or null if not found.
     * $usernameField, $idField, $usersTable are admin-configured — not user-supplied.
     */
    public function findUsernameById(
        string $idField,
        string $usernameField,
        string $usersTable,
        int $userId
    ): ?string {
        $value = $this->conn->createQueryBuilder()
            ->select($usernameField)
            ->from($usersTable)
            ->where("$idField = :userId")
            ->setParameter('userId', $userId)
            ->executeQuery()
            ->fetchOne();
        return is_string($value) ? $value : null;
    }

    /**
     * Return a map of user_id → username for the given ids.
     * Column names come from admin config — not user-supplied.
     *
     * @param  int[]  $ids
     * @return array<string, string>  keyed by string user_id
     */
    public function findUsernamesByIds(
        string $idField,
        string $usernameField,
        string $usersTable,
        array $ids
    ): array {
        if ($ids === []) {
            return [];
        }
        $rows = $this->conn->executeQuery(
            "SELECT $idField AS id, $usernameField AS username FROM $usersTable WHERE $idField IN (?)",
            [$ids],
            [ArrayParameterType::INTEGER]
        )->fetchAllAssociative();
        $result = [];
        foreach ($rows as $row) {
            $result[is_scalar($row['id'] ?? null) ? (string) $row['id'] : ''] = is_scalar($row['username'] ?? null) ? (string) $row['username'] : '';
        }
        return $result;
    }

    /**
     * Count users whose email (case-insensitive) matches $email, optionally excluding $excludeUserId.
     * $emailField, $idField, $usersTable are admin-configured — not user-supplied.
     */
    public function countByEmail(
        string $emailField,
        string $idField,
        string $usersTable,
        string $email,
        ?int $excludeUserId = null
    ): int {
        $qb = $this->conn->createQueryBuilder()
            ->select('COUNT(*)')
            ->from($usersTable)
            ->where("UPPER($emailField) = UPPER(:email)")
            ->setParameter('email', $email);
        if ($excludeUserId !== null) {
            $qb->andWhere("$idField != :excludeId")
               ->setParameter('excludeId', $excludeUserId);
        }
        $value = $qb->executeQuery()->fetchOne();
        return is_numeric($value) ? (int) $value : 0;
    }

    /**
     * Count users whose username matches $login (case-insensitive).
     * $usernameField and $usersTable are admin-configured — not user-supplied.
     */
    public function countByUsernameInsensitive(
        string $usernameField,
        string $usersTable,
        string $login
    ): int {
        $value = $this->conn->createQueryBuilder()
            ->select("COUNT($usernameField)")
            ->from($usersTable)
            ->where("LOWER($usernameField) = :login")
            ->setParameter('login', strtolower($login))
            ->executeQuery()
            ->fetchOne();
        return is_numeric($value) ? (int) $value : 0;
    }

    /**
     * Return all username values from the users table.
     * $usernameField and $usersTable are admin-configured — not user-supplied.
     *
     * @return string[]
     */
    public function findAllUsernames(string $usernameField, string $usersTable): array
    {
        $rows = $this->conn->executeQuery(
            "SELECT $usernameField FROM $usersTable"
        )->fetchFirstColumn();
        return array_map(fn (mixed $v): string => is_scalar($v) ? (string) $v : '0', $rows);
    }

    /**
     * Return ids of all groups that have is_default = 1, ordered by id.
     *
     * @return int[]
     */
    public function findDefaultGroupIds(): array
    {
        $rows = $this->conn->createQueryBuilder()
            ->select('id')
            ->from($this->table('groups'))
            ->where('is_default = 1')
            ->orderBy('id', 'ASC')
            ->executeQuery()
            ->fetchFirstColumn();
        return array_map(fn (mixed $v): int => is_numeric($v) ? (int) $v : 0, $rows);
    }

    /**
     * Return the user id whose username exactly matches $username, or false.
     * $usernameField, $idField, $usersTable are admin-configured — not user-supplied.
     */
    public function findIdByUsername(
        string $usernameField,
        string $idField,
        string $usersTable,
        string $username
    ): int|false {
        $value = $this->conn->createQueryBuilder()
            ->select($idField)
            ->from($usersTable)
            ->where("$usernameField = :username")
            ->setParameter('username', $username)
            ->executeQuery()
            ->fetchOne();
        return is_numeric($value) ? (int) $value : false;
    }

    /**
     * Return the user id whose email (case-insensitive) matches $email, or false.
     * $emailField, $idField, $usersTable are admin-configured — not user-supplied.
     */
    public function findIdByEmail(
        string $emailField,
        string $idField,
        string $usersTable,
        string $email
    ): int|false {
        $value = $this->conn->createQueryBuilder()
            ->select($idField)
            ->from($usersTable)
            ->where("UPPER($emailField) = UPPER(:email)")
            ->setParameter('email', $email)
            ->executeQuery()
            ->fetchOne();
        return is_numeric($value) ? (int) $value : false;
    }

    /**
     * Return the user_infos row for the default user, or null if not found.
     *
     * @return array<string, mixed>|null
     */
    public function getDefaultUserInfo(int $defaultUserId): ?array
    {
        $row = $this->conn->createQueryBuilder()
            ->select('*')
            ->from($this->table('user_infos'))
            ->where('user_id = :userId')
            ->setParameter('userId', $defaultUserId)
            ->executeQuery()
            ->fetchAssociative();
        return $row !== false ? $row : null;
    }

    /**
     * Return username + password for a user, used to compute the auto-login key.
     * Column names are admin-configured — not user-supplied.
     *
     * @return array{username: string, password: string}|null
     */
    public function findAuthFieldsById(
        string $usernameField,
        string $passwordField,
        string $idField,
        string $usersTable,
        int $userId
    ): ?array {
        $row = $this->conn->executeQuery(
            "SELECT $usernameField AS username, $passwordField AS password FROM $usersTable WHERE $idField = ?",
            [$userId]
        )->fetchAssociative();
        if ($row === false) {
            return null;
        }
        return [
            'username' => is_string($row['username']) ? $row['username'] : '',
            'password' => is_string($row['password']) ? $row['password'] : '',
        ];
    }

    /**
     * Find a user by exact username or exact email match (username tried first).
     * Returns id/username/email/password/status or null.
     * All column/table names are admin-configured — not user-supplied.
     *
     * @return array<string, mixed>|null
     */
    public function findByUsernameOrEmail(
        string $usernameField,
        string $emailField,
        string $idField,
        string $passwordField,
        string $usersTable,
        string $usernameOrEmail
    ): ?array {
        $select = "$idField AS id, $usernameField AS username, $emailField AS email, $passwordField AS password, status";
        $userInfos = $this->table('user_infos');
        $joinCond  = "u.$idField = i.user_id";

        $sql = "SELECT $select FROM $usersTable AS u LEFT JOIN $userInfos AS i ON $joinCond WHERE";

        $row = $this->conn->executeQuery("$sql $usernameField = ?", [$usernameOrEmail])->fetchAssociative();
        if ($row === false) {
            $row = $this->conn->executeQuery("$sql $emailField = ?", [$usernameOrEmail])->fetchAssociative();
        }

        return $row !== false ? $row : null;
    }

    /** Add an image to a user's favorites. */
    public function addFavorite(int $userId, int $imageId): void
    {
        $this->conn->executeStatement(
            'INSERT INTO ' . $this->table('favorites') . ' (image_id, user_id) VALUES (?, ?)',
            [$imageId, $userId]
        );
    }

    /** Return true when the given image is in the user's favorites. */
    public function isFavorite(int $userId, int $imageId): bool
    {
        $count = $this->conn->createQueryBuilder()
            ->select('COUNT(*)')
            ->from($this->table('favorites'))
            ->where('image_id = :imageId')
            ->andWhere('user_id = :userId')
            ->setParameter('imageId', $imageId)
            ->setParameter('userId', $userId)
            ->executeQuery()
            ->fetchOne();
        return is_numeric($count) ? (int) $count > 0 : false;
    }

    /** Count how many images the given user has in their caddie. */
    public function countCaddieByUserId(int $userId): int
    {
        $value = $this->conn->createQueryBuilder()
            ->select('COUNT(*)')
            ->from($this->table('caddie'))
            ->where('user_id = :userId')
            ->setParameter('userId', $userId)
            ->executeQuery()
            ->fetchOne();
        return is_numeric($value) ? (int) $value : 0;
    }

    /**
     * Return all user_infos rows that have a non-null, non-expired activation key.
     *
     * @return list<array<string, mixed>>
     */
    public function findByActiveActivationKey(): array
    {
        return $this->conn->createQueryBuilder()
            ->select('user_id', 'status', 'activation_key')
            ->from($this->table('user_infos'))
            ->where('activation_key IS NOT NULL')
            ->andWhere('activation_key_expire > NOW()')
            ->executeQuery()
            ->fetchAllAssociative();
    }

    /**
     * Run the caller-built user-listing query for the WS users.getList
     * endpoint and return rows + the optional FOUND_ROWS total when
     * SQL_CALC_FOUND_ROWS was requested.
     *
     * @param  list<mixed>                                $params
     * @param  list<ArrayParameterType|ParameterType>     $types
     * @return array{rows: list<array<string, mixed>>, total: int|null}
     */
    public function findUsersListPage(string $query, bool $captureFoundRows, array $params = [], array $types = []): array
    {
        $rows  = $this->conn->executeQuery($query, $params, $types)->fetchAllAssociative();
        $total = null;
        if ($captureFoundRows) {
            $value = $this->conn->executeQuery('SELECT FOUND_ROWS()')->fetchOne();
            $total = is_numeric($value) ? (int) $value : 0;
        }
        return ['rows' => $rows, 'total' => $total];
    }

    /**
     * Return (user_id, group_id) rows for the given users.
     *
     * @param  list<int> $userIds
     * @return list<array{user_id: int, group_id: int}>
     */
    public function findUserGroupPairsByUserIds(array $userIds): array
    {
        if ($userIds === []) {
            return [];
        }
        $qb = $this->conn->createQueryBuilder()
            ->select('user_id', 'group_id')
            ->from($this->table('user_group'));
        $qb->where($qb->expr()->in('user_id', ':ids'))
           ->setParameter('ids', $userIds, ArrayParameterType::INTEGER);
        return array_map(static fn (array $row): array => [
            'user_id'  => is_numeric($row['user_id']) ? (int) $row['user_id'] : 0,
            'group_id' => is_numeric($row['group_id']) ? (int) $row['group_id'] : 0,
        ], $qb->executeQuery()->fetchAllAssociative());
    }

    /**
     * Update arbitrary columns on user_infos for one user.
     *
     * @param array<string, mixed> $fields
     */
    public function updateUserInfosById(int $userId, array $fields): void
    {
        if ($fields === []) {
            return;
        }
        $this->conn->update($this->table('user_infos'), $fields, ['user_id' => $userId]);
    }

    /**
     * For each integer day count in $days, return the corresponding
     * server-side ADDDATE(NOW(), INTERVAL $day DAY) value as a yyyy-mm-dd
     * string. Used by the profile API-key expiration picker.
     *
     * @param  list<int> $days
     * @return array<string, string>
     */
    public function findApiKeyExpirationDatesFor(array $days): array
    {
        if ($days === []) {
            return [];
        }
        $exprs = [];
        foreach ($days as $day) {
            $dayInt   = (int) $day;
            $exprs[]  = 'ADDDATE(NOW(), INTERVAL ' . $dayInt . ' DAY) AS `' . $dayInt . '`';
        }
        $row = $this->conn->executeQuery('SELECT ' . implode(', ', $exprs))->fetchAssociative();
        if ($row === false) {
            return [];
        }
        $out = [];
        foreach ($row as $key => $value) {
            $out[(string) $key] = is_scalar($value) ? (string) $value : '';
        }
        return $out;
    }

    /**
     * Return all ids from the configurable users table.
     *
     * @return list<int>
     */
    public function findAllUserIdsFromUsers(string $idField, string $usersTable): array
    {
        $rows = $this->conn->executeQuery(
            "SELECT $idField AS id FROM $usersTable"
        )->fetchFirstColumn();
        return array_map(static fn (mixed $v): int => is_numeric($v) ? (int) $v : 0, $rows);
    }

    /**
     * Return user_ids present in user_infos.
     *
     * @return list<int>
     */
    public function findUserIdsFromUserInfos(): array
    {
        $rows = $this->conn->createQueryBuilder()
            ->select('user_id')
            ->from($this->table('user_infos'))
            ->executeQuery()
            ->fetchFirstColumn();
        return array_map(static fn (mixed $v): int => is_numeric($v) ? (int) $v : 0, $rows);
    }

    /**
     * Return DISTINCT user_ids from the given table. Table name comes from
     * Tables::* — admin-config-derived, not user input.
     *
     * @return list<int>
     */
    public function findDistinctUserIdsFromTable(string $table): array
    {
        $rows = $this->conn->executeQuery(
            'SELECT DISTINCT user_id FROM ' . $table
        )->fetchFirstColumn();
        return array_map(static fn (mixed $v): int => is_numeric($v) ? (int) $v : 0, $rows);
    }

    /**
     * Return user_ids whose user_infos.status is in the given list.
     *
     * @param  list<string> $statuses
     * @return list<int>
     */
    public function findUserIdsByStatuses(array $statuses): array
    {
        if ($statuses === []) {
            return [];
        }
        $qb = $this->conn->createQueryBuilder()
            ->select('user_id')
            ->from($this->table('user_infos'));
        $qb->where($qb->expr()->in('status', ':statuses'))
           ->setParameter('statuses', $statuses, ArrayParameterType::STRING);
        return array_map(static fn (mixed $v): int => is_numeric($v) ? (int) $v : 0, $qb->executeQuery()->fetchFirstColumn());
    }

    /**
     * Return user's authorized favorite image ids — favorites whose image is
     * still in a permission-visible category.
     *
     * @param list<mixed>                            $permParams
     * @param list<ArrayParameterType|ParameterType> $permTypes
     * @return list<int>
     */
    public function findAuthorizedFavoriteImageIds(
        int $userId,
        string $permWhere,
        array $permParams,
        array $permTypes,
    ): array {
        $query  = 'SELECT DISTINCT f.image_id FROM ' . $this->table('favorites') . ' AS f'
            . ' INNER JOIN ' . $this->table('image_category') . ' AS ic ON f.image_id = ic.image_id'
            . ' WHERE f.user_id = ? ' . $permWhere;
        $params = [$userId, ...$permParams];
        $types  = [ParameterType::INTEGER, ...$permTypes];
        return array_map(static fn (mixed $v): int => is_numeric($v) ? (int) $v : 0, $this->conn->executeQuery($query, $params, $types)->fetchFirstColumn());
    }

    /**
     * Return image_ids in the given user's favorites (no permission filter).
     *
     * @return list<int>
     */
    public function findFavoriteImageIdsByUserPlain(int $userId): array
    {
        $rows = $this->conn->createQueryBuilder()
            ->select('image_id')
            ->from($this->table('favorites'))
            ->where('user_id = :userId')
            ->setParameter('userId', $userId)
            ->executeQuery()
            ->fetchFirstColumn();
        return array_map(static fn (mixed $v): int => is_numeric($v) ? (int) $v : 0, $rows);
    }

    /**
     * Return user record selected via the Config::userFields() column map
     * (each value is `<dbfield> AS <pwgfield>`), keyed by pwgfield.
     *
     * @param  array<string, string>     $userFields  pwgfield → dbfield
     * @return array<string, mixed>|null
     */
    public function findByConfigFields(array $userFields, string $usersTable, int $userId): ?array
    {
        if ($userFields === []) {
            return null;
        }
        $cols = [];
        foreach ($userFields as $pwgfield => $dbfield) {
            $cols[] = $dbfield . ' AS ' . $pwgfield;
        }
        $idField = $userFields['id'] ?? 'id';
        $query   = 'SELECT ' . implode(', ', $cols) . ' FROM ' . $usersTable . ' WHERE ' . $idField . ' = ?';
        $row     = $this->conn->executeQuery($query, [$userId], [ParameterType::INTEGER])->fetchAssociative();
        return $row !== false ? $row : null;
    }

    /**
     * Return the merged user_infos + user_cache + theme.name row for the
     * given user, or null if no user_infos row exists.
     *
     * @return array<string, mixed>|null
     */
    public function findInfoCacheThemeByUserId(int $userId): ?array
    {
        $query = 'SELECT ui.*, uc.*, t.name AS theme_name FROM ' . $this->table('user_infos') . ' AS ui'
            . ' LEFT JOIN ' . $this->table('user_cache') . ' AS uc ON ui.user_id = uc.user_id'
            . ' LEFT JOIN ' . $this->table('themes') . ' AS t ON t.id = ui.theme'
            . ' WHERE ui.user_id = ?';
        $row = $this->conn->executeQuery($query, [$userId], [ParameterType::INTEGER])->fetchAssociative();
        return $row !== false ? $row : null;
    }

    /**
     * Count whether external-auth user-info rows are wired up — used by
     * the externalAuth bootstrap to detect a need-to-init situation.
     */
    public function countExternalAuthInfoByUserId(int $userId): int
    {
        $value = $this->conn->executeQuery(
            'SELECT COUNT(1) AS counter FROM ' . $this->table('user_infos') . ' AS ui'
            . ' LEFT JOIN ' . $this->table('user_cache') . ' AS uc ON ui.user_id = uc.user_id'
            . ' LEFT JOIN ' . $this->table('themes') . ' AS t ON t.id = ui.theme'
            . ' WHERE ui.user_id = ? GROUP BY ui.user_id',
            [$userId],
            [ParameterType::INTEGER],
        )->fetchOne();
        return is_numeric($value) ? (int) $value : 0;
    }

    /** Count user_cache rows for the given user (0 or 1). */
    public function countCacheByUserId(int $userId): int
    {
        $value = $this->conn->createQueryBuilder()
            ->select('COUNT(*)')
            ->from($this->table('user_cache'))
            ->where('user_id = :userId')
            ->setParameter('userId', $userId)
            ->executeQuery()
            ->fetchOne();
        return is_numeric($value) ? (int) $value : 0;
    }

    /** Delete user_cache_categories rows for the given user. */
    public function deleteUserCacheCategoriesByUserId(int $userId): void
    {
        $this->conn->executeStatement(
            'DELETE FROM ' . $this->table('user_cache_categories') . ' WHERE user_id = ?',
            [$userId],
            [ParameterType::INTEGER],
        );
    }

    /**
     * Replace user_cache_categories rows for the user atomically. Each row
     * carries (cat_id, date_last, max_date_last, nb_images, count_images,
     * nb_categories, count_categories) for one category.
     *
     * @param list<array<string, mixed>> $rows
     */
    public function insertUserCacheCategoriesBatch(int $userId, array $rows): void
    {
        if ($rows === []) {
            return;
        }
        $this->conn->transactional(function () use ($rows): void {
            foreach ($rows as $row) {
                $this->conn->executeStatement(
                    'INSERT IGNORE INTO ' . $this->table('user_cache_categories')
                    . ' (user_id, cat_id, date_last, max_date_last, nb_images, count_images, nb_categories, count_categories)'
                    . ' VALUES (?, ?, ?, ?, ?, ?, ?, ?)',
                    [$row['user_id'] ?? null, $row['cat_id'] ?? null, $row['date_last'] ?? null, $row['max_date_last'] ?? null, $row['nb_images'] ?? null, $row['count_images'] ?? null, $row['nb_categories'] ?? null, $row['count_categories'] ?? null],
                );
            }
        });
    }

    /** Delete user_cache row for the given user. */
    public function deleteUserCacheByUserId(int $userId): void
    {
        $this->conn->executeStatement(
            'DELETE FROM ' . $this->table('user_cache') . ' WHERE user_id = ?',
            [$userId],
            [ParameterType::INTEGER],
        );
    }

    /**
     * Insert (with IGNORE on PK conflict) a single user_cache row.
     * forbidden_categories / image_access_list are JSON strings (encoded by
     * the caller). last_photo_date may be null.
     */
    public function insertUserCacheRow(
        int $userId,
        bool $needUpdate,
        int $cacheUpdateTime,
        string $forbiddenCatsJson,
        int $nbTotalImages,
        ?string $lastPhotoDate,
        string $imageAccessType,
        string $imageAccessListJson,
    ): void {
        $cols   = ['user_id', 'need_update', 'cache_update_time', 'forbidden_categories', 'nb_total_images'];
        $params = [$userId, $needUpdate ? 1 : 0, $cacheUpdateTime, $forbiddenCatsJson, $nbTotalImages];
        $types  = [ParameterType::INTEGER, ParameterType::INTEGER, ParameterType::INTEGER, ParameterType::STRING, ParameterType::INTEGER];
        if ($lastPhotoDate !== null && $lastPhotoDate !== '') {
            $cols[]   = 'last_photo_date';
            $params[] = $lastPhotoDate;
            $types[]  = ParameterType::STRING;
        }
        $cols   = [...$cols, 'image_access_type', 'image_access_list'];
        $params = [...$params, $imageAccessType, $imageAccessListJson];
        $types  = [...$types, ParameterType::STRING, ParameterType::STRING];
        $placeholders = implode(', ', array_fill(0, count($cols), '?'));
        $this->conn->executeStatement(
            'INSERT IGNORE INTO ' . $this->table('user_cache') . ' (' . implode(', ', $cols) . ') VALUES (' . $placeholders . ')',
            $params,
            $types,
        );
    }

    /**
     * Return user_ids whose status is 'webmaster' or 'admin'.
     *
     * @return list<int>
     */
    public function findAdminUserIds(): array
    {
        $qb = $this->conn->createQueryBuilder()
            ->select('user_id')
            ->from($this->table('user_infos'));
        $qb->where($qb->expr()->in('status', ':statuses'))
           ->setParameter('statuses', ['webmaster', 'admin'], ArrayParameterType::STRING);
        return array_map(static fn (mixed $v): int => is_numeric($v) ? (int) $v : 0, $qb->executeQuery()->fetchFirstColumn());
    }

    /**
     * Insert a new user row and return its id. $usersTable comes from Config.
     *
     * @param array<string, mixed> $fields
     */
    public function insertNew(string $usersTable, array $fields): int
    {
        $this->conn->insert($usersTable, $fields);
        return (int) $this->conn->lastInsertId();
    }

    /**
     * Insert user_group rows atomically (each row {user_id, group_id}).
     *
     * @param list<array{user_id: int, group_id: int}> $rows
     */
    public function insertUserGroupRows(array $rows): void
    {
        if ($rows === []) {
            return;
        }
        $this->conn->transactional(function () use ($rows): void {
            foreach ($rows as $row) {
                $this->conn->insert($this->table('user_group'), $row);
            }
        });
    }

    /** Update arbitrary columns on a single users row. */
    /** @param array<string, mixed> $fields */
    public function updateUserById(string $usersTable, string $idField, int $userId, array $fields): void
    {
        if ($fields === []) {
            return;
        }
        $this->conn->update($usersTable, $fields, [$idField => $userId]);
    }

    /**
     * Insert user_infos rows atomically.
     *
     * @param list<array<string, mixed>> $rows
     */
    public function insertUserInfosBatch(array $rows): void
    {
        if ($rows === []) {
            return;
        }
        $this->conn->transactional(function () use ($rows): void {
            foreach ($rows as $row) {
                $this->conn->insert($this->table('user_infos'), $row);
            }
        });
    }

    /**
     * Return ids among $groupIds that exist in the groups table.
     *
     * @param  int[] $groupIds
     * @return list<int>
     */
    public function findExistingGroupIdsAmong(array $groupIds): array
    {
        if ($groupIds === []) {
            return [];
        }
        $qb = $this->conn->createQueryBuilder()
            ->select('id')
            ->from($this->table('groups'));
        $qb->where($qb->expr()->in('id', ':ids'))
           ->setParameter('ids', $groupIds, ArrayParameterType::INTEGER);
        return array_map(static fn (mixed $v): int => is_numeric($v) ? (int) $v : 0, $qb->executeQuery()->fetchFirstColumn());
    }

    /**
     * Run an arbitrary parameterized statement (DELETE/UPDATE) — transitional
     * passthrough used by UserService::getuserdata's user-cache rebuild path
     * that composes statements out of the caller's own state.
     *
     * @param  list<mixed>                                                    $params
     * @param  list<ArrayParameterType|\Doctrine\DBAL\ParameterType>          $types
     */
    public function executeRawStatement(string $sql, array $params = [], array $types = []): void
    {
        $this->conn->executeStatement($sql, $params, $types);
    }

    /**
     * Insert a single favorite row, ignoring (image_id, user_id) PK conflicts.
     */
    public function insertFavoriteIgnore(int $userId, int $imageId): void
    {
        $this->conn->executeStatement(
            'INSERT IGNORE INTO ' . $this->table('favorites') . ' (image_id, user_id) VALUES (?, ?)',
            [$imageId, $userId],
            [ParameterType::INTEGER, ParameterType::INTEGER],
        );
    }

    /**
     * Return full image rows for the user's favorites, subject to the
     * caller's permission filter and ORDER BY suffix.
     *
     * @param list<mixed>                            $permParams
     * @param list<ArrayParameterType|ParameterType> $permTypes
     * @return list<array<string, mixed>>
     */
    public function findFavoriteImagesWithDetails(
        int $userId,
        string $permWhere,
        array $permParams,
        array $permTypes,
        string $orderBySuffix,
    ): array {
        $query  = 'SELECT i.* FROM ' . $this->table('favorites') . ' INNER JOIN ' . $this->table('images') . ' i'
            . ' ON image_id = i.id WHERE user_id = ? ' . $permWhere . ' ' . $orderBySuffix;
        $params = [$userId, ...$permParams];
        $types  = [ParameterType::INTEGER, ...$permTypes];
        return $this->conn->executeQuery($query, $params, $types)->fetchAllAssociative();
    }

    /** Update `language` for the given user (used after Accept-Language detection). */
    public function updateLanguage(int $userId, string $language): void
    {
        $this->conn->update(
            $this->table('user_infos'),
            ['language' => $language],
            ['user_id' => $userId],
        );
    }

    /** Clear password-reset (activation_key + activation_key_expire) for the user. */
    public function clearActivationKey(int $userId): void
    {
        $this->conn->update(
            $this->table('user_infos'),
            ['activation_key' => null, 'activation_key_expire' => null],
            ['user_id' => $userId],
        );
    }

    /** Set the activation_key + expire pair for a password reset. */
    public function setActivationKey(int $userId, string $keyHash, string $expire): void
    {
        $this->conn->update(
            $this->table('user_infos'),
            ['activation_key' => $keyHash, 'activation_key_expire' => $expire],
            ['user_id' => $userId],
        );
    }

    /**
     * Return user_ids from user_infos whose status is anything other than
     * 'guest'. Used by admin-notification dialogs that exclude the anonymous
     * placeholder user.
     *
     * @return list<int>
     */
    public function findNonGuestUserIds(): array
    {
        $rows = $this->conn->createQueryBuilder()
            ->select('user_id')
            ->from($this->table('user_infos'))
            ->where("status != 'guest'")
            ->executeQuery()
            ->fetchFirstColumn();
        return array_map(static fn (mixed $v): int => is_numeric($v) ? (int) $v : 0, $rows);
    }

    /**
     * Return id → username map for the given user ids.
     *
     * @param  list<int> $ids
     * @return array<int|string, string>
     */
    public function findIdToUsernameMapByIds(string $idField, string $usernameField, string $usersTable, array $ids): array
    {
        if ($ids === []) {
            return [];
        }
        $rows = $this->conn->executeQuery(
            "SELECT $idField AS id, $usernameField AS username FROM $usersTable WHERE $idField IN (?)",
            [$ids],
            [ArrayParameterType::INTEGER],
        )->fetchAllAssociative();
        $out = [];
        foreach ($rows as $row) {
            $key       = is_scalar($row['id']) ? (string) $row['id'] : '';
            $out[$key] = is_string($row['username'] ?? null) ? $row['username'] : '';
        }
        return $out;
    }

    /**
     * Return (user_id, status, language, email, username) rows for the given
     * user ids — used by admin-notification mailers.
     *
     * @param  list<int> $ids
     * @return list<array<string, mixed>>
     */
    public function findMailRecipientInfoByIds(string $idField, string $usernameField, string $emailField, string $usersTable, array $ids): array
    {
        if ($ids === []) {
            return [];
        }
        $query = "SELECT ui.user_id, ui.status, ui.language, u.$emailField AS email, u.$usernameField AS username"
            . ' FROM ' . $this->table('user_infos') . ' AS ui'
            . " JOIN $usersTable AS u ON u.$idField = ui.user_id"
            . ' WHERE ui.user_id IN (?)';
        return $this->conn->executeQuery($query, [$ids], [ArrayParameterType::INTEGER])->fetchAllAssociative();
    }
}
