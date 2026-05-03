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
        $raw = $GLOBALS['user'] ?? [];
        /** @var array<string,mixed> $userArray */
        $userArray = is_array($raw) ? $raw : [];
        self::$instance = User::fromUserArray($userArray);
    }

    public static function isInitialized(): bool
    {
        return self::$instance !== null;
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

    /**
     * Updates the current user's language on both the typed entity and
     * $GLOBALS['user']['language'], so legacy file-top consumers see the
     * change. Used by switch_lang_to/back during NBM language stacking.
     */
    public static function setLanguage(string $language): void
    {
        self::get()->language = $language;
        if (isset($GLOBALS['user']) && is_array($GLOBALS['user'])) {
            $GLOBALS['user']['language'] = $language;
        }
    }

    /**
     * Replaces both the typed User entity and $GLOBALS['user'] with a fresh
     * raw-attribute array. Used by NBM begin/end/set helpers that swap the
     * current user wholesale to send mail as different recipients.
     *
     * @param array<string,mixed> $attrs
     */
    public static function setRawAttributes(array $attrs): void
    {
        self::$instance = User::fromUserArray($attrs);
        $GLOBALS['user'] = $attrs;
    }

    // ---- Test helpers ----------------------------------------------------

    public static function reset(): void
    {
        self::$instance = null;
    }
}
