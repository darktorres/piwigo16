<?php

declare(strict_types=1);

namespace Piwigo\Auth;

use Piwigo\Core\AccessLevel;

/**
 * Current-request access-level checks: status/ACCESS_* introspection for
 * the legacy `global $user;`/`global $conf;` bridge arrays. Static, no
 * constructor -- every method is a pure read of those two globals (same
 * "global read inside a service method" shape already established by
 * TagService::addLevelToTags()/NotificationService's news() family), zero
 * DB access, so a static utility (matching AccessLevel's own static-const
 * convention) is cheaper for ~250 combined call sites than instance
 * construction.
 *
 * P23 batch 8d: ported from include/functions_user.inc.php's
 * get_user_status()/get_access_type_status()/is_autorize_status()/
 * check_status()/is_generic()/is_a_guest()/is_classic_user()/is_admin()/
 * is_webmaster()/can_manage_comment(). Deliberately does NOT read
 * Piwigo\Users\CurrentUser -- that's a separate, not-yet-unified P17-P23
 * bridge value object (its own docblock: "deleted in P23"); unifying it
 * with `global $user` is out of this batch's scope (P23 batch 8g/9).
 */
final class AccessControl
{
    private function __construct() {}

    public static function getUserStatus(string $userStatus = ''): string
    {
        /** @var array<string, mixed> $user */
        global $user;

        if ($userStatus === '') {
            if (isset($user['status']) && is_string($user['status'])) {
                return $user['status'];
            }

            return '';
        }

        return $userStatus;
    }

    public static function getAccessTypeStatus(string $userStatus = ''): int
    {
        /** @var array<string, mixed> $conf */
        global $conf;

        return match (self::getUserStatus($userStatus)) {
            'guest' => (bool) $conf['guest_access'] ? AccessLevel::Guest : AccessLevel::Free,
            'generic' => AccessLevel::Guest,
            'normal' => AccessLevel::Classic,
            'admin' => AccessLevel::Administrator,
            'webmaster' => AccessLevel::Webmaster,
            default => AccessLevel::Free,
        };
    }

    public static function isAuthorizeStatus(int $accessType, string $userStatus = ''): bool
    {
        return self::getAccessTypeStatus($userStatus) >= $accessType;
    }

    public static function checkStatus(int $accessType, string $userStatus = ''): void
    {
        if (! self::isAuthorizeStatus($accessType, $userStatus)) {
            access_denied();
        }
    }

    public static function isGeneric(string $userStatus = ''): bool
    {
        return self::getUserStatus($userStatus) === 'generic';
    }

    public static function isAGuest(string $userStatus = ''): bool
    {
        return self::getUserStatus($userStatus) === 'guest';
    }

    public static function isClassicUser(string $userStatus = ''): bool
    {
        return self::isAuthorizeStatus(AccessLevel::Classic, $userStatus);
    }

    public static function isAdmin(string $userStatus = ''): bool
    {
        return self::isAuthorizeStatus(AccessLevel::Administrator, $userStatus);
    }

    public static function isWebmaster(string $userStatus = ''): bool
    {
        return self::isAuthorizeStatus(AccessLevel::Webmaster, $userStatus);
    }

    public static function canManageComment(string $action, int|string $commentAuthorId): bool
    {
        /**
         * @var array<string, mixed> $user
         * @var array<string, mixed> $conf
         */
        global $user, $conf;

        if (self::isAGuest()) {
            return false;
        }

        if (! in_array($action, ['delete', 'edit', 'validate'], true)) {
            return false;
        }

        if (self::isAdmin()) {
            return true;
        }

        if ($action === 'edit' && (bool) $conf['user_can_edit_comment']) {
            if ($commentAuthorId == $user['id']) {
                return true;
            }
        }

        if ($action === 'delete' && (bool) $conf['user_can_delete_comment']) {
            if ($commentAuthorId == $user['id']) {
                return true;
            }
        }

        return false;
    }
}
