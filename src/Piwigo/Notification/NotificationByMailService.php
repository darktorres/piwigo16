<?php

declare(strict_types=1);

namespace Piwigo\Notification;

use Piwigo\Session\SessionService;

/**
 * The 2 genuinely data-touching functions of the admin bulk-subscription
 * -by-mail workflow (`admin/include/functions_notification_by_mail.inc.php`).
 * Does NOT constructor-inject `Piwigo\Mail\MailService` -- Mail sits at
 * L3Presentation (real `Piwigo\Template\Template` dependency, see
 * deptrac.yaml's own note on that placement) and this domain's own
 * L2bExtendedDomain layer may not depend on it, same "call the
 * not-yet-injectable cross-layer capability as a free function
 * (`pwg_mail()`)" shape already established for Comment/Users in P18 --
 * the one real `pwg_mail()` call site
 * (`do_subscribe_unsubscribe_notification_by_mail()`) stays procedural.
 */
final readonly class NotificationByMailService
{
    public function __construct(
        private NotificationByMailRepository $repo,
    ) {}

    public function findAvailableCheckKey(): string
    {
        while (true) {
            $key = SessionService::get()->generateKey(16);
            if ($this->repo->countByCheckKey($key) === 0) {
                return $key;
            }
        }
    }

    /**
     * @param  array<array-key, mixed>  $checkKeyList  filtered to string
     *   elements here -- may come straight from $_POST
     *   (admin/notification_by_mail.php)
     * @return list<array<string, string|null>>
     */
    public function getUserNotifications(string $action, array $checkKeyList, bool|string $enabledFilterValue): array
    {
        if (! in_array($action, ['subscribe', 'send'], true)) {
            return [];
        }

        $userFields = \Piwigo\Config\Config::userFields();
        $usernameField = $userFields['username'] ?? 'username';
        $usernameField = is_string($usernameField) ? $usernameField : 'username';
        $emailField = $userFields['email'] ?? 'email';
        $emailField = is_string($emailField) ? $emailField : 'email';
        $idField = $userFields['id'] ?? 'id';
        $idField = is_string($idField) ? $idField : 'id';

        // Matches the original's `$enabled_filter_value != ''` loose
        // comparison exactly: against a bool|string value, PHP casts the
        // '' side to bool for a bool operand, so a bare `false` means "no
        // filter" (same as an empty string) while `true` always filters --
        // only a non-empty *string* value is compared as a string. Real
        // callers rely on this: do_subscribe_unsubscribe_notification_by_mail()
        // passes `! $is_subscribe` (a bool) as this parameter.
        $hasFilter = is_bool($enabledFilterValue) ? $enabledFilterValue : $enabledFilterValue !== '';

        $enabledFilterString = '';
        if ($hasFilter) {
            $converted = \Piwigo\Db\SqlDialect::booleanToString($enabledFilterValue);
            $enabledFilterString = is_string($converted) ? $converted : '';
        }

        return $this->repo->findUserNotifications(
            $action,
            array_values(array_filter($checkKeyList, is_string(...))),
            $enabledFilterString,
            $usernameField,
            $emailField,
            $idField
        );
    }
}
