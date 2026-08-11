<?php

declare(strict_types=1);

namespace Piwigo\Auth;

use Piwigo\Config\CurrentConfig;
use Piwigo\Core\AccessLevel;
use Piwigo\Users\CurrentUser;

/**
 * Status/ACCESS_* introspection reading only `CurrentUser`/`CurrentConfig`,
 * never `HtmlRenderingInterface`/`RedirectServiceInterface` -- this makes
 * it safe to construct eagerly, unlike {@see AccessControl}.
 *
 * `AccessControl`'s own `HtmlRenderingInterface`/`RedirectServiceInterface`
 * collaborators route back through `RedirectService -> UserService ->
 * MailerInterface -> MailService -> UrlServiceInterface -> UrlService ->
 * HtmlRenderingInterface -> HtmlService`, so `HtmlService`/`MailService`/
 * `UrlService`/`Template`/`UserService`/`CategoryService`/
 * `PermissionService` depend on this class instead of the full
 * `AccessControl`, since they only need `isAdmin()`/`isAGuest()`/
 * `isClassicUser()`/`isWebmaster()`, never `checkStatus()`/
 * `accessDenied()`/`canManageComment()`.
 *
 * `AccessControl` composes this class internally for its own
 * identically-named methods rather than duplicating the logic.
 */
final readonly class AccessLevelChecker
{
    public function __construct(
        private CurrentUser $currentUser,
        private CurrentConfig $currentConfig,
    ) {}

    public function getUserStatus(string $userStatus = ''): string
    {
        if ($userStatus === '') {
            return $this->currentUser->get()
                ->status->value;
        }

        return $userStatus;
    }

    public function getAccessTypeStatus(string $userStatus = ''): int
    {
        return match ($this->getUserStatus($userStatus)) {
            'guest' => $this->currentConfig->guestAccess ? AccessLevel::Guest : AccessLevel::Free,
            'generic' => AccessLevel::Guest,
            'normal' => AccessLevel::Classic,
            'admin' => AccessLevel::Administrator,
            'webmaster' => AccessLevel::Webmaster,
            default => AccessLevel::Free,
        };
    }

    public function isAuthorizeStatus(int $accessType, string $userStatus = ''): bool
    {
        return $this->getAccessTypeStatus($userStatus) >= $accessType;
    }

    public function isGeneric(string $userStatus = ''): bool
    {
        return $this->getUserStatus($userStatus) === 'generic';
    }

    public function isAGuest(string $userStatus = ''): bool
    {
        return $this->getUserStatus($userStatus) === 'guest';
    }

    public function isClassicUser(string $userStatus = ''): bool
    {
        return $this->isAuthorizeStatus(AccessLevel::Classic, $userStatus);
    }

    public function isAdmin(string $userStatus = ''): bool
    {
        return $this->isAuthorizeStatus(AccessLevel::Administrator, $userStatus);
    }

    public function isWebmaster(string $userStatus = ''): bool
    {
        return $this->isAuthorizeStatus(AccessLevel::Webmaster, $userStatus);
    }

    public function canManageComment(string $action, int|string|null $commentAuthorId): bool
    {
        if ($this->isAGuest()) {
            return false;
        }

        if (! in_array($action, ['delete', 'edit', 'validate'], true)) {
            return false;
        }

        if ($this->isAdmin()) {
            return true;
        }

        // null means the comment is anonymous (no owner to compare
        // against) -- only an admin, already handled above, can manage it.
        // This early return is unobservable on its own: without it,
        // $commentAuthorId stays null and (int) null === 0 below, but
        // UserId::from()'s own invariant guarantees $currentUserId is
        // always a positive integer -- 0 can never match it, so the
        // fall-through path already lands on the same `return false`.
        if ($commentAuthorId === null) {
            return false;
        }

        $currentUserId = $this->currentUser->get()
            ->id->value;

        if ($action === 'edit' && $this->currentConfig->userCanEditComment) {
            if ((int) $commentAuthorId === $currentUserId) {
                return true;
            }
        }

        if ($action === 'delete' && $this->currentConfig->userCanDeleteComment) {
            if ((int) $commentAuthorId === $currentUserId) {
                return true;
            }
        }

        return false;
    }
}
