<?php

declare(strict_types=1);

namespace Piwigo\Notification;

use Doctrine\ORM\Mapping as ORM;
use Piwigo\Common\ValueObject\UserId;

/**
 * Maps the `user_mail_notification` table. `user_id` is the PK,
 * application-assigned (the `users` row's own id -- never auto-generated here,
 * same shape as {@see \Piwigo\Users\UserInfoEntity}). `check_key` carries its
 * own separate UNIQUE constraint. `last_send` stays a plain nullable string,
 * not `\DateTimeImmutable` -- every real consumer ({@see
 * \Piwigo\Notification\Projection\UserMailNotification}) already expects the
 * raw DB DATETIME string form.
 */
#[ORM\Entity(repositoryClass: NotificationByMailRepository::class)]
#[ORM\Table(name: 'user_mail_notification')]
final class UserMailNotificationEntity
{
    public function __construct(
        #[ORM\Id]
        #[ORM\Column(name: 'user_id', type: 'user_id')]
        public UserId $userId,
        #[ORM\Column(name: 'check_key', type: 'string', length: 16)]
        public string $checkKey,
        #[ORM\Column(type: 'boolean')]
        public bool $enabled,
        #[ORM\Column(name: 'last_send', type: 'string', length: 19, nullable: true)]
        public ?string $lastSend,
    ) {}
}
