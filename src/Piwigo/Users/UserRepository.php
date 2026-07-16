<?php

declare(strict_types=1);

namespace Piwigo\Users;

use Doctrine\DBAL\ArrayParameterType;
use Piwigo\Db\AbstractRepository;
use Piwigo\Db\Tables;

/**
 * Persistence layer for the user domain's registration/lookup/preferences
 * slice: `users` (login/password/email), `user_infos` (profile row,
 * preferences), `user_group` (default-group assignment on registration).
 *
 * Scoped to what's genuinely self-contained: build_user()/getuserdata()'s
 * user_cache generation and check_user_favorites() stay procedural for now
 * -- both are deeply coupled to Category/Image domain internals
 * (get_computed_categories(), Tables::imageCategory(), image-count-based
 * cache invalidation) that don't exist as typed modules yet (P19).
 */
final class UserRepository extends AbstractRepository
{
    /**
     * Returns the webmaster's email address (the users row whose id
     * column matches $conf['webmaster_id']).
     *
     * P23 batch 8d: relocated from include/functions.inc.php's
     * get_webmaster_mail_address(), unchanged logic (including its
     * trigger_change('get_webmaster_mail_address', ...) filter hook and
     * its own $conf['user_fields'] column-name resolution) -- stays
     * zero-arg, matching the original's own signature exactly, so every
     * real call site retargets as a pure rename.
     */
    public function getWebmasterMailAddress(): string
    {
        /** @var array<string, mixed> $conf */
        global $conf;

        $user_fields = $conf['user_fields'] ?? [];
        $user_fields = is_array($user_fields) ? $user_fields : [];
        $email_field = $user_fields['email'] ?? 'email';
        $email_field = is_string($email_field) ? $email_field : 'email';
        $id_field = $user_fields['id'] ?? 'id';
        $id_field = is_string($id_field) ? $id_field : 'id';
        $webmaster_id = $conf['webmaster_id'] ?? 0;
        $webmaster_id = is_numeric($webmaster_id) ? (int) $webmaster_id : 0;

        $value = $this->conn->createQueryBuilder()
            ->select($email_field)
            ->from(Tables::users())
            ->where($id_field . ' = :webmasterId')
            ->setParameter('webmasterId', $webmaster_id)
            ->executeQuery()
            ->fetchOne();

        // users.email is nullable — a webmaster without an email set is real,
        // not a bug, so this degrades to '' rather than asserting non-null.
        $email = is_string($value) ? $value : '';

        $email = trigger_change('get_webmaster_mail_address', $email);

        return is_string($email) ? $email : '';
    }

    public function findIdByUsername(string $username, string $idColumn, string $usernameColumn): int|false
    {
        $value = $this->conn->createQueryBuilder()
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
        $value = $this->conn->createQueryBuilder()
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
        $row = $this->conn->createQueryBuilder()
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
        $value = $this->conn->createQueryBuilder()
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
        $qb = $this->conn->createQueryBuilder()
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
        $value = $this->conn->createQueryBuilder()
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
        $names = $this->conn->createQueryBuilder()
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
     *   the original's $conf['user_fields'] mapping
     */
    public function insertUser(array $columns): int
    {
        $qb = $this->conn->createQueryBuilder()
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

        return (int) $this->conn->lastInsertId();
    }

    /**
     * @param array<int|string, int|string> $userIds
     * @param array<string, mixed> $row default column => value pairs,
     *   copied onto every inserted row (matches create_user_infos()'s own
     *   "start from get_default_user_info(), overlay per-row fields"
     *   logic)
     */
    public function insertUserInfos(array $userIds, array $row): void
    {
        if ($userIds === []) {
            return;
        }

        foreach ($userIds as $userId) {
            $qb = $this->conn->createQueryBuilder()
                ->insert(Tables::userInfos());
            $values = [
                'user_id' => ':userId',
            ];
            $qb->setParameter('userId', $userId);
            $i = 0;
            foreach ($row as $column => $value) {
                $placeholder = ':c' . $i;
                $values[$column] = $placeholder;
                $qb->setParameter('c' . $i, $value);
                $i++;
            }
            $qb->values($values)
                ->executeStatement();
        }
    }

    /**
     * @return array<string, mixed>|null
     */
    public function findDefaultUserInfoRow(int $defaultUserId): ?array
    {
        $row = $this->conn->createQueryBuilder()
            ->select('*')
            ->from(Tables::userInfos())
            ->where('user_id = :id')
            ->setParameter('id', $defaultUserId)
            ->executeQuery()
            ->fetchAssociative();

        return $row === false ? null : $row;
    }

    public function savePreferences(int|string $userId, string $serializedPreferences): void
    {
        $this->conn->createQueryBuilder()
            ->update(Tables::userInfos())
            ->set('preferences', ':preferences')
            ->where('user_id = :userId')
            ->setParameter('preferences', $serializedPreferences)
            ->setParameter('userId', $userId)
            ->executeStatement();
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

        $ids = $this->conn->createQueryBuilder()
            ->select('user_id')
            ->from(Tables::userInfos())
            ->where('status IN (:statuses)')
            ->setParameter('statuses', $statusList, ArrayParameterType::STRING)
            ->executeQuery()
            ->fetchFirstColumn();

        return array_map(intval(...), $ids);
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
        /** @var array<string, mixed> $conf */
        global $conf;

        $tables = [
            Tables::userAccess(),
            Tables::userMailNotification(),
            Tables::userFeed(),
            Tables::userCache(),
            Tables::userCacheCategories(),
            Tables::userGroup(),
            Tables::favorites(),
            Tables::caddie(),
            Tables::userInfos(),
            Tables::userAuthKeys(),
        ];

        foreach ($tables as $table) {
            $this->conn->createQueryBuilder()
                ->delete($table)
                ->where('user_id = :userId')
                ->setParameter('userId', $userId)
                ->executeStatement();
        }

        $userFields = $conf['user_fields'];
        $userIdField = is_array($userFields) && is_string($userFields['id'] ?? null) ? $userFields['id'] : 'id';
        $this->conn->createQueryBuilder()
            ->delete(Tables::users())
            ->where($userIdField . ' = :userId')
            ->setParameter('userId', $userId)
            ->executeStatement();
    }
}
