<?php

declare(strict_types=1);

namespace Piwigo\Core;

/**
 * Redirect PHP errors/warnings away from the response body into DevTools
 * Network → Response Headers (X-PHP-Error-N) and Apache's error log.
 *
 * Errors are also passed to error_log() so Apache's error log stays the
 * authoritative server-side record.
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
            $msg   = "[{$label}] {$errstr} in {$short}";

            self::$collected[] = $msg;

            // Apache error log — authoritative server-side record.
            error_log("PHP {$label}: {$errstr} in {$errfile} on line {$errline}");

            // For non-HTML responses (JSON, XML, binary) that won't go through
            // the ob_start callback, also emit as response headers so they are
            // visible in DevTools → Network → Response Headers.
            if (!headers_sent()) {
                $n    = count(self::$collected);
                $safe = substr(str_replace(["\r", "\n"], ' ', $msg), 0, 500);
                header('X-PHP-Error-' . $n . ': ' . $safe);
                header('X-PHP-Error-Count: ' . $n);
            }

            return true; // Suppress PHP's built-in inline output.
        });

        // Shutdown: catch fatals that set_error_handler() cannot intercept.
        // The response is already committed here — log only, no header/output.
        register_shutdown_function(static function (): void {
            $last = error_get_last();
            if ($last !== null && ($last['type'] & (E_ERROR | E_PARSE | E_CORE_ERROR | E_COMPILE_ERROR))) {
                $label = self::label($last['type']);
                error_log("PHP {$label}: {$last['message']} in {$last['file']} on line {$last['line']}");
            }
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
            (bool) ($type & (E_ERROR | E_USER_ERROR | E_CORE_ERROR | E_COMPILE_ERROR))        => 'ERROR',
            (bool) ($type & (E_WARNING | E_USER_WARNING | E_CORE_WARNING | E_COMPILE_WARNING)) => 'WARNING',
            (bool) ($type & (E_DEPRECATED | E_USER_DEPRECATED))                               => 'DEPRECATED',
            (bool) ($type & (E_NOTICE | E_USER_NOTICE))                                       => 'NOTICE',
            default                                                                            => 'PHP',
        };
    }
}
