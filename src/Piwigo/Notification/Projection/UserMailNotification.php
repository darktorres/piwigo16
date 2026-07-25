<?php

declare(strict_types=1);

namespace Piwigo\Notification\Projection;

/**
 * Typed row shape for
 * {@see \Piwigo\Notification\NotificationByMailRepository::findUserNotifications()}
 * (P17-23 Stage 1b, Notification domain) -- a `user_mail_notification`/
 * `users`/`user_infos` join. `fromRow()` centralises the narrowing
 * {@see \Piwigo\Mail\NotificationByMailSender} used to duplicate across
 * ~20 `$nbmUser['x']` accesses spread over several of its own methods.
 *
 * `enabled` stays `?string`, matching the real tinyint(1) column's own
 * driver-returned representation -- {@see \Piwigo\Db\SqlDialect::getBoolean()}
 * (its one real consumer, in `NotificationByMailSubController`) already
 * accepts `mixed`, so no narrower type is needed here.
 */
final readonly class UserMailNotification
{
    public function __construct(
        public int $userId,
        public string $checkKey,
        public string $username,
        public string $mailAddress,
        public ?string $enabled,
        public ?string $lastSend,
        public ?string $status,
    ) {}

    /**
     * @param array<string, mixed> $row a {@see \Piwigo\Notification\NotificationByMailRepository::findUserNotifications()} row
     */
    public static function fromRow(array $row): self
    {
        return new self(
            userId: is_numeric($row['user_id'] ?? null) ? (int) $row['user_id'] : 0,
            checkKey: is_string($row['check_key'] ?? null) ? $row['check_key'] : '',
            username: is_string($row['username'] ?? null) ? $row['username'] : '',
            mailAddress: is_string($row['mail_address'] ?? null) ? $row['mail_address'] : '',
            enabled: is_string($row['enabled'] ?? null) ? $row['enabled'] : null,
            lastSend: is_string($row['last_send'] ?? null) ? $row['last_send'] : null,
            status: is_string($row['status'] ?? null) ? $row['status'] : null,
        );
    }

    /**
     * @return array{user_id: int, check_key: string, username: string,
     *   mail_address: string, enabled: ?string, last_send: ?string, status: ?string}
     */
    public function toArray(): array
    {
        return [
            'user_id' => $this->userId,
            'check_key' => $this->checkKey,
            'username' => $this->username,
            'mail_address' => $this->mailAddress,
            'enabled' => $this->enabled,
            'last_send' => $this->lastSend,
            'status' => $this->status,
        ];
    }
}
