<?php

declare(strict_types=1);

namespace Piwigo\Ws;

/**
 * Static accessor for the active PwgServer singleton.
 *
 * Mirrors LoggerRegistry / TemplateRegistry: include/ws_init.inc.php constructs
 * the PwgServer instance and calls PwgServerRegistry::set() so that
 * $GLOBALS['service'] and PwgServerRegistry::current() return the same
 * instance.
 */
final class PwgServerRegistry
{
    private static ?PwgServer $instance = null;

    public static function set(PwgServer $service): void
    {
        self::$instance = $service;
        $GLOBALS['service'] = $service;
    }

    public static function isInitialized(): bool
    {
        return self::$instance !== null;
    }

    public static function current(): PwgServer
    {
        if (self::$instance === null) {
            throw new \LogicException('PwgServerRegistry not initialised — ws_init.inc.php has not run yet.');
        }
        return self::$instance;
    }

    public static function reset(): void
    {
        self::$instance = null;
    }
}
