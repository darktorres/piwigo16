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
 * `enabled` stays `?string`, matching {@see \Piwigo\Db\SqlDialect::getBoolean()}
 * (its one real consumer, in `NotificationByMailSubController`), which
 * already accepts `mixed`, so no narrower type is needed here.
 *
 * Real bug found live: `fromRow()` used to require `is_string($row['enabled'])`
 * before keeping the value, silently nulling it out otherwise -- but this
 * project's own `DbConnection::params()` sets
 * `MYSQLI_OPT_INT_AND_FLOAT_NATIVE => true`, so a real tinyint(1) column
 * comes back as a native `int`, not a string. `enabled` was therefore
 * *always* null for every real row, which meant `SqlDialect::getBoolean()`
 * always saw `false` regardless of the DB's real value -- every enabled
 * user rendered into the "Unsubscribed" (opt_false) option list instead
 * of "Subscribed" (opt_true), and the pre-selection check
 * (`in_array($nbm_user->checkKey, $post['cat_true'], true)`) could never
 * match since it's only ever reached from the opt_true branch. `is_scalar()`
 * + cast to string fixes both int and (still-possible, e.g. a different
 * driver) string representations, matching this project's own established
 * `feedback_dbal_type_filter_silently_drops_rows` precedent.
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
            // Phase 5 Item 21: see \Piwigo\Auth\Projection\AuthUser::fromRow()'s
            // own comment -- `ui.status` array-hydrates as a UserStatus
            // instance now, not a raw string.
            status: ($row['status'] ?? null) instanceof \Piwigo\Users\UserStatus ? $row['status']->value : null,
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
