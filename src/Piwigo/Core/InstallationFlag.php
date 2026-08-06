<?php

declare(strict_types=1);

namespace Piwigo\Core;

/**
 * "This request is past the install-check gate" marker -- Legacy
 * Coupling Retirement gap-closure (entry-shell define()/include round,
 * Part 0b), typed replacement for the raw PHPWG_INSTALLED constant
 * everywhere OUTSIDE the install flow itself.
 *
 * Unlike Piwigo\Core\AdminContext/WsContext, this does NOT fully retire
 * the raw define(): install.php's own performInstall() step still does
 * `define('PHPWG_INSTALLED', true)` immediately before running the
 * install, right next to its own `PWG_CHARSET`/`DB_CHARSET`/`DB_COLLATE`
 * `defined(...) or define(...)` guards in the same block ("shell-defined
 * constants still win when present"). Verified via a real repo-wide scan:
 * nothing under
 * src/Piwigo/Admin/Install/ itself reads PHPWG_INSTALLED (only
 * Core\Lang/Users\UserService/Bootstrap\SessionBootstrap do, all outside
 * the install flow), so isActive() checking defined() first is a safety
 * net for that one remaining shell define(), not a live dependency this
 * class's own callers need to worry about.
 *
 * Singleton/service-locator elimination campaign, Phase 1: converted from
 * a self-managed static facade to a container-shared instance.
 * `RequestBootstrap` (the only writer) constructor-resolves it from the
 * container. `Piwigo\Core\Lang` (Phase 8) and `Piwigo\Users\UserService`
 * (Phase 11 sub-phase 11G) both closed their own former `isActiveStatic()`
 * transitional-bridge usage via real constructor injection;
 * `Piwigo\Bootstrap\SessionBootstrap` (a genuinely static-only class)
 * closed its own last (Phase 12 sub-phase 12B) via a private
 * container-resolving `installationFlag()` helper instead -- the shim
 * itself is deleted, every real caller now reaches the real `isActive()`
 * instance method.
 */
final class InstallationFlag
{
    private bool $marked = false;

    /**
     * Called once from RequestBootstrap::bootEntryPoint(), between
     * configure() and connect() -- the same point the former
     * `defined('PHPWG_INSTALLED') or define('PHPWG_INSTALLED', true);`
     * guard sat in the now-deleted include/common.inc.php seam file.
     */
    public function mark(): void
    {
        $this->marked = true;
    }

    public function isActive(): bool
    {
        return $this->marked || defined('PHPWG_INSTALLED');
    }

    /**
     * Test-only -- production code never needs to clear this mid-request.
     * No arch test needed (unlike the old static `reset()`): reaching this
     * instance method at all requires holding a real reference to this
     * exact instance, which production code has no reason to do.
     */
    public function reset(): void
    {
        $this->marked = false;
    }
}
