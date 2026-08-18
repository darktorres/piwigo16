<?php

declare(strict_types=1);

namespace Piwigo\Notification;

use Doctrine\DBAL\ArrayParameterType;
use Doctrine\ORM\EntityRepository;
use Doctrine\ORM\Query\Expr\Join;
use Override;
use Piwigo\Common\ValueObject\Email;
use Piwigo\Common\ValueObject\UserId;
use Piwigo\Common\ValueObject\Username;
use Piwigo\Db\BatchWriter;
use Piwigo\Notification\Projection\UserMailNotification;
use Piwigo\Users\UserEntity;
use Piwigo\Users\UserInfoEntity;
use Piwigo\Users\UserRelatedTableSyncInterface;

/**
 * Persistence layer for the 2 genuinely data-touching functions in
 * `admin/include/functions_notification_by_mail.inc.php` -- everything
 * else there (the `$env_nbm`/`$page`/mail-template "workflow" lifecycle:
 * begin/set/unset/end, counters, message pushing) has no DB access of its
 * own and stays procedural, same "$page/$template glue stays in the
 * free-function delegate" split as every other domain.
 *
 * Real DQL against {@see UserMailNotificationEntity}.
 * `Notification` is `L2bExtendedDomain`;
 * `Users` is `L2aCoreDomain` -- a downward dependency, not a
 * `deleteSiteRow`-class layer violation.
 *
 * @extends EntityRepository<UserMailNotificationEntity>
 */
final class NotificationByMailRepository extends EntityRepository implements UserRelatedTableSyncInterface
{
    public function countByCheckKey(string $checkKey): int
    {
        $count = $this->createQueryBuilder('n')
            ->select('COUNT(n.userId)')
            ->where('n.checkKey = :checkKey')
            ->setParameter('checkKey', $checkKey)
            ->getQuery()
            ->getSingleScalarResult();

        return is_numeric($count) ? (int) $count : 0;
    }

    /**
     * $checkKeyList IS bound -- [SEC-18]-class improvement over the
     * original's unescaped `'\'' . $s . '\''` string-literal quoting.
     * `NotificationByMailSender::quoteCheckKeyList()` used to reproduce
     * that same unescaped quoting for {@see deleteByCheckKeys()}'s own
     * now-former "already-quoted" input -- removed (its only real
     * caller), see that method's own docblock.
     *
     * `users`'s columns are fixed ({@see \Piwigo\Users\UserEntity}).
     *
     * `$enabledFilterValue`'s one real caller ({@see \Piwigo\Notification\
     * NotificationByMailService::getUserNotifications()}) only ever
     * passes `''`, `'0'`, or `'1'` (already normalized via
     * `SqlDialect::booleanToInt()`), so binding it against `n.enabled`'s
     * DQL `boolean` type is safe (`(bool) '0'` is `false`, `(bool) '1'`
     * is `true` -- PHP's own string-to-bool coercion for these 2
     * specific literals).
     *
     * @param  list<string>  $checkKeyList
     * @return list<UserMailNotification>
     */
    public function findUserNotifications(
        string $action,
        array $checkKeyList,
        string $enabledFilterValue,
    ): array {
        $qb = $this->createQueryBuilder('n')
            ->select('n.userId AS user_id', 'n.checkKey AS check_key', 'u.username AS username', 'u.mailAddress AS mail_address', 'n.enabled', 'n.lastSend AS last_send', 'ui.status')
            ->innerJoin(UserEntity::class, 'u', Join::WITH, 'n.userId = u.id')
            ->innerJoin(UserInfoEntity::class, 'ui', Join::WITH, 'ui.user = n.userId');

        if ($action === 'send') {
            $qb->andWhere('n.enabled = :enabledSend')
                ->andWhere('u.mailAddress IS NOT NULL')
                ->setParameter('enabledSend', true);
        }

        if ($checkKeyList !== []) {
            $qb->andWhere('n.checkKey IN (:checkKeys)')
                ->setParameter('checkKeys', $checkKeyList);
        }

        if ($enabledFilterValue !== '') {
            $qb->andWhere('n.enabled = :enabledFilter')
                ->setParameter('enabledFilter', (bool) $enabledFilterValue);
        }

        if ($action === 'send') {
            $qb->orderBy('n.lastSend')
                ->addOrderBy('u.username');
        } else {
            $qb->orderBy('u.username');
        }

        $rows = $qb->getQuery()
            ->getArrayResult();

        $notifications = [];
        foreach ($rows as $row) {
            if (! is_array($row)) {
                continue;
            }

            $userId = $row['user_id'] ?? null;
            $username = $row['username'] ?? null;
            $mailAddress = $row['mail_address'] ?? null;
            $notifications[] = UserMailNotification::fromRow([
                'user_id' => $userId instanceof UserId ? $userId->value : $userId,
                'check_key' => $row['check_key'] ?? null,
                'username' => $username instanceof Username ? $username->value : null,
                'mail_address' => $mailAddress instanceof Email ? $mailAddress->value : null,
                'enabled' => $row['enabled'] ?? null,
                'last_send' => $row['last_send'] ?? null,
                'status' => $row['status'] ?? null,
            ]);
        }

        return $notifications;
    }

