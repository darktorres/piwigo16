<?php

declare(strict_types=1);

namespace Piwigo\Notification\Projection;

/**
 * {@see \Piwigo\Notification\NotificationByMailRepository::
 * findUsersWithoutNotificationRow()}'s own row shape --
 * {@see \Piwigo\Controller\Admin\NotificationByMailSubController}'s real
 * (and only) consumer, its "which users need a fresh subscription row"
 * step.
 */
final readonly class UserWithoutNotificationRow
{
    public function __construct(
        public int $userId,
        public ?string $username,
        public ?string $mailAddress,
    ) {}
}
