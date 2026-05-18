<?php

declare(strict_types=1);

namespace Piwigo\Notification;

use Doctrine\DBAL\ArrayParameterType;
use Doctrine\DBAL\ParameterType;
use Piwigo\Config\Config;
use Piwigo\Db\AbstractRepository;

/** Persistence layer for the notification domain. */
final class NotificationRepository extends AbstractRepository
{
    /**
     * Set email to NULL for users whose email is blank after trimming.
     * $emailField and $usersTable are admin-configured — not user-supplied.
     */
    public function clearEmptyEmails(string $emailField, string $usersTable): void
    {
        $this->conn->executeStatement(
            "UPDATE $usersTable SET $emailField = NULL WHERE TRIM($emailField) = ''"
        );
    }

    /**
     * Return users who have an email address but no notification subscription yet.
     * Column names are admin-configured — not user-supplied.
     *
     * @return list<array<string, mixed>>
     */
    public function findUsersWithoutNotification(
        string $idField,
        string $usernameField,
        string $emailField,
        string $usersTable
    ): array {
        return $this->conn->executeQuery(
            "SELECT u.$idField AS user_id, u.$usernameField AS username, u.$emailField AS mail_address
             FROM $usersTable AS u
             LEFT JOIN " . $this->table('user_mail_notification') . " AS m ON u.$idField = m.user_id
             WHERE u.$emailField IS NOT NULL
               AND m.user_id IS NULL
             ORDER BY user_id"
        )->fetchAllAssociative();
    }

    /**
     * Delete notification subscriptions for the given check_keys.
     *
     * @param string[] $checkKeys
     */
    public function deleteByCheckKeys(array $checkKeys): void
    {
        if ($checkKeys === []) {
            return;
        }
        $qb = $this->conn->createQueryBuilder()
            ->delete($this->table('user_mail_notification'));
        $qb->where($qb->expr()->in('check_key', ':keys'))
           ->setParameter('keys', $checkKeys, ArrayParameterType::STRING);
        $qb->executeStatement();
    }

    /** Return true when the given check_key already exists in user_mail_notification. */
    public function checkKeyExists(string $key): bool
    {
        $count = $this->conn->createQueryBuilder()
            ->select('COUNT(*)')
            ->from($this->table('user_mail_notification'))
            ->where('check_key = :key')
            ->setParameter('key', $key)
            ->executeQuery()
            ->fetchOne();
        return is_numeric($count) ? (int) $count > 0 : false;
    }

    /**
     * Fetch notification subscription rows for $action ('subscribe' or 'send').
     *
     * $checkKeyList restricts results to specific check_keys (empty = all).
     * $enabledFilter = null means no enabled filter; true = only enabled, false = only disabled.
     *
     * $usernameField / $emailField / $userIdField are admin-configured column names
     * from Config::userFields() — not user-supplied values, so safe to interpolate.
     *
     * @param  string[] $checkKeyList
     * @return list<array<string, float|int|string|null>>
     */
    public function getUserNotifications(string $action, array $checkKeyList = [], ?bool $enabledFilter = null): array
    {
        if (!in_array($action, ['subscribe', 'send'], true)) {
            return [];
        }

        $userFields    = Config::userFields();
        $userIdField   = $userFields['id'] ?? 'id';
        $usernameField = $userFields['username'] ?? 'username';
        $emailField    = $userFields['email'] ?? 'mail_address';

        $qb = $this->conn->createQueryBuilder()
            ->select(
                'N.user_id',
                'N.check_key',
                "U.{$usernameField} AS username",
                "U.{$emailField} AS mail_address",
                'N.enabled',
                'N.last_send',
                'UI.status'
            )
            ->from($this->table('user_mail_notification'), 'N')
            ->join('N', $this->table('users'), 'U', "N.user_id = U.{$userIdField}")
            ->join('N', $this->table('user_infos'), 'UI', 'UI.user_id = N.user_id');

        if ($action === 'send') {
            $qb->andWhere('N.enabled = :enabledSend')
               ->andWhere("U.{$emailField} IS NOT NULL")
               ->setParameter('enabledSend', 1);
        }

        if ($checkKeyList !== []) {
            $qb->andWhere($qb->expr()->in('N.check_key', ':checkKeys'))
               ->setParameter('checkKeys', $checkKeyList, ArrayParameterType::STRING);
        }

        if ($enabledFilter !== null) {
            $qb->andWhere('N.enabled = :enabledFilter')
               ->setParameter('enabledFilter', $enabledFilter ? 1 : 0);
        }

        if ($action === 'send') {
            $qb->orderBy('N.last_send')->addOrderBy('username');
        } else {
            $qb->orderBy('username');
        }

        /** @var list<array<string, float|int|string|null>> */
        return $qb->executeQuery()->fetchAllAssociative();
    }

