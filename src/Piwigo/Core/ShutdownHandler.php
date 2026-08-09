<?php

declare(strict_types=1);

namespace Piwigo\Core;

/**
 * Static callback registry for CLI graceful shutdown. `install()`
 * wires SIGTERM to run every registered cleanup callback before exiting --
 * Docker sends SIGTERM on `docker stop`/container restart, and a
 * `bin/piwigo backup:*` command mid-archive-extraction needs a chance to
 * remove its temp dir rather than leaving corrupt partial state behind.
 *
 * No-ops entirely when ext-pcntl isn't loaded (declared as a hard
 * composer.json requirement for this project's own deployment targets, but
 * defensive here rather than fatal -- CLI commands should still run their
 * primary work even if graceful cleanup-on-signal isn't available).
 *
 * Mirrors Kernel's own sanctioned static-state exception (SEC-60 doesn't
 * apply: `bin/piwigo` processes are one-shot, never reused across a
 * worker-mode request loop the way HTTP-path services must be).
 */
final class ShutdownHandler
{
    /**
     * @var list<callable(): void>
     */
    private static array $callbacks = [];

    private static bool $installed = false;

    public static function register(callable $cleanup): void
    {
        self::$callbacks[] = $cleanup;
    }

    public static function install(): void
    {
        if (self::$installed || ! function_exists('pcntl_signal')) {
            return;
        }
        self::$installed = true;

        pcntl_async_signals(true);
        pcntl_signal(SIGTERM, static function (): never {
            self::runAll();
            exit(143); // 128 + SIGTERM(15), conventional signal-termination exit code
        });
    }

    private static function runAll(): void
    {
        foreach (self::$callbacks as $callback) {
            $callback();
        }
    }

    /**
     * Test-only -- restricted to tests/ by an arch test, mirroring the
     * equivalent guard on Kernel's own test-isolation reset method.
     */
    public static function reset(): void
    {
        self::$callbacks = [];
        self::$installed = false;
    }
}
