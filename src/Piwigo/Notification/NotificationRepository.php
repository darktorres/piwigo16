<?php

declare(strict_types=1);

namespace Piwigo\Notification;

use Doctrine\DBAL\ArrayParameterType;
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
        string $idField, string $usernameField, string $emailField, string $usersTable
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
           ->setParameter('keys', $checkKeys, \Doctrine\DBAL\ArrayParameterType::STRING);
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
        return (int) $count > 0;
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
        $userIdField   = (string) ($userFields['id'] ?? 'id');
        $usernameField = (string) ($userFields['username'] ?? 'username');
        $emailField    = (string) ($userFields['email'] ?? 'mail_address');

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
               ->setParameter('enabledSend', 'true');
        }

        if ($checkKeyList !== []) {
            $qb->andWhere($qb->expr()->in('N.check_key', ':checkKeys'))
               ->setParameter('checkKeys', $checkKeyList, ArrayParameterType::STRING);
        }

        if ($enabledFilter !== null) {
            $qb->andWhere('N.enabled = :enabledFilter')
               ->setParameter('enabledFilter', $enabledFilter ? 'true' : 'false');
        }

        if ($action === 'send') {
            $qb->orderBy('N.last_send')->addOrderBy('username');
        } else {
            $qb->orderBy('username');
        }

        /** @var list<array<string, float|int|string|null>> */
        return $qb->executeQuery()->fetchAllAssociative();
    }
}
