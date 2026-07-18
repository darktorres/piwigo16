<?php

declare(strict_types=1);

namespace Piwigo\Users;

use Piwigo\Config\Config;
use Piwigo\Core\AppInfo;

/**
 * Static accessor for the authenticated-user singleton. In v17 this is the
 * P17-P23 bridge shape (the doc's own callout: "in v17 CurrentUser is a
 * container singleton with instance get()/set(); the static API is the
 * bridge only, deleted in P23") -- kept static for now since no
 * UserBootstrap/auth domain exists yet to inject a real instance into.
 *
 * `attachGlobals()` is called by `CommonBootstrap::run()`/
 * `CliBootstrap::buildApplication()` right after `Kernel::boot()` --  NOT
 * from Kernel itself, which is L1Infrastructure and (per deptrac's
 * ruleset) may only depend on L0Data, not upward on `Users`'
 * L2aCoreDomain.
 */
final class CurrentUser
{
    private static ?User $instance = null;

    /**
     * Initialises the singleton with an empty guest user if not already
     * set. Idempotent -- a later real `set()` call (once auth exists,
     * P17-23) is never clobbered by a redundant `attachGlobals()`.
     */
    public static function attachGlobals(): void
    {
        self::$instance ??= new User(
            id: Config::guestId(),
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
     * Test-only -- restricted to tests/ by an arch test (matching the same
     * test-isolation precedent as Config's and Kernel's own reset() methods).
     */
    public static function reset(): void
    {
        self::$instance = null;
    }
}
