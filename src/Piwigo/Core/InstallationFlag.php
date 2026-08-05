<?php

declare(strict_types=1);

namespace Piwigo\Core;

use LogicException;

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
 * container. `Piwigo\Core\Lang` (Phase 8), `Piwigo\Users\UserService`
 * (large construction-site fan-out, out of scope for this phase), and
 * `Piwigo\Bootstrap\SessionBootstrap` (a genuinely static-only class) aren't
 * converted yet, so they keep calling the `isActiveStatic()` shim below
 * instead of `isActive()` -- see that method's own docblock.
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

    /**
     * @deprecated transitional bridge for callers not yet converted to
     * constructor injection (Piwigo\Core\Lang::load() -- Phase 8;
     * Piwigo\Users\UserService's 2 call sites; Piwigo\Bootstrap\
     * SessionBootstrap::register()) -- PHP forbids an instance method and
     * a static method sharing one name, hence the `Static` suffix (not a
     * rename of the real API -- `isActive()` above is the real one; this
     * is scaffolding only). Delete once
     * `grep -rn "InstallationFlag::isActiveStatic("` outside tests/
     * returns nothing.
     *
     * Falls back to `false` (the same default the old static `$marked`
     * property always started at) when `Kernel::boot()` hasn't run --
     * `Lang::load()` is called from a very large number of test files that
     * exercise it indirectly (MailService::switchLangTo(), Template
     * construction, admin page renderers, ...) and never cared about this
     * flag one way or the other; none of them ever called the old
     * `InstallationFlag::mark()` either, so `false` is the exact
     * behavior-preserving default for all of them. A real request always
     * has a booted Kernel by the time `Lang::load()` runs, so this
     * fallback is never reached in production.
     */
    public static function isActiveStatic(): bool
    {
        if (! Kernel::isBooted()) {
            return false;
        }

        $instance = Kernel::container()->get(self::class);
        if (! $instance instanceof self) {
            throw new LogicException('Container returned an unexpected type for ' . self::class);
        }

        return $instance->isActive();
    }
}
