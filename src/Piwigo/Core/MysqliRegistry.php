<?php

declare(strict_types=1);

namespace Piwigo\Core;

/**
 * Static accessor for the active MySQLi connection singleton.
 *
 * Mirrors LoggerRegistry / TemplateRegistry: pwg_db_connect() in
 * include/dblayer/functions_mysqli.inc.php constructs the mysqli object and
 * calls MysqliRegistry::set() so that $GLOBALS['mysqli'] and
 * MysqliRegistry::current() return the same instance.
 */
final class MysqliRegistry
{
    private static ?\mysqli $instance = null;

    public static function set(\mysqli $mysqli): void
    {
        self::$instance = $mysqli;
        $GLOBALS['mysqli'] = $mysqli;
    }

    public static function isInitialized(): bool
    {
        return self::$instance !== null;
    }

    public static function current(): \mysqli
    {
        if (self::$instance === null) {
            throw new \LogicException('MysqliRegistry not initialised — pwg_db_connect() has not run yet.');
        }
        return self::$instance;
    }

    public static function reset(): void
    {
        self::$instance = null;
    }
}