    /**
     * Insert user_mail_notification rows atomically.
     *
     * @param list<array<string, mixed>> $rows
     */
    public function insertSubscriptionsBatch(array $rows): void
    {
        if ($rows === []) {
            return;
        }
        $this->conn->transactional(function () use ($rows): void {
            foreach ($rows as $row) {
                $this->conn->insert($this->table('user_mail_notification'), $row);
            }
        });
    }

    /**
     * Apply per-user last_send updates atomically.
     *
     * @param list<array{user_id: int, last_send: string}> $rows
     */
    public function setLastSendBatch(array $rows): void
    {
        if ($rows === []) {
            return;
        }
        $this->conn->transactional(function () use ($rows): void {
            foreach ($rows as $row) {
                $this->conn->update(
                    $this->table('user_mail_notification'),
                    ['last_send' => $row['last_send']],
                    ['user_id'   => $row['user_id']],
                );
            }
        });
    }

    /**
     * Apply a list of (check_key, enabled) updates atomically.
     *
     * @param list<array{check_key: mixed, enabled: mixed}> $updates
     */
    public function setEnabledByCheckKeysBatch(array $updates): void
    {
        if ($updates === []) {
            return;
        }
        $this->conn->transactional(function () use ($updates): void {
            foreach ($updates as $row) {
                $this->conn->update(
                    $this->table('user_mail_notification'),
                    ['enabled' => $row['enabled']],
                    ['check_key' => $row['check_key']],
                );
            }
        });
    }

    /**
     * Build (params, types) for a date-range filter on a single column.
     * Empty start/end means "no bound on that side".
     *
     * @return array{0: list<mixed>, 1: list<ArrayParameterType|ParameterType>, 2: string}
     */
    private function dateRangeFragment(string $column, ?string $start, ?string $end): array
    {
        $sql    = '';
        $params = [];
        $types  = [];
        if ($start !== null && $start !== '') {
            $sql      .= " AND $column > ?";
            $params[]  = $start;
            $types[]   = ParameterType::STRING;
        }
        if ($end !== null && $end !== '') {
            $sql      .= " AND $column <= ?";
            $params[]  = $end;
            $types[]   = ParameterType::STRING;
        }
        return [$params, $types, $sql];
    }

    /**
     * COUNT(DISTINCT c.id) of validated comments in the date window, restricted
     * by the supplied permission filter (which references ic.image_id).
     *
     * @param list<mixed>                                  $permParams
     * @param list<ArrayParameterType|ParameterType>       $permTypes
     */
    public function countNewComments(?string $start, ?string $end, string $permWhere, array $permParams, array $permTypes): int
    {
        [$dParams, $dTypes, $dSql] = $this->dateRangeFragment('c.validation_date', $start, $end);
        $sql = 'SELECT COUNT(DISTINCT c.id)'
            . ' FROM ' . $this->table('comments') . ' AS c'
            . ' INNER JOIN ' . $this->table('image_category') . ' AS ic ON c.image_id = ic.image_id'
            . ' WHERE 1=1' . $dSql . $permWhere;
        $value = $this->conn->executeQuery($sql, [...$dParams, ...$permParams], [...$dTypes, ...$permTypes])->fetchOne();
        return is_numeric($value) ? (int) $value : 0;
    }

    /**
     * SELECT DISTINCT c.id list for the same predicate as countNewComments.
     *
     * @param list<mixed>                                  $permParams
     * @param list<ArrayParameterType|ParameterType>       $permTypes
     * @return list<int>
     */
    public function findNewCommentIds(?string $start, ?string $end, string $permWhere, array $permParams, array $permTypes): array
    {
        [$dParams, $dTypes, $dSql] = $this->dateRangeFragment('c.validation_date', $start, $end);
        $sql = 'SELECT DISTINCT c.id'
            . ' FROM ' . $this->table('comments') . ' AS c'
            . ' INNER JOIN ' . $this->table('image_category') . ' AS ic ON c.image_id = ic.image_id'
            . ' WHERE 1=1' . $dSql . $permWhere;
        $rows = $this->conn->executeQuery($sql, [...$dParams, ...$permParams], [...$dTypes, ...$permTypes])->fetchAllAssociative();
        return array_map(static fn (mixed $v): int => is_numeric($v) ? (int) $v : 0, array_column($rows, 'id'));
    }

