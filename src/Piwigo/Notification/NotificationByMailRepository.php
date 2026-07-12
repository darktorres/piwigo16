<?php

declare(strict_types=1);

namespace Piwigo\Notification;

use Piwigo\Db\AbstractRepository;
use Piwigo\Db\Tables;

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
        $count = $this->conn->executeQuery(
            'SELECT COUNT(*) FROM ' . Tables::userMailNotification() . ' WHERE check_key = ?',
            [$checkKey]
        )->fetchOne();

        return is_numeric($count) ? (int) $count : 0;
    }

    /**
     * $usernameField/$emailField/$idField are trusted admin-configured
     * column names ($conf['user_fields'], not user input -- can't be bound
     * as parameters anyway, SQL doesn't allow placeholders for
     * identifiers). $checkKeyList, by contrast, IS bound -- [SEC-18]-class
     * improvement over the original's unescaped `'\'' . $s . '\''`
     * string-literal quoting (`quote_check_key_list()`, still used
     * unchanged by admin/notification_by_mail.php's own out-of-scope raw
     * query, kept untouched here).
     *
     * Every column is normalized to string|null, matching the legacy
     * pwg_db_fetch_assoc()'s own contract -- downstream callers
     * (set_user_on_env_nbm()/inc_mail_sent_success()/etc., untouched by
     * this port) are all typed against that exact shape.
     *
     * @param  list<string>  $checkKeyList
     * @return list<array<string, string|null>>
     */
    public function findUserNotifications(
        string $action,
        array $checkKeyList,
        string $enabledFilterValue,
        string $usernameField,
        string $emailField,
        string $idField
    ): array {
        $sql = 'SELECT N.user_id, N.check_key, U.' . $usernameField . ' AS username, U.' . $emailField . ' AS mail_address,'
            . ' N.enabled, N.last_send, UI.status'
            . ' FROM ' . Tables::userMailNotification() . ' AS N'
            . ' JOIN ' . Tables::users() . ' AS U ON N.user_id = U.' . $idField
            . ' JOIN ' . Tables::userInfos() . ' AS UI ON UI.user_id = N.user_id'
            . ' WHERE 1=1';
        $params = [];

        if ($action === 'send') {
            $sql .= ' AND N.enabled = ? AND U.' . $emailField . ' IS NOT NULL';
            $params[] = 'true';
        }

        if ($checkKeyList !== []) {
            $sql .= ' AND N.check_key IN (' . implode(',', array_fill(0, count($checkKeyList), '?')) . ')';
            array_push($params, ...$checkKeyList);
        }

        if ($enabledFilterValue !== '') {
            $sql .= ' AND N.enabled = ?';
            $params[] = $enabledFilterValue;
        }

        $sql .= $action === 'send' ? ' ORDER BY N.last_send, username' : ' ORDER BY username';

        $rows = $this->conn->executeQuery($sql, $params)
            ->fetchAllAssociative();

        return array_map(
            static fn (array $row): array => array_map(
                static fn (mixed $value): string|null => is_scalar($value) ? (string) $value : null,
                $row
            ),
            $rows
        );
    }
}
