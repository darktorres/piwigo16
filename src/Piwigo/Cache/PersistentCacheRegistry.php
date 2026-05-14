<?php

declare(strict_types=1);

namespace Piwigo\Cache;

/** Static accessor for the active PersistentCache singleton. */
final class PersistentCacheRegistry
{
    private static ?PersistentCache $instance = null;

    public static function set(PersistentCache $cache): void
    {
        self::$instance = $cache;
    }

    public static function isInitialized(): bool
    {
        return self::$instance !== null;
    }

    public static function current(): PersistentCache
    {
        if (self::$instance === null) {
            throw new \LogicException('PersistentCacheRegistry not initialised — common.inc.php has not run yet.');
        }
        return self::$instance;
    }

    public static function reset(): void
    {
        self::$instance = null;
    }
}