    /** COUNT(DISTINCT id) of unvalidated comments in the date window. */
    public function countUnvalidatedComments(?string $start, ?string $end): int
    {
        [$dParams, $dTypes, $dSql] = $this->dateRangeFragment('date', $start, $end);
        $sql = 'SELECT COUNT(DISTINCT id) FROM ' . $this->table('comments')
            . ' WHERE validated = 0' . $dSql;
        $value = $this->conn->executeQuery($sql, $dParams, $dTypes)->fetchOne();
        return is_numeric($value) ? (int) $value : 0;
    }

    /**
     * @return list<int>
     */
    public function findUnvalidatedCommentIds(?string $start, ?string $end): array
    {
        [$dParams, $dTypes, $dSql] = $this->dateRangeFragment('date', $start, $end);
        $sql = 'SELECT DISTINCT id FROM ' . $this->table('comments')
            . ' WHERE validated = 0' . $dSql;
        $rows = $this->conn->executeQuery($sql, $dParams, $dTypes)->fetchAllAssociative();
        return array_map(static fn (mixed $v): int => is_numeric($v) ? (int) $v : 0, array_column($rows, 'id'));
    }

    /**
     * COUNT(DISTINCT image_id) of images that became available in the date
     * window, after applying the supplied permission filter on id.
     *
     * @param list<mixed>                                  $permParams
     * @param list<ArrayParameterType|ParameterType>       $permTypes
     */
    public function countNewElements(?string $start, ?string $end, string $permWhere, array $permParams, array $permTypes): int
    {
        [$dParams, $dTypes, $dSql] = $this->dateRangeFragment('date_available', $start, $end);
        $sql = 'SELECT COUNT(DISTINCT image_id)'
            . ' FROM ' . $this->table('images')
            . ' INNER JOIN ' . $this->table('image_category') . ' AS ic ON image_id = id'
            . ' WHERE 1=1' . $dSql . $permWhere;
        $value = $this->conn->executeQuery($sql, [...$dParams, ...$permParams], [...$dTypes, ...$permTypes])->fetchOne();
        return is_numeric($value) ? (int) $value : 0;
    }

    /**
     * @param list<mixed>                                  $permParams
     * @param list<ArrayParameterType|ParameterType>       $permTypes
     * @return list<int>
     */
    public function findNewElementIds(?string $start, ?string $end, string $permWhere, array $permParams, array $permTypes): array
    {
        [$dParams, $dTypes, $dSql] = $this->dateRangeFragment('date_available', $start, $end);
        $sql = 'SELECT DISTINCT image_id'
            . ' FROM ' . $this->table('images')
            . ' INNER JOIN ' . $this->table('image_category') . ' AS ic ON image_id = id'
            . ' WHERE 1=1' . $dSql . $permWhere;
        $rows = $this->conn->executeQuery($sql, [...$dParams, ...$permParams], [...$dTypes, ...$permTypes])->fetchAllAssociative();
        return array_map(static fn (mixed $v): int => is_numeric($v) ? (int) $v : 0, array_column($rows, 'image_id'));
    }

    /**
     * COUNT(DISTINCT category_id) of categories that received new images in
     * the date window.
     *
     * @param list<mixed>                                  $permParams
     * @param list<ArrayParameterType|ParameterType>       $permTypes
     */
    public function countUpdatedCategories(?string $start, ?string $end, string $permWhere, array $permParams, array $permTypes): int
    {
        [$dParams, $dTypes, $dSql] = $this->dateRangeFragment('date_available', $start, $end);
        $sql = 'SELECT COUNT(DISTINCT category_id)'
            . ' FROM ' . $this->table('images')
            . ' INNER JOIN ' . $this->table('image_category') . ' AS ic ON image_id = id'
            . ' WHERE 1=1' . $dSql . $permWhere;
        $value = $this->conn->executeQuery($sql, [...$dParams, ...$permParams], [...$dTypes, ...$permTypes])->fetchOne();
        return is_numeric($value) ? (int) $value : 0;
    }

    /**
     * @param list<mixed>                                  $permParams
     * @param list<ArrayParameterType|ParameterType>       $permTypes
     * @return list<int>
     */
    public function findUpdatedCategoryIds(?string $start, ?string $end, string $permWhere, array $permParams, array $permTypes): array
    {
        [$dParams, $dTypes, $dSql] = $this->dateRangeFragment('date_available', $start, $end);
        $sql = 'SELECT DISTINCT category_id'
            . ' FROM ' . $this->table('images')
            . ' INNER JOIN ' . $this->table('image_category') . ' AS ic ON image_id = id'
            . ' WHERE 1=1' . $dSql . $permWhere;
        $rows = $this->conn->executeQuery($sql, [...$dParams, ...$permParams], [...$dTypes, ...$permTypes])->fetchAllAssociative();
        return array_map(static fn (mixed $v): int => is_numeric($v) ? (int) $v : 0, array_column($rows, 'category_id'));
    }

