<?php

declare(strict_types=1);

namespace Piwigo\Core;

/**
 * "This request is dispatched through ws.php" marker -- Legacy Coupling
 * Retirement gap-closure (entry-shell define()/include round, Part 0b),
 * typed replacement for the raw IN_WS constant (`defined('IN_WS')`
 * reads). Same shape as Piwigo\Core\AdminContext -- see AdminContext's
 * own docblock for why reset() exists.
 */
final class WsContext
{
    private static bool $active = false;

    /**
     * Called once at the top of the ws.php entry shell, exactly where the
     * former define() sat.
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
