<?php

declare(strict_types=1);

namespace Piwigo\Auth;

use Piwigo\Config\CurrentConfig;
use Piwigo\Core\AccessLevel;
use Piwigo\Users\CurrentUser;

/**
 * The cheap half of what used to be a single `AccessControl`: status/
 * ACCESS_* introspection reading only `CurrentUser`/`CurrentConfig`, never
 * `HtmlRenderingInterface`/`RedirectServiceInterface`.
 *
 * Singleton/service-locator elimination campaign, Phase 12 sub-phase 12A:
 * extracted from `AccessControl` specifically to break an artificial
 * circular dependency -- `AccessControl`'s own `HtmlRenderingInterface`/
 * `RedirectServiceInterface` collaborators route back through
 * `RedirectService -> UserService -> MailerInterface -> MailService ->
 * UrlServiceInterface -> UrlService -> HtmlRenderingInterface ->
 * HtmlService`, so `HtmlService`/`MailService`/`UrlService`/`Template`/
 * `UserService`/`CategoryService`/`PermissionService` could never take the
 * full `AccessControl` via real constructor injection. Every one of those
 * 7 classes' own real usage, read directly, only ever called `isAdmin()`/
 * `isAGuest()`/`isClassicUser()`/`isWebmaster()` -- never `checkStatus()`/
 * `accessDenied()`/`canManageComment()`, the only `AccessControl` methods
 * that actually need the two interfaces -- confirming the cycle was
 * accidental, not load-bearing. This class has none of that: it's exactly
 * as safe to construct eagerly as `CurrentUser`/`CurrentConfig`
 * themselves.
 *
 * `AccessControl` composes this class internally for its own identically-
 * named methods (unchanged public API, unchanged behavior) rather than
 * duplicating the logic.
 */
final class AccessLevelChecker
{
    public function __construct(
        private readonly CurrentUser $currentUser,
        private readonly CurrentConfig $currentConfig,
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
            'guest' => $this->currentConfig->guestAccess() ? AccessLevel::Guest : AccessLevel::Free,
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
        // Confirmed while investigating a mutation-testing gap.
        if ($commentAuthorId === null) {
            return false;
        }

        $currentUserId = $this->currentUser->get()
            ->id->value;

        if ($action === 'edit' && $this->currentConfig->userCanEditComment()) {
            if ((int) $commentAuthorId === $currentUserId) {
                return true;
            }
        }

        if ($action === 'delete' && $this->currentConfig->userCanDeleteComment()) {
            if ((int) $commentAuthorId === $currentUserId) {
                return true;
            }
        }

        return false;
    }
}