    /** COUNT(DISTINCT user_id) of new users in the date window. */
    public function countNewUsers(?string $start, ?string $end): int
    {
        [$dParams, $dTypes, $dSql] = $this->dateRangeFragment('registration_date', $start, $end);
        $sql = 'SELECT COUNT(DISTINCT user_id) FROM ' . $this->table('user_infos')
            . ' WHERE 1=1' . $dSql;
        $value = $this->conn->executeQuery($sql, $dParams, $dTypes)->fetchOne();
        return is_numeric($value) ? (int) $value : 0;
    }

    /**
     * @return list<int>
     */
    public function findNewUserIds(?string $start, ?string $end): array
    {
        [$dParams, $dTypes, $dSql] = $this->dateRangeFragment('registration_date', $start, $end);
        $sql = 'SELECT DISTINCT user_id FROM ' . $this->table('user_infos')
            . ' WHERE 1=1' . $dSql;
        $rows = $this->conn->executeQuery($sql, $dParams, $dTypes)->fetchAllAssociative();
        return array_map(static fn (mixed $v): int => is_numeric($v) ? (int) $v : 0, array_column($rows, 'user_id'));
    }

    /**
     * Aggregate (date_available, nb_elements, nb_cats) rows used to build the
     * "recent posts" cache, restricted to images visible under the supplied
     * permission filter (which references i.id).
     *
     * @param list<mixed>                                  $permParams
     * @param list<ArrayParameterType|ParameterType>       $permTypes
     * @return list<array<string, mixed>>
     */
    public function findRecentPostDates(int $maxDates, string $permWhere, array $permParams, array $permTypes): array
    {
        $sql = '
SELECT
    date_available,
    COUNT(DISTINCT id) AS nb_elements,
    COUNT(DISTINCT category_id) AS nb_cats
  FROM ' . $this->table('images') . ' i INNER JOIN ' . $this->table('image_category') . ' AS ic ON id=image_id
  ' . $permWhere . '
  GROUP BY date_available
  ORDER BY date_available DESC
  LIMIT ' . $maxDates;
        return $this->conn->executeQuery($sql, $permParams, $permTypes)->fetchAllAssociative();
    }

    /**
     * Random sample of $max image rows that share $dateAvailable, restricted
     * by the permission filter.
     *
     * @param list<mixed>                                  $permParams
     * @param list<ArrayParameterType|ParameterType>       $permTypes
     * @return list<array<string, mixed>>
     */
    public function findRecentImagesForDate(string $dateAvailable, int $max, string $permWhere, array $permParams, array $permTypes): array
    {
        $sql = '
SELECT DISTINCT i.*
  FROM ' . $this->table('images') . ' i
    INNER JOIN ' . $this->table('image_category') . ' AS ic ON id=image_id
  ' . $permWhere . '
    AND date_available = ?
  ORDER BY RAND()
  LIMIT ' . $max;
        return $this->conn->executeQuery(
            $sql,
            [...$permParams, $dateAvailable],
            [...$permTypes, ParameterType::STRING],
        )->fetchAllAssociative();
    }

    /**
     * Top-N (uppercats, img_count) tuples for categories that received images
     * on $dateAvailable, restricted by the permission filter.
     *
     * @param list<mixed>                                  $permParams
     * @param list<ArrayParameterType|ParameterType>       $permTypes
     * @return list<array<string, mixed>>
     */
    public function findRecentCategoriesForDate(string $dateAvailable, int $max, string $permWhere, array $permParams, array $permTypes): array
    {
        $sql = '
SELECT
    DISTINCT c.uppercats,
    COUNT(DISTINCT i.id) AS img_count
  FROM ' . $this->table('images') . ' i
    INNER JOIN ' . $this->table('image_category') . ' AS ic ON i.id=image_id
    INNER JOIN ' . $this->table('categories') . ' c ON c.id=category_id
  ' . $permWhere . '
    AND date_available = ?
  GROUP BY category_id, c.uppercats
  ORDER BY img_count DESC
  LIMIT ' . $max;
        return $this->conn->executeQuery(
            $sql,
            [...$permParams, $dateAvailable],
            [...$permTypes, ParameterType::STRING],
        )->fetchAllAssociative();
    }
}
