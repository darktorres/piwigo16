<?php

declare(strict_types=1);

namespace Piwigo\Notification\Projection;

/** (user_id, username, mail_address) row from NotificationRepository::findUsersWithoutNotification(). */
final readonly class NotifiableUserRow
{
    public function __construct(
        public int $userId,
        public string $username,
        public string $mailAddress,
    ) {
    }

    /** @param array<string, mixed> $row */
    public static function fromRow(array $row): self
    {
        return new self(
            userId:      is_numeric($row['user_id'] ?? null) ? (int) $row['user_id'] : 0,
            username:    is_string($row['username'] ?? null) ? $row['username'] : '',
            mailAddress: is_string($row['mail_address'] ?? null) ? $row['mail_address'] : '',
        );
    }
}
