<?php

declare(strict_types=1);

namespace Piwigo\Notification\Projection;

use Piwigo\Users\UserStatus;

/**
 * Typed row shape for
 * {@see \Piwigo\Notification\NotificationByMailRepository::findUserNotifications()},
 * a `user_mail_notification`/`users`/`user_infos` join. `fromRow()`
 * centralises the narrowing that {@see \Piwigo\Mail\NotificationByMailSender}
 * would otherwise duplicate across ~20 `$nbmUser['x']` accesses spread
 * over several of its own methods.
 *
 * `enabled` stays `?string`, matching {@see \Piwigo\Db\SqlDialect::getBoolean()}
 * (its one real consumer, in `NotificationByMailSubController`), which
 * already accepts `mixed`, so no narrower type is needed here.
 *
 * `enabled` is read with `is_scalar()` + cast to string rather than
 * `is_string()`: `DbConnection::params()` sets
 * `MYSQLI_OPT_INT_AND_FLOAT_NATIVE => true`, so a real tinyint(1) column
 * comes back as a native `int`, not a string, and a plain `is_string()`
 * check would always fail.
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
            enabled: is_scalar($row['enabled'] ?? null) ? (string) $row['enabled'] : null,
            lastSend: is_string($row['last_send'] ?? null) ? $row['last_send'] : null,
            // `status` array-hydrates as a UserStatus instance, not a raw string.
            status: ($row['status'] ?? null) instanceof UserStatus ? $row['status']->value : null,
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
