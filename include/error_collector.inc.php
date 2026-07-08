<?php

declare(strict_types=1);

/**
 * Redirects PHP errors/warnings/deprecations away from the response body and
 * into HTTP response headers instead (X-PHP-Error-N), visible in DevTools ->
 * Network -> Response Headers.
 *
 * Ported from piwigo16-rewrite's src/Piwigo/Core/ErrorCollector.php (commit
 * 6c00509cc, "route PHP errors to DevTools via X-PHP-Error-N response
 * headers") as a set of procedural functions — 17.x-rewrite has no src/
 * PSR-4 autoloading yet, so a class there isn't loadable here.
 *
 * Prevents PHP notices/warnings/deprecations from corrupting JSON, XML, or
 * binary responses (e.g. ws.php) while keeping them inspectable in the
 * browser. Errors still reach error_log() so the Apache error log remains
 * the authoritative server-side record.
 *
 * Install once, early in the bootstrap — see include/common.inc.php's
 * show_php_errors_on_frontend handling.
 */
function pwg_error_collector_install(): void
{
    global $pwg_error_collector_active, $pwg_error_collector_collected;

    if (! empty($pwg_error_collector_active)) {
        return;
    }
    $pwg_error_collector_active = true;
    $pwg_error_collector_collected = [];

    // Never write errors inline — they corrupt non-HTML responses.
    @ini_set('display_errors', '0');
    @ini_set('display_startup_errors', '0');

    set_error_handler(pwg_error_collector_handle_error(...));
    register_shutdown_function(pwg_error_collector_flush(...));
}

function pwg_error_collector_is_active(): bool
{
    global $pwg_error_collector_active;

    return ! empty($pwg_error_collector_active);
}

/**
 * @return list<string>
 */
function pwg_error_collector_collected(): array
{
    global $pwg_error_collector_collected;

    if (! isset($pwg_error_collector_collected)) {
        return [];
    }

    /** @var list<string> $pwg_error_collector_collected */
    return $pwg_error_collector_collected;
}

function pwg_error_collector_reset(): void
{
    global $pwg_error_collector_active, $pwg_error_collector_collected;

    $pwg_error_collector_active = false;
    $pwg_error_collector_collected = [];
}

/**
 * @internal registered via set_error_handler() — trailing params are
 * optional to match the callable(int, string, string=, int=, array=):bool
 * signature set_error_handler() expects.
 * @param array<array-key, mixed> $errcontext unused, kept for signature parity
 */
function pwg_error_collector_handle_error(int $errno, string $errstr, string $errfile = '', int $errline = 0, array $errcontext = []): bool
{
    /** @var list<string> $pwg_error_collector_collected */
    global $pwg_error_collector_collected;

    // Respect the @ error-suppression operator.
    if (! (error_reporting() & $errno)) {
        return false;
    }

    $label = pwg_error_collector_label($errno);
    $short = basename($errfile) . ':' . $errline;
    $pwg_error_collector_collected[] = "[{$label}] {$errstr} in {$short}";

    // Keep the Apache error log as the authoritative server-side record.
    error_log("PHP {$label}: {$errstr} in {$errfile} on line {$errline}");

    return true; // Suppress PHP's built-in inline output.
}

/**
 * @internal registered via register_shutdown_function()
 */
function pwg_error_collector_flush(): void
{
    /** @var list<string> $pwg_error_collector_collected */
    global $pwg_error_collector_collected;

    // Catch fatal errors that set_error_handler() cannot intercept.
    $last = error_get_last();
    if ($last !== null && ($last['type'] & (E_ERROR | E_PARSE | E_CORE_ERROR | E_COMPILE_ERROR))) {
        $label = pwg_error_collector_label($last['type']);
        $short = basename($last['file']) . ':' . $last['line'];
        $pwg_error_collector_collected[] = "[{$label}] {$last['message']} in {$short}";
    }

    if ($pwg_error_collector_collected === [] || headers_sent()) {
        return;
    }

    // Emit one header per error — DevTools shows each on its own line.
    foreach ($pwg_error_collector_collected as $i => $msg) {
        // Strip newlines (invalid in header values) and cap length.
        $safe = substr(str_replace(["\r", "\n"], ' ', $msg), 0, 500);
        header('X-PHP-Error-' . ($i + 1) . ': ' . $safe);
    }
    header('X-PHP-Error-Count: ' . count($pwg_error_collector_collected));
}

function pwg_error_collector_label(int $type): string
{
    return match (true) {
        (bool) ($type & (E_ERROR | E_USER_ERROR | E_CORE_ERROR | E_COMPILE_ERROR)) => 'ERROR',
        (bool) ($type & (E_WARNING | E_USER_WARNING | E_CORE_WARNING | E_COMPILE_WARNING)) => 'WARNING',
        (bool) ($type & (E_DEPRECATED | E_USER_DEPRECATED)) => 'DEPRECATED',
        (bool) ($type & (E_NOTICE | E_USER_NOTICE)) => 'NOTICE',
        default => 'PHP',
    };
}
