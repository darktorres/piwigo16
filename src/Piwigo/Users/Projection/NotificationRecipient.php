<?php

declare(strict_types=1);

namespace Piwigo\Users\Projection;

use Piwigo\Common\ValueObject\UserId;

/**
 * {@see \Piwigo\Users\UserRepository::findNotificationRecipientsByIds()}'s
 * own row shape -- {@see \Piwigo\Admin\AlbumNotificationPageRenderer}'s own
 * "notify these specific users" mail-merge data, its real (and only)
 * consumer.
 *
 * Every field stays nullable: array hydration always applies each column's
 * custom Doctrine Type (this class's own gotcha #1 territory), so a
 * non-null `$userId`/etc is the only real shape reachable in production --
 * the fallback exists purely for a hydration surprise, same defensive
 * posture the raw row this DTO replaced already had.
 */
final readonly class NotificationRecipient
{
    public function __construct(
        public ?UserId $userId,
        public ?string $status,
        public ?string $language,
        public ?string $email,
        public ?string $username,
    ) {}
}
