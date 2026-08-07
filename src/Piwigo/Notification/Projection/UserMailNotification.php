<?php

declare(strict_types=1);

namespace Piwigo\Notification\Projection;

use InvalidArgumentException;
use Piwigo\Common\ValueObject\UserId;
use Piwigo\Users\UserStatus;

/**
 * Typed row shape for
 * {@see \Piwigo\Notification\NotificationByMailRepository::findUserNotifications()},
 * a `user_mail_notification`/`users`/`user_infos` join. `fromRow()`
 * centralises the narrowing that {@see \Piwigo\Mail\NotificationByMailSender}
 * would otherwise duplicate across ~20 `$nbmUser['x']` accesses spread
 * over several of its own methods.
 *
 * `enabled` is a real `bool`, not `?string` -- `user_mail_notification.
 * enabled` is `UserMailNotificationEntity`'s own `bool` column, and its one
 * real consumer ({@see \Piwigo\Db\SqlDialect::getBoolean()}, called from
 * `NotificationByMailSubController`) just re-derives the same bool a
 * tinyint(1) driver read already gives natively -- nothing left to
 * conditionally convert once `fromRow()` does the `(bool)` cast once, here.
 *
 * `userId` is `UserId`, not `?UserId` -- `n.userId = u.id`/`ui.userId =
 * n.userId` are both real `INNER JOIN`s in the one query this projects,
 * so a row can't exist without a valid user id, same reasoning as
 * {@see \Piwigo\Rate\Projection\Rate}'s `userId`.
 */
final readonly class UserMailNotification
{
    public function __construct(
        public UserId $userId,
        public string $checkKey,
        public string $username,
        public string $mailAddress,
        public bool $enabled,
        public ?string $lastSend,
        public ?string $status,
    ) {}

    /**
     * @param array<string, mixed> $row a {@see \Piwigo\Notification\NotificationByMailRepository::findUserNotifications()} row
     */
    public static function fromRow(array $row): self
    {
        $userIdValue = $row['user_id'] ?? null;
        $userId = $userIdValue instanceof UserId ? $userIdValue : UserId::tryFrom($userIdValue);
        if ($userId === null) {
            throw new InvalidArgumentException(sprintf('Expected a positive user id, got %s', get_debug_type($userIdValue)));
        }

        return new self(
            userId: $userId,
            checkKey: is_string($row['check_key'] ?? null) ? $row['check_key'] : '',
            username: is_string($row['username'] ?? null) ? $row['username'] : '',
            mailAddress: is_string($row['mail_address'] ?? null) ? $row['mail_address'] : '',
            enabled: (bool) ($row['enabled'] ?? false),
            lastSend: is_string($row['last_send'] ?? null) ? $row['last_send'] : null,
            // `status` array-hydrates as a UserStatus instance, not a raw string.
            status: ($row['status'] ?? null) instanceof UserStatus ? $row['status']->value : null,
        );
    }

    /**
     * @return array{user_id: int, check_key: string, username: string,
     *   mail_address: string, enabled: bool, last_send: ?string, status: ?string}
     */
    public function toArray(): array
    {
        return [
            'user_id' => $this->userId->value,
            'check_key' => $this->checkKey,
            'username' => $this->username,
            'mail_address' => $this->mailAddress,
            'enabled' => $this->enabled,
            'last_send' => $this->lastSend,
            'status' => $this->status,
        ];
    }
}
