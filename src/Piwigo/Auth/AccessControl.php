<?php

declare(strict_types=1);

namespace Piwigo\Auth;

use Piwigo\Core\HtmlRenderingInterface;
use Piwigo\Core\RedirectServiceInterface;

/**
 * Current-request access-level checks: delegates status/ACCESS_*
 * introspection to {@see AccessLevelChecker} and keeps only
 * checkStatus() as real logic here, since it's the one method that
 * needs `HtmlRenderingInterface`/`RedirectServiceInterface` to deny
 * access.
 */
final class AccessControl
{
    public function __construct(
        private readonly HtmlRenderingInterface $htmlRenderer,
        private readonly RedirectServiceInterface $redirectService,
        private readonly AccessLevelChecker $accessLevelChecker,
    ) {}

    public function getUserStatus(string $userStatus = ''): string
    {
        return $this->accessLevelChecker->getUserStatus($userStatus);
    }

    public function isAuthorizeStatus(int $accessType, string $userStatus = ''): bool
    {
        return $this->accessLevelChecker->isAuthorizeStatus($accessType, $userStatus);
    }

    public function checkStatus(int $accessType, string $userStatus = ''): void
    {
        if (! $this->accessLevelChecker->isAuthorizeStatus($accessType, $userStatus)) {
            // accessDenied() is `never`-typed -- it always terminates
            // (throws or redirects), so there's no reachable fallthrough
            // after this call.
            $this->htmlRenderer->accessDenied($this->redirectService);
        }
    }

    public function isGeneric(string $userStatus = ''): bool
    {
        return $this->accessLevelChecker->isGeneric($userStatus);
    }

    public function isAGuest(string $userStatus = ''): bool
    {
        return $this->accessLevelChecker->isAGuest($userStatus);
    }

    public function isClassicUser(string $userStatus = ''): bool
    {
        return $this->accessLevelChecker->isClassicUser($userStatus);
    }

    public function isAdmin(string $userStatus = ''): bool
    {
        return $this->accessLevelChecker->isAdmin($userStatus);
    }

    public function isWebmaster(string $userStatus = ''): bool
    {
        return $this->accessLevelChecker->isWebmaster($userStatus);
    }

    public function canManageComment(string $action, int|string|null $commentAuthorId): bool
    {
        return $this->accessLevelChecker->canManageComment($action, $commentAuthorId);
    }
}
