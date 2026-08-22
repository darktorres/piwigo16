<?php

declare(strict_types=1);

namespace Piwigo\Notification;

use Piwigo\Db\SqlDialect;
use Piwigo\Notification\Projection\NotificationInsertRow;
use Piwigo\Notification\Projection\UserMailNotification;
use Piwigo\Notification\Projection\UserWithoutNotificationRow;
use Piwigo\Session\SessionService;

/**
 * The 2 genuinely data-touching functions of the admin bulk-subscription
 * -by-mail workflow (`admin/include/functions_notification_by_mail.inc.php`).
 * Does NOT constructor-inject `Piwigo\Mail\MailService` -- Mail sits at
 * L3Presentation (real `Piwigo\Template\Template` dependency, see
 * deptrac.yaml's own note on that placement) and this domain's own
 * L2bExtendedDomain layer may not depend on it, same "call the
 * not-yet-injectable cross-layer capability as a free function
 * (`pwg_mail()`)" shape already established for Comment/Users --
 * the one real `pwg_mail()` call site
 * (`do_subscribe_unsubscribe_notification_by_mail()`) stays procedural.
 */
final readonly class NotificationByMailService
{
    public function __construct(
        private NotificationByMailRepository $repo,
        private SessionService $sessionService,
    ) {}

    public function findAvailableCheckKey(): string
    {
        while (true) {
            $key = $this->sessionService->generateKey(16);
            if ($this->repo->countByCheckKey($key) === 0) {
                return $key;
            }
        }
    }

    /**
     * @param  array<array-key, mixed>  $checkKeyList  filtered to string
     *   elements here -- may come straight from $_POST
     *   (admin/notification_by_mail.php)
     * @return list<UserMailNotification>
     */
    public function getUserNotifications(string $action, array $checkKeyList, bool|string $enabledFilterValue): array
    {
        if (! in_array($action, ['subscribe', 'send'], true)) {
            return [];
        }

        // Matches the original's `$enabled_filter_value != ''` loose
        // comparison exactly: against a bool|string value, PHP casts the
        // '' side to bool for a bool operand, so a bare `false` means "no
        // filter" (same as an empty string) while `true` always filters --
        // only a non-empty *string* value is compared as a string. Real
        // callers rely on this: do_subscribe_unsubscribe_notification_by_mail()
        // passes `! $is_subscribe` (a bool) as this parameter.
        $hasFilter = is_bool($enabledFilterValue) ? $enabledFilterValue : $enabledFilterValue !== '';

        // enabled is a real tinyint(1) column -- a numeric string, not
        // the old enum('true','false') string; findUserNotifications() binds
        // this straight through as a query parameter.
        $enabledFilterString = '';
        if ($hasFilter) {
            $converted = SqlDialect::booleanToInt($enabledFilterValue);
            $enabledFilterString = is_int($converted) ? (string) $converted : '';
        }

        return $this->repo->findUserNotifications(
            $action,
            array_values(array_filter($checkKeyList, is_string(...))),
            $enabledFilterString,
        );
    }

    public function nullifyBlankEmails(): void
    {
        $this->repo->nullifyBlankEmails();
    }

    /**
     * @return list<UserWithoutNotificationRow>
     */
    public function getUsersWithoutNotificationRow(): array
    {
        return $this->repo->findUsersWithoutNotificationRow();
    }

    /**
     * @param  list<string>  $checkKeyList
     */
    public function deleteByCheckKeys(array $checkKeyList): void
    {
        $this->repo->deleteByCheckKeys($checkKeyList);
    }

    /**
     * @param list<NotificationInsertRow> $inserts
     */
    public function insertNotifications(array $inserts): void
    {
        $this->repo->insertNotifications($inserts);
    }
}
