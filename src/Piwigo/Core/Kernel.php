<?php

declare(strict_types=1);

namespace Piwigo\Core;

use Piwigo\Config\Config;
use Piwigo\Users\CurrentUser;

/**
 * Single boot entry point for the typed-service layer (Phase 4, Wave A).
 *
 * Call order: common.inc.php runs first (populates $conf/$page/$user/$lang via
 * the legacy bootstrap dance), then every root entry point calls Kernel::boot()
 * immediately after. boot() wires the typed facades — Config, PageState,
 * CurrentUser, Lang — to the already-loaded globals via reference bridges, so
 * both old code (global $conf) and new code (Config::get()) read the same data.
 *
 * The guard (self::$booted) makes the call idempotent: nested entry points that
 * include common.inc.php a second time will not re-wire and corrupt references.
 */
final class Kernel
{
    private static bool $booted = false;

    public static function boot(): void
    {
        if (self::$booted) {
            return;
        }
        self::$booted = true;

        Config::attachGlobals();
        PageState::attachGlobals();
        Lang::attachGlobals();
        CurrentUser::attachGlobals();

        ServiceLocator::register(Config::class, Config::instance());
        ServiceLocator::register(PageState::class, PageState::current());
    }

    public static function isBooted(): bool
    {
        return self::$booted;
    }

    // ---- Test helpers ----------------------------------------------------

    public static function reset(): void
    {
        self::$booted = false;
        Config::reset();
        PageState::reset();
        Lang::reset();
        CurrentUser::reset();
        ServiceLocator::reset();
    }
}
