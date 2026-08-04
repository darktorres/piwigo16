<?php

declare(strict_types=1);

namespace Piwigo\Auth;

use Piwigo\Core\AccessLevel;
use Piwigo\Core\HtmlRenderingInterface;
use Piwigo\Core\RedirectServiceInterface;

/**
 * Current-request access-level checks: status/ACCESS_* introspection,
 * reading Piwigo\Users\CurrentUser (Legacy Coupling Retirement Track A
 * batch A3 -- previously the legacy `global $user;` bridge array directly)
 * and `global $conf;`.
 *
 * Singleton/service-locator elimination campaign, Phase 7: converted to a
 * real, container-shared instance -- HtmlRenderingInterface/
 * RedirectServiceInterface/CurrentUser are constructor-injected (all 3
 * already bound in `container.php`, so this needed zero new container
 * config). checkStatus()'s former nullable-collaborator guard is gone: a
 * container-resolved instance is now *always* guaranteed both
 * collaborators (unlike the old static design, which allowed
 * setHtmlRenderer()/setRedirectService() to simply never be called), so
 * accessDenied() is unconditionally reachable -- a real, permanent
 * simplification, not just a test artifact.
 *
 * `CurrentConfig::guestAccess()`/`userCanEditComment()`/
 * `userCanDeleteComment()` stay static calls for now (Phase 9 > 7, per
 * this campaign's own plan).
 *
 * P23 batch 8d: ported from include/functions_user.inc.php's
 * get_user_status()/get_access_type_status()/is_autorize_status()/
 * check_status()/is_generic()/is_a_guest()/is_classic_user()/is_admin()/
 * is_webmaster()/can_manage_comment().
 */
final class AccessControl
{
    public function __construct(
        private readonly HtmlRenderingInterface $htmlRenderer,
        private readonly RedirectServiceInterface $redirectService,
        private readonly \Piwigo\Users\CurrentUser $currentUser,
    ) {}

    /**
     * @deprecated transitional bridge for callers not yet converted to
     * constructor injection -- see the singleton/service-locator
     * elimination campaign plan, Phase 7. A live container resolve on
     * every call (no memoized pre-boot fallback, unlike e.g.
     * `CurrentUser::current()`): unlike a boolean marker/holder, this
     * class has no safe "unset" default to fall back to -- access-control
     * decisions must always reflect real state, so this throws naturally
     * (via `Kernel::container()`) if called before `Kernel::boot()`,
     * matching `SrcImage`/`DerivativeImage`/`ScriptLoader`'s own Phase 6
     * bridge shape, not `CurrentUser`/`CurrentTemplate`'s memoized-fallback
     * shape. Delete once `grep -rn "AccessControl::current("` outside
     * tests/ returns nothing.
     */
    public static function current(): self
    {
        $instance = \Piwigo\Core\Kernel::container()->get(self::class);
        if (! $instance instanceof self) {
            throw new \LogicException('Container returned an unexpected type for ' . self::class);
        }

        return $instance;
    }

    public function getUserStatus(string $userStatus = ''): string
    {
        if ($userStatus === '') {
            return $this->currentUser->get()->status->value;
        }

        return $userStatus;
    }

    public function getAccessTypeStatus(string $userStatus = ''): int
    {

        return match ($this->getUserStatus($userStatus)) {
            'guest' => \Piwigo\Config\CurrentConfig::guestAccess() ? AccessLevel::Guest : AccessLevel::Free,
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

    public function checkStatus(int $accessType, string $userStatus = ''): void
    {
        if (! $this->isAuthorizeStatus($accessType, $userStatus)) {
            // accessDenied() is `never`-typed -- always terminates (throws
            // or redirects), so unlike the old nullable-collaborator design
            // there is no reachable fallthrough left to throw a plain
            // RuntimeException from (PHPStan proves this: deadCode.unreachable).
            $this->htmlRenderer->accessDenied($this->redirectService);
        }
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

        $currentUserId = $this->currentUser->get()->id->value;

        if ($action === 'edit' && \Piwigo\Config\CurrentConfig::userCanEditComment()) {
            if ((int) $commentAuthorId === $currentUserId) {
                return true;
            }
        }

        if ($action === 'delete' && \Piwigo\Config\CurrentConfig::userCanDeleteComment()) {
            if ((int) $commentAuthorId === $currentUserId) {
                return true;
            }
        }

        return false;
    }
}
