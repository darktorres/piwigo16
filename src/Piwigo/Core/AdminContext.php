<?php

declare(strict_types=1);

namespace Piwigo\Core;

/**
 * "This request is dispatched through admin.php/admin/popuphelp.php"
 * marker -- Legacy Coupling Retirement gap-closure (entry-shell
 * define()/include round, Part 0b), typed replacement for the raw
 * IN_ADMIN constant (`defined('IN_ADMIN')`/bare `IN_ADMIN` reads). A
 * single-write/multi-read per-request marker -- SEC-60 forbids define()
 * inside src/Piwigo, so the constant stays confined to the 2 entry-shell
 * files that set it; everything else reads this instead. reset() exists
 * because PHPUnit/Pest run every test file in one shared process -- a
 * test exercising the IN_ADMIN branch must not leak state into the next.
 */
final class AdminContext
{
    private static bool $active = false;

    /**
     * Called once at the top of the admin.php/admin/popuphelp.php entry
     * shells, exactly where the former define() sat.
     */
    public static function mark(): void
    {
        self::$active = true;
    }

    public static function isActive(): bool
    {
        return self::$active;
    }

    public static function reset(): void
    {
        self::$active = false;
    }
}
