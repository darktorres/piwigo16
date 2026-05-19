<?php

declare(strict_types=1);

namespace Piwigo\Users;

use Piwigo\Config\Config;

/**
 * Static accessor for the authenticated user singleton.
 * UserBootstrap::bootstrap() calls CurrentUser::set() after building the full user.
 */
final class CurrentUser
{
    private static ?User $instance = null;

    /**
     * Initialise the singleton with an empty guest user. Called by
     * Kernel::boot() before authentication runs; UserBootstrap::bootstrap()
     * later calls set() with the real user.
     *
     * (Method name is historical from the days of the $GLOBALS['user']
     * bridge; the body no longer touches any global.)
     */
    public static function attachGlobals(): void
    {
        self::$instance ??= new User(
            id: Config::guestId(),
            username: '',
            email: '',
            language: 'en_US',
            theme: 'elegant',
            status: 'guest',
            enabledHigh: false,
        );
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
     * Updates the current user's language on the typed entity.
     * Used by switch_lang_to/back during NBM language stacking.
     */
    public static function setLanguage(string $language): void
    {
        self::$instance = self::get()->withLanguage($language);
    }

    /**
     * Replaces the singleton with the result of `$fn(currentUser)`.
     * Use for any mutation now that User is readonly.
     *
     * @param \Closure(User): User $fn
     */
    public static function update(\Closure $fn): void
    {
        self::$instance = $fn(self::get());
    }

    /**
     * Replaces the typed User entity with a fresh raw-attribute array.
     * Used by NBM begin/end/set helpers that swap the current user wholesale.
     *
     * @param array<mixed> $attrs
     */
    public static function setRawAttributes(array $attrs): void
    {
        self::$instance = User::fromUserArray($attrs);
    }

    // ---- Test helpers ----------------------------------------------------

    public static function reset(): void
    {
        self::$instance = null;
    }
}
