<?php

declare(strict_types=1);

namespace Piwigo\Users;

use Piwigo\Common\ValueObject\UserId;

/**
 * Seam {@see UserService::syncUsers()} takes as explicit parameters (not
 * constructor-injected -- same reasoning as
 * {@see \Piwigo\Category\OldPermalinkLookupInterface}'s own docblock:
 * `Users` is `L2aCoreDomain`, and this interface's 2 real implementations
 * ({@see \Piwigo\Notification\NotificationByMailRepository}/
 * {@see \Piwigo\Feed\FeedRepository}) are both `L2bExtendedDomain`, so
 * constructor injection would just relocate the deptrac violation to
 * whichever caller constructs `UserService`).
 *
 * Replaces {@see UserRepository::findDistinctUserIdsInTable()}/
 * {@see UserRepository::deleteUsersFromTable()}'s old raw-table-name DBAL
 * pair -- `user_mail_notification`/`user_feed` both carry a plain scalar
 * `user_id` column (no association, no join needed the way
 * `old_permalinks.cat_id` needed one against `categories`), so each
 * implementation is a single-table DQL query.
 */
interface UserRelatedTableSyncInterface
{
    /**
     * @return list<UserId>
     */
    public function findDistinctUserIds(): array;

    /**
     * @param list<UserId> $userIds
     */
    public function deleteForUserIds(array $userIds): void;
}
