<?php

declare(strict_types=1);

namespace Piwigo\Auth;

use Piwigo\Core\AccessLevel;
use Piwigo\Core\HtmlRenderingInterface;

/**
 * Current-request access-level checks: status/ACCESS_* introspection,
 * reading Piwigo\Users\CurrentUser (Legacy Coupling Retirement Track A
 * batch A3 -- previously the legacy `global $user;` bridge array directly)
 * and `global $conf;`. Static, no constructor -- every method is a pure
 * read (same "global read inside a service method" shape already
 * established by TagService::addLevelToTags()/NotificationService's news()
 * family), zero DB access, so a static utility (matching AccessLevel's own
 * static-const convention) is cheaper for ~250 combined call sites than
 * instance construction.
 *
 * P23 batch 8d: ported from include/functions_user.inc.php's
 * get_user_status()/get_access_type_status()/is_autorize_status()/
 * check_status()/is_generic()/is_a_guest()/is_classic_user()/is_admin()/
 * is_webmaster()/can_manage_comment().
 */
final class AccessControl
{
    private function __construct() {}

    private static ?HtmlRenderingInterface $htmlRenderer = null;

    /**
     * Set once by include/common.inc.php (legacy, not subject to deptrac) --
     * same static-setter shape as Piwigo\Core\Lang::setDefaultLanguageProvider(),
     * chosen for the same reason: checkStatus() is this class's own primary,
     * near-universal entry point (36 real call sites), so constructor/
     * per-method injection would ripple across every one of them for zero
     * real benefit.
     */
    public static function setHtmlRenderer(HtmlRenderingInterface $renderer): void
    {
        self::$htmlRenderer = $renderer;
    }

    public static function getUserStatus(string $userStatus = ''): string
    {
        if ($userStatus === '') {
            return \Piwigo\Users\CurrentUser::get()->status->value;
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
            if (self::$htmlRenderer !== null) {
                self::$htmlRenderer->accessDenied();
            }
            throw new \RuntimeException('Access denied');
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
        /** @var array<string, mixed> $conf */
        global $conf;

        if (self::isAGuest()) {
            return false;
        }

        if (! in_array($action, ['delete', 'edit', 'validate'], true)) {
            return false;
        }

        if (self::isAdmin()) {
            return true;
        }

        $currentUserId = \Piwigo\Users\CurrentUser::get()->id;

        if ($action === 'edit' && (bool) $conf['user_can_edit_comment']) {
            if ((int) $commentAuthorId === $currentUserId) {
                return true;
            }
        }

        if ($action === 'delete' && (bool) $conf['user_can_delete_comment']) {
            if ((int) $commentAuthorId === $currentUserId) {
                return true;
            }
        }

        return false;
    }
}
