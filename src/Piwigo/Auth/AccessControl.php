<?php

declare(strict_types=1);

namespace Piwigo\Auth;

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
 * RedirectServiceInterface are constructor-injected (both already bound
 * in `container.php`, so this needed zero new container config).
 * checkStatus()'s former nullable-collaborator guard is gone: a
 * container-resolved instance is now *always* guaranteed both
 * collaborators (unlike the old static design, which allowed
 * setHtmlRenderer()/setRedirectService() to simply never be called), so
 * accessDenied() is unconditionally reachable -- a real, permanent
 * simplification, not just a test artifact.
 *
 * Singleton/service-locator elimination campaign, Phase 12 sub-phase 12A:
 * every method that doesn't need HtmlRenderingInterface/
 * RedirectServiceInterface (getUserStatus()/getAccessTypeStatus()/
 * isAuthorizeStatus()/isGeneric()/isAGuest()/isClassicUser()/isAdmin()/
 * isWebmaster()/canManageComment()) moved to AccessLevelChecker -- see
 * that class's own docblock for why (breaking an artificial circular
 * dependency). This class now delegates to it and keeps only
 * checkStatus() as real logic (the one method that genuinely needs
 * htmlRenderer/redirectService). Public API and behavior are unchanged;
 * only the implementation moved -- CurrentUser/CurrentConfig dropped from
 * this class's own constructor entirely, since AccessLevelChecker already
 * carries them and nothing here reads them directly anymore.
 * currentForCaching() (a degraded, never-throwing fallback built to
 * survive that same cycle's eager-Doctrine-connect risk in
 * CssLoader/ScriptLoader) is deleted -- those 2 files now take
 * AccessLevelChecker directly, which has no Doctrine dependency of its
 * own, so there is no more risk to guard against.
 *
 * Sub-phase 12B: the current() transitional-bridge shim itself is deleted
 * -- its last real production callers (RequestBootstrap.php's 3 direct
 * calls, PageTail.php's 2, public/admin.php's 1, public/random.php's 2)
 * all closed, either via real constructor injection or a private/public
 * Bootstrap-internal resolver. Every real caller now takes this class via
 * constructor injection or resolves it through Kernel::container()
 * directly from Bootstrap/-internal code.
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
        private readonly AccessLevelChecker $accessLevelChecker,
    ) {}

    public function getUserStatus(string $userStatus = ''): string
    {
        return $this->accessLevelChecker->getUserStatus($userStatus);
    }

    public function getAccessTypeStatus(string $userStatus = ''): int
    {
        return $this->accessLevelChecker->getAccessTypeStatus($userStatus);
    }

    public function isAuthorizeStatus(int $accessType, string $userStatus = ''): bool
    {
        return $this->accessLevelChecker->isAuthorizeStatus($accessType, $userStatus);
    }

    public function checkStatus(int $accessType, string $userStatus = ''): void
    {
        if (! $this->accessLevelChecker->isAuthorizeStatus($accessType, $userStatus)) {
            // accessDenied() is `never`-typed -- always terminates (throws
            // or redirects), so unlike the old nullable-collaborator design
            // there is no reachable fallthrough left to throw a plain
            // RuntimeException from (PHPStan proves this: deadCode.unreachable).
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
