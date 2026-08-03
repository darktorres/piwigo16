<?php

declare(strict_types=1);

namespace Piwigo\Notification;

use Piwigo\Db\AbstractRepository;
use Piwigo\Db\Tables;
use Piwigo\Notification\Projection\UserMailNotification;

/**
 * Persistence layer for the 2 genuinely data-touching functions in
 * `admin/include/functions_notification_by_mail.inc.php` -- everything
 * else there (the `$env_nbm`/`$page`/mail-template "workflow" lifecycle:
 * begin/set/unset/end, counters, message pushing) has no DB access of its
 * own and stays procedural, same "$page/$template glue stays in the
 * free-function delegate" split as every other P19 domain.
 */
final class NotificationByMailRepository extends AbstractRepository
{
    public function countByCheckKey(string $checkKey): int
    {
        $userMailNotificationTable = Tables::userMailNotification();
        $count = $this->conn->executeQuery(
            <<<SQL
            SELECT COUNT(*) FROM {$userMailNotificationTable} WHERE check_key = ?
            SQL
            ,
            [$checkKey]
        )->fetchOne();

        return is_numeric($count) ? (int) $count : 0;
    }

    /**
     * $checkKeyList IS bound -- [SEC-18]-class improvement over the
     * original's unescaped `'\'' . $s . '\''` string-literal quoting.
     * `NotificationByMailSender::quoteCheckKeyList()` used to reproduce
     * that same unescaped quoting for {@see deleteByCheckKeys()}'s own
     * now-former "already-quoted" input -- removed in the SQL-modernization
     * pass (its only real caller), see that method's own docblock.
     *
     * SQL-modernization audit, Item 14 Sub-phase C4: dropped the
     * `$usernameField`/`$emailField`/`$idField` multi-auth column-name
     * params -- `users`'s columns are now fixed
     * ({@see \Piwigo\Users\UserEntity}), see this repository's own class
     * docblock note. Stays on DBAL -- this repository has no `EntityManager`
     * access (extends `AbstractRepository`, not `EntityRepository`), and
     * the `N`/`UI` joins plus conditional `WHERE`/`ORDER BY` shape aren't
     * this item's own blocker to redesign.
     *
     * @param  list<string>  $checkKeyList
     * @return list<UserMailNotification>
     */
    public function findUserNotifications(
        string $action,
        array $checkKeyList,
        string $enabledFilterValue,
    ): array {
        $userMailNotificationTable = Tables::userMailNotification();
        $usersTable = Tables::users();
        $userInfosTable = Tables::userInfos();

        $sql = <<<SQL
            SELECT N.user_id, N.check_key, U.username AS username, U.mail_address AS mail_address, N.enabled, N.last_send, UI.status FROM {$userMailNotificationTable} AS N JOIN {$usersTable} AS U ON N.user_id = U.id JOIN {$userInfosTable} AS UI ON UI.user_id = N.user_id WHERE 1=1
            SQL;
        $params = [];

        if ($action === 'send') {
            $sql .= <<<SQL
                 AND N.enabled = ? AND U.mail_address IS NOT NULL
                SQL;
            $params[] = 1;
        }

        if ($checkKeyList !== []) {
            $placeholders = implode(',', array_fill(0, count($checkKeyList), '?'));
            $sql .= <<<SQL
                 AND N.check_key IN ({$placeholders})
                SQL;
            array_push($params, ...$checkKeyList);
        }

        if ($enabledFilterValue !== '') {
            $sql .= <<<SQL
                 AND N.enabled = ?
                SQL;
            $params[] = $enabledFilterValue;
        }

        $sql .= $action === 'send' ? ' ORDER BY N.last_send, username' : ' ORDER BY username';

        $rows = $this->conn->executeQuery($sql, $params)
            ->fetchAllAssociative();

        return array_map(UserMailNotification::fromRow(...), $rows);
    }

    /**
     * Sets every blank (trimmed-empty) email address to NULL --
     * Controller\Admin\NotificationByMailSubController::
     * insertNewDataUserMailNotification()'s own pre-sync cleanup, so
     * `WHERE email IS NOT NULL` below can't false-negative on a
     * whitespace-only address.
     *
     * SQL-modernization audit, Item 14 Sub-phase C4: dropped the
     * `$emailColumn` multi-auth column-name param -- see
     * {@see findUserNotifications()}'s own docblock.
     */
    public function nullifyBlankEmails(): void
    {
        $usersTable = Tables::users();
        $this->conn->executeStatement(<<<SQL
            UPDATE
              {$usersTable}
            SET
              mail_address = NULL
            WHERE
              TRIM(mail_address) = ''
            SQL);
    }

    /**
     * user_id/username/mail_address for every user with a real email
     * address but no `user_mail_notification` row yet -- Controller\Admin\
     * NotificationByMailSubController's own "which users need a fresh
     * subscription row" step.
     *
     * SQL-modernization audit, Item 14 Sub-phase C4: dropped the
     * `$idColumn`/`$usernameColumn`/`$emailColumn` multi-auth column-name
     * params -- see {@see findUserNotifications()}'s own docblock.
     *
     * @return list<array<string, mixed>>
     */
    public function findUsersWithoutNotificationRow(): array
    {
        $usersTable = Tables::users();
        $userMailNotificationTable = Tables::userMailNotification();

        return $this->conn->fetchAllAssociative(<<<SQL
            SELECT
              u.id AS user_id,
              u.username AS username,
              u.mail_address AS mail_address
            FROM
              {$usersTable} AS u LEFT JOIN {$userMailNotificationTable} AS m ON u.id = m.user_id
            WHERE
              u.mail_address IS NOT NULL AND
              m.user_id IS NULL
            ORDER BY
              user_id
            SQL);
    }

    /**
     * Deletes rows by check_key.
     *
     * SQL-modernization audit: $checkKeyList used to arrive already
     * quoted by NotificationByMailSender::quoteCheckKeyList() (`'\'' . $s
     * . '\''`, manual wrapping with zero escaping) and get spliced
     * straight into the query text -- its own docblock said "$checkKeyList
     * may come straight from $_POST", which would have made this a real
     * SQL injection had that path ever been live. Traced the one real
     * caller (Controller\Admin\NotificationByMailSubController::
     * insertNewDataUserMailNotification()): its $check_key_list is always
     * server-generated (NotificationByMailSender::findAvailableCheckKey()),
     * never $_POST, so not exploitable today -- but the construction
     * style itself is exactly this initiative's target regardless.
     * quoteCheckKeyList() had this as its only real caller; removed
     * entirely rather than left as unused dead code, and this method now
     * takes plain, unquoted check keys and binds them.
     *
     * @param  list<string>  $checkKeyList
     */
    public function deleteByCheckKeys(array $checkKeyList): void
    {
        if ($checkKeyList === []) {
            return;
        }

        $this->conn->createQueryBuilder()
            ->delete(Tables::userMailNotification())
            ->where('check_key IN (:checkKeys)')
            ->setParameter('checkKeys', $checkKeyList, \Doctrine\DBAL\ArrayParameterType::STRING)
            ->executeStatement();
    }

    /**
     * Bulk-inserts new `user_mail_notification` rows --
     * Controller\Admin\NotificationByMailSubController's own "add every
     * user without a notification row yet" step.
     *
     * @param array<int, array{user_id: mixed, check_key: string, enabled: int}> $inserts
     */
    public function insertNotifications(array $inserts): void
    {
        if ($inserts === []) {
            return;
        }

        $this->batchWriter()
            ->massInsert(Tables::userMailNotification(), ['user_id', 'check_key', 'enabled'], $inserts);
    }
}