    /**
     * Sets every blank (trimmed-empty) email address to NULL --
     * Controller\Admin\NotificationByMailSubController::
     * insertNewDataUserMailNotification()'s own pre-sync cleanup, so
     * `WHERE email IS NOT NULL` below can't false-negative on a
     * whitespace-only address. No multi-auth column-name param -- see
     * {@see findUserNotifications()}'s own docblock.
     *
     * `users` is mapped ({@see UserEntity}), and DQL's built-in `TRIM()`
     * function is portable, same default both-sides-whitespace semantics
     * as the original raw SQL's bare `TRIM(mail_address)`.
     */
    public function nullifyBlankEmails(): void
    {
        $em = $this->getEntityManager();
        $em->createQueryBuilder()
            ->update(UserEntity::class, 'u')
            ->set('u.mailAddress', 'NULL')
            ->where("TRIM(u.mailAddress) = ''")
            ->getQuery()
            ->execute();
        $em->clear();
    }

    /**
     * user_id/username/mail_address for every user with a real email
     * address but no `user_mail_notification` row yet -- Controller\Admin\
     * NotificationByMailSubController's own "which users need a fresh
     * subscription row" step.
     *
     * No multi-auth column-name params -- see
     * {@see findUserNotifications()}'s own docblock.
     *
     * The anti-join uses `Join::WITH` (no formal ORM association between
     * `UserEntity`/`UserMailNotificationEntity`), same pattern as
     * {@see \Piwigo\Tag\TagRepository::findOrphanTags()}.
     *
     * @return list<array<string, mixed>>
     */
    public function findUsersWithoutNotificationRow(): array
    {
        $rows = $this->getEntityManager()
            ->createQueryBuilder()
            ->select('u.id AS user_id', 'u.username AS username', 'u.mailAddress AS mail_address')
            ->from(UserEntity::class, 'u')
            ->leftJoin(UserMailNotificationEntity::class, 'm', Join::WITH, 'u.id = m.userId')
            ->where('u.mailAddress IS NOT NULL')
            ->andWhere('m.userId IS NULL')
            ->orderBy('u.id')
            ->getQuery()
            ->getArrayResult();

        $result = [];
        foreach ($rows as $row) {
            if (! is_array($row)) {
                continue;
            }

            $userId = $row['user_id'] ?? null;
            $username = $row['username'] ?? null;
            $mailAddress = $row['mail_address'] ?? null;
            $result[] = [
                'user_id' => $userId instanceof UserId ? $userId->value : $userId,
                'username' => $username instanceof Username ? $username->value : null,
                'mail_address' => $mailAddress instanceof Email ? $mailAddress->value : null,
            ];
        }

        return $result;
    }

    /**
     * Deletes rows by check_key.
     *
     * $checkKeyList is bound, not quoted -- the original construction
     * quoted it via NotificationByMailSender::quoteCheckKeyList()
     * (`'\'' . $s . '\''`, manual wrapping with zero escaping) and
     * spliced it straight into the query text; its own docblock said
     * "$checkKeyList may come straight from $_POST", which would have
     * made this a real SQL injection had that path ever been live. The
     * one real caller (Controller\Admin\NotificationByMailSubController::
     * insertNewDataUserMailNotification()) always generates
     * $check_key_list server-side
     * (NotificationByMailSender::findAvailableCheckKey()), never from
     * $_POST, so it was not exploitable in practice -- but the
     * construction style itself was still worth fixing.
     * quoteCheckKeyList() had this as its only real caller and was
     * removed entirely; this method now takes plain, unquoted check keys
     * and binds them.
     *
     * @param  list<string>  $checkKeyList
     */
    public function deleteByCheckKeys(array $checkKeyList): void
    {
        if ($checkKeyList === []) {
            return;
        }

        $em = $this->getEntityManager();
        $em->createQueryBuilder()
            ->delete(UserMailNotificationEntity::class, 'n')
            ->where('n.checkKey IN (:checkKeys)')
            ->setParameter('checkKeys', $checkKeyList, ArrayParameterType::STRING)
            ->getQuery()
            ->execute();
        $em->clear();
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

        new BatchWriter($this->getEntityManager()->getConnection())
            ->massInsert('user_mail_notification', ['user_id', 'check_key', 'enabled'], $inserts);
    }

    /**
     * {@see UserRelatedTableSyncInterface} implementation --
     * {@see \Piwigo\Users\UserService::syncUsers()}'s own permalink-domain
     * counterpart is {@see \Piwigo\Category\OldPermalinkLookupInterface}.
     * `getSingleColumnResult()` never applies custom Doctrine Type
     * conversion, so `n.userId` comes back as a plain scalar despite being
     * `UserId`-typed.
     *
     * @return list<UserId>
     */
    #[Override]
    public function findDistinctUserIds(): array
    {
        $rows = $this->createQueryBuilder('n')
            ->select('DISTINCT n.userId')
            ->getQuery()
            ->getSingleColumnResult();

        $ids = [];
        foreach ($rows as $row) {
            $id = $row instanceof UserId ? $row : UserId::tryFrom($row);
            if ($id instanceof UserId) {
                $ids[] = $id;
            }
        }

        return $ids;
    }

    /**
     * {@see UserRelatedTableSyncInterface} implementation.
     *
     * @param list<UserId> $userIds
     */
    #[Override]
    public function deleteForUserIds(array $userIds): void
    {
        if ($userIds === []) {
            return;
        }

        $em = $this->getEntityManager();
        $em->createQueryBuilder()
            ->delete(UserMailNotificationEntity::class, 'n')
            ->where('n.userId IN (:userIds)')
            ->setParameter('userIds', array_map(static fn (UserId $id): int => $id->value, $userIds), ArrayParameterType::INTEGER)
            ->getQuery()
            ->execute();
        $em->clear();
    }
}
