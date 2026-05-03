<?php

declare(strict_types=1);

namespace Piwigo\Core;

/**
 * Static accessor for the active Logger singleton.
 *
 * Mirrors TemplateRegistry: every entry-point that constructs `$logger = new
 * Logger(...)` (include/common.inc.php, i.php) calls LoggerRegistry::set() so
 * that $GLOBALS['logger'] and LoggerRegistry::current() return the same
 * instance. Migrated call sites use the typed accessor instead of `global
 * $logger;`.
 */
final class LoggerRegistry
{
    private static ?Logger $instance = null;

    public static function set(Logger $logger): void
    {
        self::$instance = $logger;
        $GLOBALS['logger'] = $logger;
    }

    public static function isInitialized(): bool
    {
        return self::$instance !== null;
    }

    public static function current(): Logger
    {
        if (self::$instance === null) {
            throw new \LogicException('LoggerRegistry not initialised — no global Logger has been constructed yet.');
        }
        return self::$instance;
    }

    public static function reset(): void
    {
        self::$instance = null;
    }
}
