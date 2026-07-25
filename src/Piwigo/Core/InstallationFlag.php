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
 */
final class InstallationFlag
{
    private static bool $marked = false;

    /**
     * Called once from include/common.inc.php, exactly where the former
     * `defined('PHPWG_INSTALLED') or define('PHPWG_INSTALLED', true);`
     * guard sat.
     */
    public static function mark(): void
    {
        self::$marked = true;
    }

    public static function isActive(): bool
    {
        return self::$marked || defined('PHPWG_INSTALLED');
    }

    public static function reset(): void
    {
        self::$marked = false;
    }
}
