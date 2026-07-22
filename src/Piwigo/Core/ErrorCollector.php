<?php

declare(strict_types=1);

namespace Piwigo\Core;

/**
 * Redirects PHP errors/warnings/deprecations away from the response body and
 * into HTTP response headers instead (X-PHP-Error-N), visible in DevTools ->
 * Network -> Response Headers.
 *
 * P23 sub-batch 8f-5: real class replacing the 7 pwg_error_collector_*()
 * free functions of the deleted include/error_collector.inc.php -- that
 * file's own docblock justified staying procedural with "17.x-rewrite has
 * no src/ PSR-4 autoloading yet", long stale. Bodies ported verbatim
 * (set_error_handler()/register_shutdown_function()/headers_sent()
 * semantics unchanged); the two $pwg_error_collector_* globals became the
 * static properties below.
 *
 * Prevents PHP notices/warnings/deprecations from corrupting JSON, XML, or
 * binary responses (e.g. ws.php) while keeping them inspectable in the
 * browser. Errors still reach error_log() so the Apache error log remains
 * the authoritative server-side record.
 *
 * Install once, early in the bootstrap — see Piwigo\Bootstrap\
 * RequestBootstrap's show_php_errors_on_frontend handling.
 */
final class ErrorCollector
{
    private static bool $active = false;

    /**
     * @var list<string>
     */
    private static array $collected = [];

    public static function install(): void
    {
        if (self::$active) {
            return;
        }
        self::$active = true;
        self::$collected = [];

        // Never write errors inline — they corrupt non-HTML responses.
        @ini_set('display_errors', '0');
        @ini_set('display_startup_errors', '0');

        set_error_handler(self::handleError(...));
        register_shutdown_function(self::flush(...));
    }

    /**
     * Installs (per the same show_php_errors/show_php_errors_on_frontend
     * config gate) from every bootstrap that has its own fatalError()-
     * reachable call graph -- originally only Bootstrap\RequestBootstrap::
     * connect() (the main HTTP pipeline); Bootstrap\InstallBootstrap::boot()
     * (install.php/upgrade.php/upgrade_feed.php) needs the identical
     * sequence for the exact same reason (see HtmlService::fatalError()'s
     * own comment: without this installed, its trigger_error(E_USER_ERROR)
     * hard-halts the script before ever reaching the ResponseReadyException
     * throw that follows it) -- confirmed live, a real install.php 500
     * rather than the intended clean error page.
     */
    public static function installIfConfigured(): void
    {
        if (\Piwigo\Config\Config::has('show_php_errors') && \Piwigo\Config\Config::showPhpErrors() !== 0) {
            if (is_scalar(\Piwigo\Config\Config::showPhpErrors())) {
                @ini_set('error_reporting', \Piwigo\Config\Config::showPhpErrors());
            }
            if (\Piwigo\Config\Config::showPhpErrorsOnFrontend()) {
                self::install();
            }
        }
    }

    public static function isActive(): bool
    {
        return self::$active;
    }

    /**
     * @return list<string>
     */
    public static function collected(): array
    {
        return self::$collected;
    }

    public static function reset(): void
    {
        self::$active = false;
        self::$collected = [];
    }

    /**
     * @internal registered via set_error_handler() — trailing params are
     * optional to match the callable(int, string, string=, int=, array=):bool
     * signature set_error_handler() expects.
     * @param array<array-key, mixed> $errcontext unused, kept for signature parity
     */
    private static function handleError(int $errno, string $errstr, string $errfile = '', int $errline = 0, array $errcontext = []): bool
    {
        // Respect the @ error-suppression operator.
        if (! (bool) (error_reporting() & $errno)) {
            return false;
        }

        $label = self::label($errno);
        $short = basename($errfile) . ':' . $errline;
        self::$collected[] = "[{$label}] {$errstr} in {$short}";

        // Keep the Apache error log as the authoritative server-side record.
        error_log("PHP {$label}: {$errstr} in {$errfile} on line {$errline}");

        return true; // Suppress PHP's built-in inline output.
    }

    /**
     * @internal registered via register_shutdown_function()
     */
    private static function flush(): void
    {
        // Catch fatal errors that set_error_handler() cannot intercept.
        $last = error_get_last();
        if ($last !== null && (bool) ($last['type'] & (E_ERROR | E_PARSE | E_CORE_ERROR | E_COMPILE_ERROR))) {
            $label = self::label($last['type']);
            $short = basename($last['file']) . ':' . $last['line'];
            self::$collected[] = "[{$label}] {$last['message']} in {$short}";
        }

        if (self::$collected === [] || headers_sent()) {
            return;
        }

        // Emit one header per error — DevTools shows each on its own line.
        foreach (self::$collected as $i => $msg) {
            // Strip newlines (invalid in header values) and cap length.
            $safe = substr(str_replace(["\r", "\n"], ' ', $msg), 0, 500);
            header('X-PHP-Error-' . ($i + 1) . ': ' . $safe);
        }
        header('X-PHP-Error-Count: ' . count(self::$collected));
    }

    private static function label(int $type): string
    {
        return match (true) {
            (bool) ($type & (E_ERROR | E_USER_ERROR | E_CORE_ERROR | E_COMPILE_ERROR)) => 'ERROR',
            (bool) ($type & (E_WARNING | E_USER_WARNING | E_CORE_WARNING | E_COMPILE_WARNING)) => 'WARNING',
            (bool) ($type & (E_DEPRECATED | E_USER_DEPRECATED)) => 'DEPRECATED',
            (bool) ($type & (E_NOTICE | E_USER_NOTICE)) => 'NOTICE',
            default => 'PHP',
        };
    }
}
