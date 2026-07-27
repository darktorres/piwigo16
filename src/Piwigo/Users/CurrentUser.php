<?php

declare(strict_types=1);

namespace Piwigo\Users;

use Piwigo\Common\ValueObject\UserId;
use Piwigo\Config\CurrentConfig;
use Piwigo\Core\AppInfo;

/**
 * Static accessor for the authenticated-user singleton. In v17 this is the
 * P17-P23 bridge shape (the doc's own callout: "in v17 CurrentUser is a
 * container singleton with instance get()/set(); the static API is the
 * bridge only, deleted in P23") -- kept static for now since no
 * UserBootstrap/auth domain exists yet to inject a real instance into.
 *
 * `attachGlobals()` is called by `RequestBootstrap::finalize()`/
 * `bootConfigOnly()`/`CliBootstrap::buildApplication()` -- NOT from
 * Kernel itself, which is L1Infrastructure and (per deptrac's ruleset)
 * may only depend on L0Data, not upward on `Users`'
 * L2aCoreDomain.
 */
final class CurrentUser
{
    private static ?User $instance = null;

    /**
     * Legacy Coupling Retirement Phase 8, 8h: distinguishes "a real,
     * per-request user was resolved" from "attachGlobals() guest-seeded
     * this singleton because nothing else has run yet" -- isInitialized()
     * can't make that distinction, since attachGlobals() unconditionally
     * seeds a guest user on every bootstrap path, including CLI/plugin
     * `autoupdate` firings with no real request user. ActivityService::
     * record() needs the distinction: a truly-unresolved actor must record
     * as `null` (activity.performed_by's `ON DELETE SET NULL` foreign key
     * depends on it -- `0` is not a valid id, AUTO_INCREMENT starts at 1),
     * not the guest id.
     */
    private static bool $realUserResolved = false;

    /**
     * Initialises the singleton with an empty guest user if not already
     * set. Idempotent -- a later real `set()` call (once auth exists,
     * P17-23) is never clobbered by a redundant `attachGlobals()`.
     */
    public static function attachGlobals(): void
    {
        self::$instance ??= new User(
            id: UserId::from(CurrentConfig::guestId()),
            username: '',
            email: '',
            language: AppInfo::DEFAULT_LANGUAGE,
            theme: AppInfo::DEFAULT_TEMPLATE,
            status: UserStatus::Guest,
            enabledHigh: false,
        );
    }

    public static function isInitialized(): bool
    {
        return self::$instance instanceof \Piwigo\Users\User;
    }

    public static function get(): User
    {
        if (! self::$instance instanceof \Piwigo\Users\User) {
            throw new \LogicException('CurrentUser not initialised -- call Kernel::boot() first.');
        }

        return self::$instance;
    }

    public static function set(User $user): void
    {
        self::$instance = $user;
    }

    public static function updateLanguage(string $language): void
    {
        self::$instance = self::get()->withLanguage($language);
    }

    /**
     * Called by UserBootstrap::initialize() -- the only real per-request
     * user resolver -- right alongside its own set() call.
     */
    public static function markRealUserResolved(): void
    {
        self::$realUserResolved = true;
    }

    public static function wasRealUserResolved(): bool
    {
        return self::$realUserResolved;
    }

    /**
     * Monotonic (false -> true, never flipped back mid-request), unlike
     * most of this class's other request-scoped state -- needs an
     * explicit reset at the true start of each request
     * (RequestBootstrap::configure()), not folded into reset() below,
     * which is arch-test-restricted to tests/ and resets more than this
     * flag needs.
     */
    public static function resetRealUserResolvedFlag(): void
    {
        self::$realUserResolved = false;
    }

    /**
     * Test-only -- restricted to tests/ by an arch test (matching the same
     * test-isolation precedent as Config's and Kernel's own reset() methods).
     */
    public static function reset(): void
    {
        self::$instance = null;
        self::$realUserResolved = false;
    }
}
