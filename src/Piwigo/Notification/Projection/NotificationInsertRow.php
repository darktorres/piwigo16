<?php

declare(strict_types=1);

namespace Piwigo\Notification\Projection;

/**
 * {@see \Piwigo\Notification\NotificationByMailRepository::
 * insertNotifications()}'s own `user_mail_notification` insert row --
 * {@see \Piwigo\Controller\Admin\NotificationByMailSubController}'s
 * real (and only) caller already builds it as a known, finite field set.
 */
final readonly class NotificationInsertRow
{
    public function __construct(
        public int $userId,
        public string $checkKey,
        public int $enabled,
    ) {}

    /**
     * @return array{user_id: int, check_key: string, enabled: int}
     */
    public function toArray(): array
    {
        return [
            'user_id' => $this->userId,
            'check_key' => $this->checkKey,
            'enabled' => $this->enabled,
        ];
    }
}
