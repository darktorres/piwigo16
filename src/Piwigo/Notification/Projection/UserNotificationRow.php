<?php

declare(strict_types=1);

namespace Piwigo\Notification\Projection;

/** (user_id, check_key, username, mail_address, enabled, last_send, status) row from NotificationRepository::getUserNotifications(). */
final readonly class UserNotificationRow
{
    public function __construct(
        public int $userId,
        public string $checkKey,
        public string $username,
        public string $mailAddress,
        public bool $enabled,
        public ?string $lastSend,
        public string $status,
    ) {
    }

    /** @param array<string, mixed> $row */
    public static function fromRow(array $row): self
    {
        $enabledRaw = $row['enabled'] ?? null;
        return new self(
            userId:      is_numeric($row['user_id'] ?? null) ? (int) $row['user_id'] : 0,
            checkKey:    is_string($row['check_key'] ?? null) ? $row['check_key'] : '',
            username:    is_string($row['username'] ?? null) ? $row['username'] : '',
            mailAddress: is_string($row['mail_address'] ?? null) ? $row['mail_address'] : '',
            enabled:     is_bool($enabledRaw) ? $enabledRaw : (is_numeric($enabledRaw) ? (int) $enabledRaw !== 0 : false),
            lastSend:    is_string($row['last_send'] ?? null) ? $row['last_send'] : null,
            status:      is_string($row['status'] ?? null) ? $row['status'] : '',
        );
    }
}
