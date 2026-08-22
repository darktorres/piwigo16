<?php

declare(strict_types=1);

namespace Piwigo\Controller\Admin\Projection;

/**
 * One row of `notification_by_mail.latte`'s `send`-action `$users` list,
 * built by {@see \Piwigo\Controller\Admin\NotificationByMailSubController::
 * handle()} from a real {@see \Piwigo\Notification\Projection\
 * UserMailNotification}. `$checked` is already the literal HTML attribute
 * string (`'checked="checked"'` or `''`), not a bool -- preserves the
 * original code's own direct-to-markup value.
 */
final readonly class NotificationByMailUserRow
{
    public function __construct(
        public string $id,
        public string $checked,
        public string $username,
        public string $email,
        public ?string $lastSend,
    ) {}

    /**
     * @return array{ID: string, CHECKED: string, USERNAME: string, EMAIL: string, LAST_SEND: ?string}
     */
    public function toArray(): array
    {
        return [
            'ID' => $this->id,
            'CHECKED' => $this->checked,
            'USERNAME' => $this->username,
            'EMAIL' => $this->email,
            'LAST_SEND' => $this->lastSend,
        ];
    }
}
