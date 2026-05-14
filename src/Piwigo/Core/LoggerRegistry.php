<?php

declare(strict_types=1);

namespace Piwigo\Core;

use Psr\Log\LoggerInterface;

/**
 * Static accessor for the active PSR-3 logger singleton.
 *
 * Accepts any LoggerInterface implementation so the concrete class
 * (currently Logger, backed by Monolog) can be swapped at boot time
 * without touching call sites.
 */
final class LoggerRegistry
{
    private static ?LoggerInterface $instance = null;

    public static function set(LoggerInterface $logger): void
    {
        self::$instance = $logger;
    }

    public static function isInitialized(): bool
    {
        return self::$instance !== null;
    }

    public static function current(): LoggerInterface
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
