<?php

declare(strict_types=1);

namespace Piwigo\Core;

/**
 * Redirect PHP errors/warnings away from the response body and into HTTP
 * response headers instead (X-PHP-Error-N), visible in DevTools → Network
 * → Response Headers.
 *
 * This prevents PHP notices/warnings from corrupting JSON, XML, or binary
 * responses while keeping them inspectable in the browser.
 *
 * Errors are also passed through to PHP's built-in error_log() so the Apache
 * error log remains the authoritative server-side record.
 *
 * Install once, early in the bootstrap:
 *   ErrorCollector::install();
 */
final class ErrorCollector
{
    /** @var list<string> */
    private static array $collected = [];

    private static bool $active = false;

    public static function install(): void
    {
        if (self::$active) {
            return;
        }
        self::$active = true;

        // Never write errors inline — they corrupt non-HTML responses.
        ini_set('display_errors', '0');
        ini_set('display_startup_errors', '0');

        set_error_handler(static function (int $errno, string $errstr, string $errfile, int $errline): bool {
            // Respect the @ error-suppression operator.
            if (!(error_reporting() & $errno)) {
                return false;
            }

            $label = self::label($errno);
            $short = basename($errfile) . ':' . $errline;
            self::$collected[] = "[{$label}] {$errstr} in {$short}";

            // Keep the Apache error log as the authoritative server-side record.
            error_log("PHP {$label}: {$errstr} in {$errfile} on line {$errline}");

            return true; // Suppress PHP's built-in inline output.
        });

        register_shutdown_function(static function (): void {
            // Catch fatal errors that set_error_handler() cannot intercept.
            $last = error_get_last();
            if ($last !== null && ($last['type'] & (E_ERROR | E_PARSE | E_CORE_ERROR | E_COMPILE_ERROR))) {
                $label = self::label($last['type']);
                $short = basename($last['file']) . ':' . $last['line'];
                self::$collected[] = "[{$label}] {$last['message']} in {$short}";
            }

            if (empty(self::$collected) || headers_sent()) {
                return;
            }

            // Emit one header per error — DevTools shows each on its own line.
            foreach (self::$collected as $i => $msg) {
                // Strip newlines (invalid in header values) and cap length.
                $safe = substr(str_replace(["\r", "\n"], ' ', $msg), 0, 500);
                header('X-PHP-Error-' . ($i + 1) . ': ' . $safe);
            }
            header('X-PHP-Error-Count: ' . count(self::$collected));
        });
    }

    public static function isActive(): bool
    {
        return self::$active;
    }

    /** @return list<string> */
    public static function collected(): array
    {
        return self::$collected;
    }

    public static function reset(): void
    {
        self::$collected = [];
        self::$active    = false;
    }

    private static function label(int $type): string
    {
        return match (true) {
            (bool) ($type & (E_ERROR | E_USER_ERROR | E_CORE_ERROR | E_COMPILE_ERROR))     => 'ERROR',
            (bool) ($type & (E_WARNING | E_USER_WARNING | E_CORE_WARNING | E_COMPILE_WARNING)) => 'WARNING',
            (bool) ($type & (E_DEPRECATED | E_USER_DEPRECATED))                            => 'DEPRECATED',
            (bool) ($type & (E_NOTICE | E_USER_NOTICE))                                    => 'NOTICE',
            default => 'PHP',
        };
    }
}
