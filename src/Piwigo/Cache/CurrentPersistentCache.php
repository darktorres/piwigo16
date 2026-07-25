<?php

declare(strict_types=1);

namespace Piwigo\Cache;

/**
 * Static accessor for the current request's `PersistentCache` instance --
 * Legacy Coupling Retirement Track A gap-fill batch G5, replacing the
 * legacy `global $persistent_cache;` bridge. Same shape as this codebase's
 * other per-request singleton facades (`Piwigo\Core\CurrentLogger`,
 * `Piwigo\Template\CurrentTemplate`), except `get()` returns nullable
 * rather than throwing when unset: the established consumer idiom for
 * this one is `if (! $persistent_cache instanceof PersistentCache) { ...
 * fatalError()/construct a fallback ... }`, matching exactly how a never-
 * set raw global (null) behaved before -- a throwing get() would change
 * behavior for every one of those call sites instead of preserving it.
 *
 * One construction site: `Piwigo\Bootstrap\RequestBootstrap::connect()` (the
 * normal request pipeline).
 */
final class CurrentPersistentCache
{
    private static ?PersistentCache $instance = null;

    public static function get(): ?PersistentCache
    {
        return self::$instance;
    }

    public static function set(PersistentCache $cache): void
    {
        self::$instance = $cache;
    }

    public static function isInitialized(): bool
    {
        return self::$instance instanceof PersistentCache;
    }

    /**
     * Test-only, for test-isolation between requests -- mirrors
     * CurrentLogger's/CurrentTemplate's own reset() methods.
     */
    public static function reset(): void
    {
        self::$instance = null;
    }
}
