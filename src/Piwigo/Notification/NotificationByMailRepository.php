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
     * $usernameField/$emailField/$idField are trusted admin-configured
     * column names (\Piwigo\Config\CurrentConfig::userFields(), not user input -- can't be bound
     * as parameters anyway, SQL doesn't allow placeholders for
     * identifiers). $checkKeyList, by contrast, IS bound -- [SEC-18]-class
     * improvement over the original's unescaped `'\'' . $s . '\''`
     * string-literal quoting (now `NotificationByMailSender::quoteCheckKeyList()`,
     * still used unchanged by NotificationByMailSubController's own
     * out-of-scope raw query, kept untouched here).
     *
     * @param  list<string>  $checkKeyList
     * @return list<UserMailNotification>
     */
    public function findUserNotifications(
        string $action,
        array $checkKeyList,
        string $enabledFilterValue,
        string $usernameField,
        string $emailField,
        string $idField
    ): array {
        $userMailNotificationTable = Tables::userMailNotification();
        $usersTable = Tables::users();
        $userInfosTable = Tables::userInfos();

        $sql = <<<SQL
            SELECT N.user_id, N.check_key, U.{$usernameField} AS username, U.{$emailField} AS mail_address, N.enabled, N.last_send, UI.status FROM {$userMailNotificationTable} AS N JOIN {$usersTable} AS U ON N.user_id = U.{$idField} JOIN {$userInfosTable} AS UI ON UI.user_id = N.user_id WHERE 1=1
            SQL;
        $params = [];

        if ($action === 'send') {
            $sql .= <<<SQL
                 AND N.enabled = ? AND U.{$emailField} IS NOT NULL
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
     */
    public function nullifyBlankEmails(string $emailColumn): void
    {
        $usersTable = Tables::users();
        $this->conn->executeStatement(<<<SQL
            UPDATE
              {$usersTable}
            SET
              {$emailColumn} = NULL
            WHERE
              TRIM({$emailColumn}) = ''
            SQL);
    }

    /**
     * user_id/username/mail_address for every user with a real email
     * address but no `user_mail_notification` row yet -- Controller\Admin\
     * NotificationByMailSubController's own "which users need a fresh
     * subscription row" step.
     *
     * @return list<array<string, mixed>>
     */
    public function findUsersWithoutNotificationRow(string $idColumn, string $usernameColumn, string $emailColumn): array
    {
        $usersTable = Tables::users();
        $userMailNotificationTable = Tables::userMailNotification();

        return $this->conn->fetchAllAssociative(<<<SQL
            SELECT
              u.{$idColumn} AS user_id,
              u.{$usernameColumn} AS username,
              u.{$emailColumn} AS mail_address
            FROM
              {$usersTable} AS u LEFT JOIN {$userMailNotificationTable} AS m ON u.{$idColumn} = m.user_id
            WHERE
              u.{$emailColumn} IS NOT NULL AND
              m.user_id IS NULL
            ORDER BY
              user_id
            SQL);
    }

    /**
     * Deletes rows by check_key. $quotedCheckKeyList is already-quoted SQL
     * literals (NotificationByMailSender::quoteCheckKeyList()'s own
     * output) -- see this class's own docblock on why that stays as-is
     * rather than becoming bound parameters here.
     *
     * @param  list<string>  $quotedCheckKeyList
     */
    public function deleteByQuotedCheckKeys(array $quotedCheckKeyList): void
    {
        if ($quotedCheckKeyList === []) {
            return;
        }

        $userMailNotificationTable = Tables::userMailNotification();
        $quotedCheckKeysCsv = implode(',', $quotedCheckKeyList);
        $this->conn->executeStatement(<<<SQL
            DELETE FROM {$userMailNotificationTable} WHERE check_key IN ({$quotedCheckKeysCsv})
            SQL);
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
