<?php

declare(strict_types=1);

namespace Piwigo\Core;

use Piwigo\Config\Config;
use Piwigo\Users\CurrentUser;
use Piwigo\Core\LanguageStack;

/**
 * Single boot entry point for the typed-service layer (Phase 4, Wave A).
 *
 * Call order: common.inc.php runs first (ConfigLoader populates Config::$data
 * directly; legacy code populates $page/$user/$lang via the bootstrap dance),
 * then every root entry point calls Kernel::boot() immediately after. boot()
 * wires PageState / CurrentUser / Lang to their respective $GLOBALS via
 * reference bridges so legacy procedural reads stay coherent with the typed
 * facades. (Config no longer needs a bridge — ConfigLoader writes directly
 * to Config::$data and all readers/writers are migrated.)
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
        LanguageStack::reset();
        CurrentUser::reset();
        ServiceLocator::reset();
    }
}
