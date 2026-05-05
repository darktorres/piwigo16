<?php

declare(strict_types=1);

namespace Piwigo\Core;

/**
 * Redirect PHP errors/warnings away from the response body and into HTTP
 * response headers instead (X-PHP-Error-N), visible in DevTools → Network
 * → Response Headers.
 *
 * Headers are emitted immediately inside the error handler callback, not
 * deferred to a shutdown function. Shutdown functions run after Template::flush()
 * has already committed the response body, making header() a no-op at that point.
 * Emitting during the error handler fires while script execution is still in
 * progress and headers are still mutable.
 *
 * Errors are also passed through to error_log() so the Apache error log
 * remains the authoritative server-side record.
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

        // Buffer output so headers remain mutable until the first byte is actually
        // sent. Without this, a warning that fires before any echo would still
        // race against output that may have started before install() was called.
        if (!ob_get_level()) {
            ob_start();
        }

        set_error_handler(static function (int $errno, string $errstr, string $errfile, int $errline): bool {
            // Respect the @ error-suppression operator.
            if (!(error_reporting() & $errno)) {
                return false;
            }

            $label = self::label($errno);
            $short = basename($errfile) . ':' . $errline;
            $msg   = "[{$label}] {$errstr} in {$short}";

            self::$collected[] = $msg;

            // Keep the Apache error log as the authoritative server-side record.
            error_log("PHP {$label}: {$errstr} in {$errfile} on line {$errline}");

            // Emit the header immediately — the error handler fires during script
            // execution, before Template::flush() commits the response body, so
            // header() is still valid here. Shutdown functions fire too late.
            if (!headers_sent()) {
                $n    = count(self::$collected);
                $safe = substr(str_replace(["\r", "\n"], ' ', $msg), 0, 500);
                header('X-PHP-Error-' . $n . ': ' . $safe);
                header('X-PHP-Error-Count: ' . $n); // replaced on each error; final value = total
            }

            return true; // Suppress PHP's built-in inline output.
        });

        // Shutdown function: catch fatal errors that set_error_handler() cannot
        // intercept. At this point the response is already committed, so we can
        // only log — no header() calls.
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
