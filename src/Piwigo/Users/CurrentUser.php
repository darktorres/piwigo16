<?php

declare(strict_types=1);

namespace Piwigo\Users;

/**
 * Static accessor for the authenticated user singleton.
 *
 * Wave A: Kernel::boot() calls attachGlobals() once common.inc.php has fully
 * populated $GLOBALS['user']. After that, CurrentUser::get() returns a User
 * entity whose typed properties match the $user global at boot time.
 */
final class CurrentUser
{
    private static ?User $instance = null;

    /**
     * Called by Kernel::boot() after include/user.inc.php has fully populated
     * $GLOBALS['user'] (including language assignment and plugin hooks).
     */
    public static function attachGlobals(): void
    {
        self::$instance = User::fromUserArray($GLOBALS['user']);
    }

    public static function get(): User
    {
        if (self::$instance === null) {
            throw new \LogicException('CurrentUser not initialised — call Kernel::boot() first.');
        }
        return self::$instance;
    }

    public static function set(User $user): void
    {
        self::$instance = $user;
    }

    // ---- Test helpers ----------------------------------------------------

    public static function reset(): void
    {
        self::$instance = null;
    }
}
